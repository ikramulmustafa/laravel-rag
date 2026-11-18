<?php

declare(strict_types=1);

namespace Ikram\Rag\Ai;

use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Retrieval\Citation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Retrieval as a tool an SDK agent can call, answering with cited passages.
 *
 * The model is an untrusted caller. It only ever sees and fills `query`, `limit`
 * and the metadata keys the developer allowed; anything else it sends is ignored
 * in code, because not every provider enforces `additionalProperties: false`.
 * The fixed collection and filters always win over what the model asked for.
 *
 * The SDK forwards a failing tool's exception text to the provider, so a failure
 * is reported to the application and the model gets a fixed sentence instead:
 * a DSN or an API error body must never end up in someone else's prompt.
 */
final class SearchKnowledgeBase implements Tool
{
    private const DEFAULT_DESCRIPTION = 'Search the knowledge base for passages relevant to a question. '
        .'Returns JSON with cited passages, each with an id, source and similarity score. Cite passages by their source.';

    private const ERROR = '{"error":"The knowledge base search failed. Answer without it or ask the user to retry."}';

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    private const MAX_QUERY_LENGTH = 1000;

    /** Property names the schema already uses, so a filter key cannot replace them. */
    private const RESERVED_KEYS = ['query', 'limit'];

    /** @var list<string> */
    private readonly array $allowedFilters;

    /**
     * @param  array<string, mixed>  $filters  Always applied; they override a model-supplied value for the same key
     * @param  list<string>  $allowedFilters  Metadata keys the model may filter on, each with a string value
     * @param  int  $limit  The default number of passages, and the most the model may ask for
     * @param  int  $maxChars  Output budget in characters; lowest-ranked passages go first when it is exceeded
     */
    public function __construct(
        private readonly Retriever $retriever,
        private readonly ?string $collection = null,
        private readonly array $filters = [],
        array $allowedFilters = [],
        private readonly int $limit = 5,
        private readonly string $name = 'search_knowledge_base',
        private readonly ?string $description = null,
        private readonly int $maxChars = 8000,
    ) {
        foreach ($allowedFilters as $key) {
            if (! is_string($key) || preg_match('/^[A-Za-z0-9_]+$/', $key) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Allowed filter key [%s] is not a plain identifier (letters, digits, underscores).',
                    is_scalar($key) ? (string) $key : get_debug_type($key),
                ));
            }

            if (in_array($key, self::RESERVED_KEYS, true)) {
                throw new InvalidArgumentException("Allowed filter key [{$key}] clashes with the tool's own [{$key}] argument.");
            }
        }

        if ($collection !== null && in_array('collection', $allowedFilters, true)) {
            throw new InvalidArgumentException('The collection is fixed, so the model cannot be allowed to filter on it.');
        }

        if ($limit < 1) {
            throw new InvalidArgumentException("The tool limit must be at least 1, {$limit} given.");
        }

        if ($maxChars < 500) {
            throw new InvalidArgumentException("The tool output budget must be at least 500 characters, {$maxChars} given.");
        }

        $this->allowedFilters = array_values(array_unique($allowedFilters));
    }

    /** The SDK matches tool calls on this; without it the name would be the class basename. */
    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description ?? self::DEFAULT_DESCRIPTION;
    }

    public function schema(JsonSchema $schema): array
    {
        $properties = [
            'query' => $schema->string()
                ->description('What to search for, in natural language.')
                ->min(1)
                ->max(self::MAX_QUERY_LENGTH)
                ->required(),
            'limit' => $schema->integer()
                ->description("How many passages to return, at most {$this->limit}.")
                ->min(1)
                ->max($this->limit),
        ];

        foreach ($this->allowedFilters as $key) {
            $properties[$key] = $schema->string()
                ->description("Only return passages whose {$key} metadata equals this value.");
        }

        return $properties;
    }

    /**
     * @throws ValidationException When the arguments are invalid; the SDK hands the messages back to the model
     */
    public function handle(Request $request): string
    {
        try {
            [$query, $limit, $filters] = $this->arguments($request);

            $result = $this->retriever->retrieve($query, $limit, $filters);

            return $result->isEmpty()
                ? $this->encode(['query' => $query, 'count' => 0, 'citations' => [], 'note' => 'No relevant passages found.'])
                : $this->withinBudget($query, $result->citations);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return self::ERROR;
        }
    }

    /**
     * Read only what the schema offers. Anything else in the request, including a
     * `collection` the developer did not allow, never reaches the retriever.
     *
     * @return array{0: string, 1: int, 2: array<string, mixed>}
     *
     * @throws ValidationException
     */
    private function arguments(Request $request): array
    {
        $rules = [
            'query' => 'required|string|max:'.self::MAX_QUERY_LENGTH,
            'limit' => "nullable|integer|min:1|max:{$this->limit}",
        ];

        foreach ($this->allowedFilters as $key) {
            $rules[$key] = 'nullable|string';
        }

        $valid = $request->validate($rules);

        if (trim($valid['query']) === '') {
            throw ValidationException::withMessages(['query' => 'The query must not be blank.']);
        }

        $filters = [];

        foreach ($this->allowedFilters as $key) {
            if (isset($valid[$key]) && $valid[$key] !== '') {
                $filters[$key] = $valid[$key];
            }
        }

        $filters = array_merge($filters, $this->filters);

        if ($this->collection !== null) {
            $filters['collection'] = $this->collection;
        }

        return [$valid['query'], isset($valid['limit']) ? (int) $valid['limit'] : $this->limit, $filters];
    }

    /**
     * Fit the answer into `maxChars`, measured on the encoded JSON so escaping is
     * counted. Lowest-ranked citations go first; if the best one alone is still too
     * long, its text is cut back at a word boundary. A model given a cut-down answer
     * is told so through `truncated`.
     *
     * @param  list<Citation>  $citations
     */
    private function withinBudget(string $query, array $citations): string
    {
        $rows = array_map($this->row(...), $citations);
        $truncated = false;
        $json = $this->success($query, $rows, $truncated);

        while (mb_strlen($json) > $this->maxChars && count($rows) > 1) {
            array_pop($rows);
            $truncated = true;
            $json = $this->success($query, $rows, $truncated);
        }

        $last = count($rows) - 1;
        $kept = $rows[$last]['text'];

        while (($over = mb_strlen($json) - $this->maxChars) > 0 && $kept !== '') {
            // One extra character for the ellipsis; escaping can make the text
            // longer in JSON than in PHP, which the next pass picks up.
            $kept = mb_substr($kept, 0, max(0, mb_strlen($kept) - $over - 1));
            $space = mb_strrpos($kept, ' ');

            if ($space !== false) {
                $kept = mb_substr($kept, 0, $space);
            }

            $kept = rtrim($kept);
            $rows[$last]['text'] = $kept.'…';
            $truncated = true;
            $json = $this->success($query, $rows, $truncated);
        }

        return $json;
    }

    /** @return array{id: int, source: string, score: float, rerank_score?: float, text: string} */
    private function row(Citation $citation): array
    {
        $row = [
            'id' => $citation->chunkId,
            'source' => $citation->source,
            'score' => round($citation->score, 4),
        ];

        if ($citation->rerankScore !== null) {
            $row['rerank_score'] = round($citation->rerankScore, 4);
        }

        $row['text'] = $citation->text;

        return $row;
    }

    /** @param list<array<string, mixed>> $rows */
    private function success(string $query, array $rows, bool $truncated): string
    {
        return $this->encode(['query' => $query, 'count' => count($rows), 'citations' => $rows, 'truncated' => $truncated]);
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        return json_encode($payload, self::JSON_FLAGS);
    }
}
