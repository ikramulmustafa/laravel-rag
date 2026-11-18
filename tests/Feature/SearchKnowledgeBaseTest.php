<?php

declare(strict_types=1);

use Ikram\Rag\Ai\SearchKnowledgeBase;
use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Facades\Rag;
use Ikram\Rag\Retrieval\Citation;
use Ikram\Rag\Retrieval\RetrievalResult;
use Ikram\Rag\Tests\Fixtures\KnowledgeAgent;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;

const TOOL_ERROR_JSON = '{"error":"The knowledge base search failed. Answer without it or ask the user to retry."}';

/**
 * A retriever that records every call and answers through `$respond`, or
 * through the real retriever when none is given.
 *
 * @param  (Closure(string, ?int, array<string, mixed>): RetrievalResult)|null  $respond
 */
function spyRetriever(?Closure $respond = null): object
{
    $inner = app(Retriever::class);

    return new class($respond ?? fn (string $q, ?int $l, array $f): RetrievalResult => $inner->retrieve($q, $l, $f)) implements Retriever
    {
        /** @var list<array{query: string, limit: ?int, filters: array<string, mixed>}> */
        public array $calls = [];

        public function __construct(private readonly Closure $respond) {}

        public function retrieve(string $query, ?int $limit = null, array $filters = []): RetrievalResult
        {
            $this->calls[] = ['query' => $query, 'limit' => $limit, 'filters' => $filters];

            return ($this->respond)($query, $limit, $filters);
        }
    };
}

/**
 * Ingest the fixture corpus as docs/<file>.
 *
 * @param  array<string, mixed>  $metadata
 */
function ingestToolCorpus(string $collection = 'default', array $metadata = [], string $prefix = 'docs'): void
{
    foreach (glob(__DIR__.'/../Fixtures/corpus/*.md') ?: [] as $path) {
        Rag::ingest($prefix.'/'.basename($path), (string) file_get_contents($path), $collection, $metadata);
    }
}

/** @return array<string, mixed> */
function decodeTool(string $json): array
{
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Citations with long, accented text, ranked best first.
 *
 * @return list<Citation>
 */
function longCitations(int $count, int $words): array
{
    $citations = [];

    for ($i = 0; $i < $count; $i++) {
        $text = trim(str_repeat("élégie café résumé déjà-vu n°{$i} ", $words));
        $citations[] = new Citation($i + 1, $i + 1, "docs/é{$i}.md", $text, 0, 0.9 - $i * 0.05);
    }

    return $citations;
}

it('exposes query, limit and the allowed filter keys in its schema, nothing else', function (): void {
    $tool = new SearchKnowledgeBase(spyRetriever(), allowedFilters: ['product', 'region'], limit: 4);

    $schema = $tool->schema(new JsonSchemaTypeFactory);

    expect(array_keys($schema))->toBe(['query', 'limit', 'product', 'region'])
        ->and($schema['query']->toArray())->toMatchArray(['type' => 'string', 'minLength' => 1, 'maxLength' => 1000])
        ->and($schema['limit']->toArray())->toMatchArray(['type' => 'integer', 'minimum' => 1, 'maximum' => 4])
        ->and($schema['product']->toArray())->toMatchArray(['type' => 'string']);
});

it('describes itself as cited search by default', function (): void {
    expect((new SearchKnowledgeBase(spyRetriever()))->description())
        ->toBe('Search the knowledge base for passages relevant to a question. Returns JSON with cited passages, each with an id, source and similarity score. Cite passages by their source.')
        ->and((new SearchKnowledgeBase(spyRetriever(), description: 'Search the docs.'))->description())->toBe('Search the docs.');
});

it('returns cited passages from the corpus', function (): void {
    ingestToolCorpus();

    $out = decodeTool((new SearchKnowledgeBase(spyRetriever()))->handle(new Request(['query' => 'refund window'])));

    expect($out['query'])->toBe('refund window')
        ->and($out['count'])->toBeGreaterThanOrEqual(1)
        ->and($out['count'])->toBe(count($out['citations']))
        ->and($out['truncated'])->toBeFalse()
        ->and(array_keys($out['citations'][0]))->toBe(['id', 'source', 'score', 'text'])
        ->and($out['citations'][0]['id'])->toBeInt()
        ->and($out['citations'][0]['source'])->toStartWith('docs/')
        ->and($out['citations'][0]['score'])->toBeFloat();
});

it('includes rerank_score only when the citation has one, rounded like Citation', function (): void {
    $spy = spyRetriever(fn (string $q): RetrievalResult => new RetrievalResult($q, [
        new Citation(7, 1, 'docs/a.md', 'alpha', 0, 0.812345, 0.912345),
    ], 'm'));

    $out = decodeTool((new SearchKnowledgeBase($spy))->handle(new Request(['query' => 'a'])));

    expect($out['citations'][0])->toBe(['id' => 7, 'source' => 'docs/a.md', 'score' => 0.8123, 'rerank_score' => 0.9123, 'text' => 'alpha']);
});

it('ignores a model-supplied collection and any key it was not allowed', function (): void {
    ingestToolCorpus('a', prefix: 'a');
    ingestToolCorpus('b', prefix: 'b');
    $spy = spyRetriever();

    $out = decodeTool((new SearchKnowledgeBase($spy, collection: 'a', limit: 10))
        ->handle(new Request(['query' => 'refund window', 'collection' => 'b', 'secret' => 'x'])));

    $sources = array_unique(array_column($out['citations'], 'source'));

    expect($out['count'])->toBeGreaterThan(0)
        ->and($sources)->each->toStartWith('a/')
        ->and($spy->calls[0]['filters'])->toBe(['collection' => 'a']);
});

it('applies an allowed filter the model supplied', function (): void {
    ingestToolCorpus(metadata: ['product' => 'pro'], prefix: 'pro');
    ingestToolCorpus(metadata: ['product' => 'basic'], prefix: 'basic');

    $out = decodeTool((new SearchKnowledgeBase(spyRetriever(), allowedFilters: ['product'], limit: 10))
        ->handle(new Request(['query' => 'refund window', 'product' => 'pro'])));

    expect($out['count'])->toBeGreaterThan(0)
        ->and(array_column($out['citations'], 'source'))->each->toStartWith('pro/');
});

it('lets fixed filters win over the same key from the model', function (): void {
    $spy = spyRetriever(fn (string $q): RetrievalResult => new RetrievalResult($q, [], 'm'));

    (new SearchKnowledgeBase($spy, filters: ['product' => ['basic', 'team']], allowedFilters: ['product', 'region']))
        ->handle(new Request(['query' => 'refund', 'product' => 'pro', 'region' => 'eu']));

    expect($spy->calls[0]['filters'])->toBe(['product' => ['basic', 'team'], 'region' => 'eu']);
});

it('uses the configured limit unless the model asks for fewer', function (): void {
    $spy = spyRetriever(fn (string $q): RetrievalResult => new RetrievalResult($q, [], 'm'));
    $tool = new SearchKnowledgeBase($spy, limit: 4);

    $tool->handle(new Request(['query' => 'refund']));
    $tool->handle(new Request(['query' => 'refund', 'limit' => 2]));

    expect(array_column($spy->calls, 'limit'))->toBe([4, 2]);
});

it('rejects a limit above the configured maximum', function (): void {
    $spy = spyRetriever();

    expect(caught(fn () => (new SearchKnowledgeBase($spy, limit: 3))->handle(new Request(['query' => 'refund', 'limit' => 4]))))
        ->toBeInstanceOf(ValidationException::class)
        ->and($spy->calls)->toBe([]);
});

it('rejects a blank query without searching', function (string $query): void {
    $spy = spyRetriever();

    expect(caught(fn () => (new SearchKnowledgeBase($spy))->handle(new Request(['query' => $query]))))
        ->toBeInstanceOf(ValidationException::class)
        ->and($spy->calls)->toBe([]);
})->with(['spaces' => '   ', 'empty' => '', 'tabs and newlines' => "\t\n "]);

it('rejects a missing or overlong query', function (array $arguments): void {
    expect(caught(fn () => (new SearchKnowledgeBase(spyRetriever()))->handle(new Request($arguments))))
        ->toBeInstanceOf(ValidationException::class);
})->with([
    'missing' => [[]],
    'not a string' => [['query' => ['refund']]],
    'too long' => [['query' => str_repeat('é', 1001)]],
]);

it('says so when nothing matched', function (): void {
    $json = (new SearchKnowledgeBase(spyRetriever()))->handle(new Request(['query' => 'refund window']));

    expect($json)->toBe('{"query":"refund window","count":0,"citations":[],"note":"No relevant passages found."}');
});

it('reports a retrieval failure and gives the model only a safe message', function (): void {
    Exceptions::fake();
    $spy = spyRetriever(fn () => throw new RuntimeException('secret DSN postgres://u:p@h'));

    $json = (new SearchKnowledgeBase($spy))->handle(new Request(['query' => 'refund window']));

    expect($json)->toBe(TOOL_ERROR_JSON)
        ->and($json)->not->toContain('secret');
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'secret DSN postgres://u:p@h');
});

it('keeps multibyte output inside the budget as valid JSON', function (): void {
    $spy = spyRetriever(fn (string $q): RetrievalResult => new RetrievalResult($q, longCitations(10, 40), 'm'));

    $json = (new SearchKnowledgeBase($spy, limit: 10, maxChars: 600))->handle(new Request(['query' => 'café résumé']));
    $out = decodeTool($json);

    expect(mb_strlen($json))->toBeLessThanOrEqual(600)
        ->and(mb_check_encoding($json, 'UTF-8'))->toBeTrue()
        ->and($out['truncated'])->toBeTrue()
        ->and($out['count'])->toBe(1)
        ->and($out['citations'][0]['id'])->toBe(1)
        ->and($out['citations'][0]['text'])->toEndWith('…')
        ->and($out['citations'][0]['text'])->not->toEndWith(' …');

    // Trimmed at a word boundary: what is kept, minus the ellipsis, is a prefix ending on a whole word.
    $kept = mb_substr($out['citations'][0]['text'], 0, -1);
    expect(longCitations(1, 40)[0]->text)->toStartWith($kept.' ');
});

it('stays inside every budget, counting JSON escaping', function (string $text): void {
    $citations = array_map(
        fn (int $i): Citation => new Citation($i, $i, "docs/{$i}.md", $text, 0, 0.9),
        range(1, 6),
    );
    $spy = spyRetriever(fn (string $q): RetrievalResult => new RetrievalResult($q, $citations, 'm'));

    foreach (range(500, 1500, 13) as $budget) {
        $json = (new SearchKnowledgeBase($spy, limit: 6, maxChars: $budget))->handle(new Request(['query' => 'é "q"']));

        expect(mb_strlen($json))->toBeLessThanOrEqual($budget)
            ->and(decodeTool($json)['truncated'])->toBeTrue();
    }
})->with([
    'accents' => [str_repeat('déjà vu à l’élysée ', 60)],
    'quotes, slashes and newlines' => [str_repeat("say \"hi\"\\ then\n\ttab ", 60)],
    'one long word' => [str_repeat('é', 1200)],
]);

it('drops the lowest-ranked citations first and keeps the rest whole', function (): void {
    $spy = spyRetriever(fn (string $q): RetrievalResult => new RetrievalResult($q, longCitations(10, 3), 'm'));

    $json = (new SearchKnowledgeBase($spy, limit: 10, maxChars: 700))->handle(new Request(['query' => 'café']));
    $out = decodeTool($json);

    expect(mb_strlen($json))->toBeLessThanOrEqual(700)
        ->and($out['truncated'])->toBeTrue()
        ->and($out['count'])->toBeGreaterThan(1)->toBeLessThan(10)
        ->and($out['count'])->toBe(count($out['citations']))
        ->and(array_column($out['citations'], 'id'))->toBe(range(1, $out['count']))
        ->and(array_column($out['citations'], 'text'))->each->not->toEndWith('…');
});

it('leaves output that fits untouched', function (): void {
    $spy = spyRetriever(fn (string $q): RetrievalResult => new RetrievalResult($q, longCitations(3, 3), 'm'));

    $out = decodeTool((new SearchKnowledgeBase($spy, maxChars: 8000))->handle(new Request(['query' => 'café'])));

    expect($out['truncated'])->toBeFalse()
        ->and($out['count'])->toBe(3);
});

it('refuses settings that would make an unsafe or broken tool', function (Closure $build): void {
    expect(caught($build))->toBeInstanceOf(InvalidArgumentException::class);
})->with([
    'a filter key that is not an identifier' => [fn () => new SearchKnowledgeBase(spyRetriever(), allowedFilters: ['meta->x'])],
    'the collection, when it is fixed' => [fn () => new SearchKnowledgeBase(spyRetriever(), collection: 'docs', allowedFilters: ['collection'])],
    'a filter key that clashes with query' => [fn () => new SearchKnowledgeBase(spyRetriever(), allowedFilters: ['query'])],
    'a limit below one' => [fn () => new SearchKnowledgeBase(spyRetriever(), limit: 0)],
    'a budget below 500' => [fn () => new SearchKnowledgeBase(spyRetriever(), maxChars: 499)],
]);

it('lets the model choose the collection when none is fixed', function (): void {
    $spy = spyRetriever(fn (string $q): RetrievalResult => new RetrievalResult($q, [], 'm'));

    (new SearchKnowledgeBase($spy, allowedFilters: ['collection']))->handle(new Request(['query' => 'refund', 'collection' => 'b']));

    expect($spy->calls[0]['filters'])->toBe(['collection' => 'b']);
});

it('is built by Rag::tool() with the configured defaults', function (): void {
    config()->set('rag.retrieval.default_limit', 3);

    $tool = Rag::tool();

    expect($tool)->toBeInstanceOf(SearchKnowledgeBase::class)
        ->and($tool->name())->toBe('search_knowledge_base')
        ->and($tool->schema(new JsonSchemaTypeFactory)['limit']->toArray()['maximum'])->toBe(3)
        ->and(Rag::tool(name: 'docs_search')->name())->toBe('docs_search');
});

it('runs inside an SDK agent', function (): void {
    ingestToolCorpus();
    $spy = spyRetriever();
    app()->instance(Retriever::class, $spy);

    KnowledgeAgent::fake([
        new ToolCall('c1', 'search_knowledge_base', ['query' => 'refund window']),
        'Refunds are allowed within 30 days.',
    ]);

    $response = (new KnowledgeAgent)->prompt('How long for refunds?');

    $result = decodeTool((string) $response->toolResults->first()->result);

    expect($response->text)->toBe('Refunds are allowed within 30 days.')
        ->and(array_column($spy->calls, 'query'))->toBe(['refund window'])
        ->and($result['count'])->toBeGreaterThan(0)
        ->and($result['citations'][0]['source'])->toStartWith('docs/');
});
