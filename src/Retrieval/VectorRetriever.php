<?php

declare(strict_types=1);

namespace Ikram\Rag\Retrieval;

use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Embedding\EmbeddingModelId;
use Ikram\Rag\Exceptions\DimensionMismatch;
use Ikram\Rag\Exceptions\MixedEmbeddingModels;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final class VectorRetriever implements Retriever
{
    /** How many offending model ids a mixed-model error names. */
    private const OFFENDING_IDS_SHOWN = 5;

    public function __construct(
        private readonly Embedder $embedder,
        private readonly Connection $connection,
        private readonly float $minScore = 0.0,
        private readonly int $defaultLimit = 5,
    ) {}

    public function retrieve(string $query, ?int $limit = null, array $filters = []): RetrievalResult
    {
        // Nothing to search for. Embedding whitespace costs a request and returns a
        // vector that is close to nothing in particular, so answer empty instead.
        if (trim($query) === '') {
            return new RetrievalResult(
                query: $query,
                citations: [],
                embeddingModel: $this->embedder->modelId(),
            );
        }

        $limit ??= $this->defaultLimit;

        $this->guardAgainstMixedModels($filters);

        $vector = $this->embedder->embed([$query])[0] ?? [];
        $this->guardQueryWidth($vector);

        $rows = $this->connection->getDriverName() === 'pgsql'
            ? $this->searchWithPgvector($vector, $limit, $filters)
            : $this->searchInPhp($vector, $limit, $filters);

        $citations = [];

        foreach ($rows as $row) {
            if ($row->score < $this->minScore) {
                continue;
            }

            $citations[] = new Citation(
                chunkId: (int) $row->id,
                documentId: (int) $row->document_id,
                source: (string) $row->source,
                text: (string) $row->text,
                position: (int) $row->position,
                score: (float) $row->score,
            );
        }

        return new RetrievalResult(
            query: $query,
            citations: $citations,
            embeddingModel: $this->embedder->modelId(),
        );
    }

    /**
     * A corpus embedded with more than one model is not searchable. Cosine distance
     * between vectors from different models is a real number with no meaning, so the
     * query returns results, they rank plausibly, and they are wrong.
     *
     * This is the failure this package exists to prevent, so it throws rather than warns.
     *
     * Only the rows this search could return are checked: another collection on an
     * old model does not stop this one being searched. The check is one existence
     * query and runs on every search. It is not cached, because the retriever is a
     * singleton that lives as long as an Octane or queue worker, and a remembered
     * "fine" would hide a corpus that became mixed after it was taken.
     *
     * @param  array<string, mixed>  $filters
     */
    private function guardAgainstMixedModels(array $filters): void
    {
        $current = $this->embedder->modelId();
        $accepted = array_values(array_filter([$current, EmbeddingModelId::legacyForm($current)]));

        $offending = $this->scopedChunks($filters)->whereNotIn('c.embedding_model', $accepted);

        if (! $offending->exists()) {
            return;
        }

        // The failure path only: name what was found, so the message says which
        // models to reindex away from. One extra id is fetched to know whether the
        // list was cut short.
        $others = $offending->clone()
            ->distinct()
            ->orderBy('c.embedding_model')
            ->limit(self::OFFENDING_IDS_SHOWN + 1)
            ->pluck('c.embedding_model')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $hasCurrent = $this->scopedChunks($filters)->whereIn('c.embedding_model', $accepted)->exists();

        if (count($others) === 1 && ! $hasCurrent) {
            throw new MixedEmbeddingModels(sprintf(
                'The searched corpus was embedded with "%s" but the configured embedder is "%s". '
                .'Searching across models returns confident nonsense. Either restore the '
                .'previous model in config/rag.php, or run `php artisan rag:reindex`.',
                $others[0],
                $current,
            ));
        }

        $shown = array_slice($others, 0, self::OFFENDING_IDS_SHOWN);

        if ($hasCurrent) {
            array_unshift($shown, $current);
        }

        throw new MixedEmbeddingModels(sprintf(
            'The searched corpus contains vectors from different embedding models (%s%s). '
            .'Distances between them are meaningless. Re-run `php artisan rag:reindex` '
            .'to rebuild the corpus with a single model.',
            implode(', ', $shown),
            count($others) > self::OFFENDING_IDS_SHOWN ? ', and more' : '',
        ));
    }

    /**
     * The query vector must have the width the embedder declares. Anything else means
     * the provider ignored or misread the configured dimensions, and every distance
     * computed from it would be meaningless.
     *
     * @param  array<int, float>  $vector
     */
    private function guardQueryWidth(array $vector): void
    {
        $expected = $this->embedder->dimensions();

        if (count($vector) !== $expected) {
            throw new DimensionMismatch(sprintf(
                'The query was embedded with %d dimensions but the embedder is configured for %d. '
                .'Check rag.embedding.dimensions; if it changed, run `php artisan rag:reindex`.',
                count($vector),
                $expected,
            ));
        }
    }

    /**
     * Chunks joined to their documents, narrowed to what a search with these filters
     * could return. Shared by the search and the mixed-model guard so the two can
     * never disagree about scope.
     *
     * @param  array<string, mixed>  $filters
     */
    private function scopedChunks(array $filters): Builder
    {
        $query = $this->connection->table('rag_chunks as c')
            ->join('rag_documents as d', 'd.id', '=', 'c.document_id');

        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * @param  list<float>  $vector
     * @param  array<string, mixed>  $filters
     * @return list<object>
     */
    private function searchWithPgvector(array $vector, int $limit, array $filters): array
    {
        $literal = Vector::toLiteral($vector);

        // A NULL embedding is a row a resize has cleared and not yet re-embedded. It
        // is left out, so a half-finished reindex returns fewer results, not errors.
        $query = $this->scopedChunks($filters)
            ->whereNotNull('c.embedding')
            ->selectRaw(
                'c.id, c.document_id, c.text, c.position, d.source, '
                .'1 - (c.embedding <=> ?::vector) as score',
                [$literal],
            )
            ->orderByRaw('c.embedding <=> ?::vector', [$literal])
            ->limit($limit);

        return $query->get()->all();
    }

    /**
     * Portable fallback for SQLite and MySQL. Loads candidates into memory, so it is
     * only appropriate for test suites and small corpora. Production wants Postgres
     * with pgvector; the README says so and this comment is the second warning.
     *
     * @param  list<float>  $vector
     * @param  array<string, mixed>  $filters
     * @return list<object>
     */
    private function searchInPhp(array $vector, int $limit, array $filters): array
    {
        $query = $this->scopedChunks($filters)
            ->select('c.id', 'c.document_id', 'c.text', 'c.position', 'c.embedding', 'd.source');

        $scored = [];

        foreach ($query->get() as $row) {
            $stored = json_decode((string) $row->embedding, true);

            if (! is_array($stored)) {
                continue;
            }

            // pgvector refuses a width mismatch in SQL; JSON storage would not, and
            // a vector of another width scored here is a number with no meaning.
            if (count($stored) !== count($vector)) {
                throw new DimensionMismatch(sprintf(
                    'Chunk %d holds a vector of %d dimensions but the query has %d. The corpus was '
                    .'embedded at another width; run `php artisan rag:reindex` to rebuild it.',
                    (int) $row->id,
                    count($stored),
                    count($vector),
                ));
            }

            $row->score = self::cosine($vector, array_map(floatval(...), $stored));
            unset($row->embedding);
            $scored[] = $row;
        }

        usort($scored, static fn (object $a, object $b): int => $b->score <=> $a->score);

        return array_slice($scored, 0, $limit);
    }

    /**
     * `collection` lives on the document; anything else is looked up in the chunk's
     * metadata JSON.
     *
     * The metadata path is written as `c.metadata->key` and left to the query grammar
     * to translate — `->>` spelled out by hand only works on Postgres, and on SQLite
     * it is parsed as part of the column name, matching nothing and silently returning
     * an empty result rather than erroring.
     *
     * Keys become part of that path, so only plain identifiers are accepted. A key
     * such as `a->b` would otherwise reach into nested JSON the caller never meant
     * to expose to filtering.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        foreach ($filters as $column => $value) {
            if (preg_match('/^[A-Za-z0-9_]+$/', (string) $column) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Filter key [%s] is not allowed. Filter keys must be plain identifiers '
                    .'(letters, digits and underscores).',
                    $column,
                ));
            }

            $target = $column === 'collection' ? 'd.collection' : "c.metadata->{$column}";

            is_array($value)
                ? $query->whereIn($target, $value)
                : $query->where($target, $value);
        }
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        foreach ($a as $i => $value) {
            $other = $b[$i] ?? 0.0;
            $dot += $value * $other;
            $magA += $value ** 2;
            $magB += $other ** 2;
        }

        $denominator = sqrt($magA) * sqrt($magB);

        return $denominator === 0.0 ? 0.0 : $dot / $denominator;
    }
}
