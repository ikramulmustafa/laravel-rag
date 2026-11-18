<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Facades\Rag;
use Ikram\Rag\Retrieval\Citation;
use Ikram\Rag\Retrieval\RerankingRetriever;
use Ikram\Rag\Retrieval\RetrievalResult;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;
use Psr\Log\LoggerInterface;

/**
 * An inner retriever that records the limit it was asked for.
 *
 * @param  list<string>  $texts
 */
function stubRetriever(array $texts): object
{
    return new class($texts) implements Retriever
    {
        public ?int $askedLimit = null;

        /** @param list<string> $texts */
        public function __construct(private readonly array $texts) {}

        public function retrieve(string $query, ?int $limit = null, array $filters = []): RetrievalResult
        {
            $this->askedLimit = $limit;
            $citations = [];
            foreach ($this->texts as $i => $text) {
                $citations[] = new Citation($i + 1, $i + 1, "doc{$i}.md", $text, 0, 0.9 - $i * 0.1);
            }

            return new RetrievalResult($query, array_slice($citations, 0, $limit), 'm');
        }
    };
}

function rerankingOver(Retriever $inner, ?LoggerInterface $logger = null, int $candidates = 3, int $limit = 2): RerankingRetriever
{
    return new RerankingRetriever($inner, null, null, $candidates, $limit, $logger ?? Log::channel('null'));
}

/**
 * @return list<string>
 */
function textsOf(RetrievalResult $result): array
{
    return array_map(fn (Citation $c): string => $c->text, $result->citations);
}

it('orders citations by the ranked indices and records rerank_score', function (): void {
    Reranking::fake([[
        new RankedDocument(2, 'gamma', 0.95),
        new RankedDocument(0, 'alpha', 0.40),
    ]]);

    $inner = stubRetriever(['alpha', 'beta', 'gamma', 'delta']);
    $result = rerankingOver($inner)->retrieve('q');

    expect($inner->askedLimit)->toBe(6)
        ->and(textsOf($result))->toBe(['gamma', 'alpha'])
        ->and($result->citations[0]->rerankScore)->toBe(0.95)
        ->and($result->citations[0]->score)->toBe(0.7)
        ->and(json_decode(json_encode($result), true)['citations'][0]['rerank_score'])->toBe(0.95);

    Reranking::assertReranked(fn (RerankingPrompt $p) => $p->limit === 2 && $p->documents === ['alpha', 'beta', 'gamma', 'delta']);
});

it('is not used unless enabled', function (): void {
    Reranking::fake();
    fakeSdkEmbeddings();

    expect(app(Retriever::class))->not->toBeInstanceOf(RerankingRetriever::class);
    app(Retriever::class)->retrieve('anything');

    Reranking::assertNothingReranked();
});

it('wraps the retriever when enabled', function (): void {
    config()->set('rag.retrieval.rerank.enabled', true);
    app()->forgetInstance(Retriever::class);

    expect(app(Retriever::class))->toBeInstanceOf(RerankingRetriever::class);
});

it('refuses a failover list as the rerank provider', function (): void {
    config()->set('rag.retrieval.rerank.enabled', true);
    config()->set('rag.retrieval.rerank.provider', ['cohere', 'jina']);
    app()->forgetInstance(Retriever::class);

    $e = caught(fn () => app(Retriever::class));

    expect($e)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($e->getMessage())->toContain('failover');
});

it('refuses fewer than one candidate per result', function (): void {
    expect(caught(fn () => new RerankingRetriever(stubRetriever([]), null, null, 0, 5, Log::channel('null'))))
        ->toBeInstanceOf(InvalidArgumentException::class);
});

it('falls back to vector order and logs a warning when the reranker fails', function (): void {
    Reranking::fake([fn () => throw new RuntimeException('secret-body: api key sk-123')]);

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once()->withArgs(function (string $message, array $context = []): bool {
        $flat = $message.json_encode($context);

        return str_contains($flat, 'RuntimeException') && ! str_contains($flat, 'sk-123');
    });

    $result = rerankingOver(stubRetriever(['alpha', 'beta', 'gamma', 'delta']), $logger)->retrieve('q');

    expect(textsOf($result))->toBe(['alpha', 'beta'])
        ->and($result->citations[0]->rerankScore)->toBeNull();
});

it('survives out-of-range indices and never exceeds the limit', function (): void {
    Reranking::fake([[
        new RankedDocument(99, 'ghost', 0.99),
        new RankedDocument(1, 'beta', 0.5),
        new RankedDocument(0, 'alpha', 0.4),
        new RankedDocument(2, 'gamma', 0.3),
    ]]);

    $result = rerankingOver(stubRetriever(['alpha', 'beta', 'gamma']))->retrieve('q');

    expect(textsOf($result))->toBe(['beta', 'alpha']);
});

it('falls back when every ranked index is out of range', function (): void {
    Reranking::fake([[new RankedDocument(99, 'ghost', 0.99)]]);

    $result = rerankingOver(stubRetriever(['alpha', 'beta', 'gamma']))->retrieve('q');

    expect(textsOf($result))->toBe(['alpha', 'beta']);
});

it('skips the reranker for a single candidate', function (): void {
    Reranking::fake();

    $result = rerankingOver(stubRetriever(['alpha']))->retrieve('q');

    expect($result->count())->toBe(1);
    Reranking::assertNothingReranked();
});

it('reranks only citations with text', function (): void {
    Reranking::fake([[new RankedDocument(1, 'gamma', 0.8), new RankedDocument(0, 'alpha', 0.2)]]);

    $result = rerankingOver(stubRetriever(['alpha', '  ', 'gamma']))->retrieve('q');

    expect(textsOf($result))->toBe(['gamma', 'alpha']);
    Reranking::assertReranked(fn (RerankingPrompt $p) => $p->documents === ['alpha', 'gamma']);
});

it('keeps vector order when fewer than two citations have text', function (): void {
    Reranking::fake();

    $result = rerankingOver(stubRetriever(['alpha', '', ' ']))->retrieve('q');

    expect(textsOf($result))->toBe(['alpha', '']);
    Reranking::assertNothingReranked();
});

it('uses each ranked index once', function (): void {
    Reranking::fake([[
        new RankedDocument(1, 'beta', 0.9),
        new RankedDocument(1, 'beta', 0.8),
        new RankedDocument(0, 'alpha', 0.4),
    ]]);

    $result = rerankingOver(stubRetriever(['alpha', 'beta', 'gamma']))->retrieve('q');

    expect(textsOf($result))->toBe(['beta', 'alpha']);
});

it('rejects a candidates multiplier outside 1 to 10', function (): void {
    $e = caught(fn () => new RerankingRetriever(stubRetriever([]), null, null, 11, 5, Log::channel('null')));

    expect($e)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($e->getMessage())->toContain('between 1 and 10');
});

it('caps the over-fetch at the pool maximum', function (): void {
    Reranking::fake();

    $inner = stubRetriever(['alpha']);
    rerankingOver($inner, null, 3, 50)->retrieve('q');

    expect($inner->askedLimit)->toBe(100);
});

it('does not pad with unranked candidates when the reranker returns fewer than the limit', function (): void {
    Reranking::fake([[new RankedDocument(3, 'delta', 0.7), new RankedDocument(1, 'beta', 0.6)]]);

    $result = rerankingOver(stubRetriever(['alpha', 'beta', 'gamma', 'delta']), null, 4, 3)->retrieve('q');

    expect(textsOf($result))->toBe(['delta', 'beta']);
});

it('words the provider error by what is wrong with it', function (): void {
    config()->set('rag.retrieval.rerank.enabled', true);

    config()->set('rag.retrieval.rerank.provider', 5);
    app()->forgetInstance(Retriever::class);
    expect(caught(fn () => app(Retriever::class))->getMessage())->toContain('provider name string')
        ->and(caught(fn () => app(Retriever::class))->getMessage())->not->toContain('failover');

    config()->set('rag.retrieval.rerank.provider', ['a', 'b']);
    app()->forgetInstance(Retriever::class);
    expect(caught(fn () => app(Retriever::class))->getMessage())->toContain('failover');
});

/** Turn reranking on and ingest enough for a real pool, then rebuild the retriever. */
function enableRerankingOverCorpus(array $rerank): void
{
    fakeSdkEmbeddings();
    config()->set('rag.retrieval.rerank', ['enabled' => true, ...$rerank]);
    app()->forgetInstance(Retriever::class);

    Rag::ingest('policies/refunds.md', 'Customers may request a refund within 30 days of delivery.');
    Rag::ingest('policies/shipping.md', 'Standard shipping takes 3 to 5 working days.');
    Rag::ingest('handbook/leave.md', 'Annual leave accrues monthly.');
}

it('treats a blank rerank provider and model as unset', function (): void {
    // ai.default is openai, which cannot rerank; ai.default_for_reranking is cohere.
    config()->set('ai.default', 'openai');
    config()->set('ai.default_for_reranking', 'cohere');
    Reranking::fake([[new RankedDocument(1, 'b', 0.9), new RankedDocument(0, 'a', 0.5)]]);
    enableRerankingOverCorpus(['provider' => '', 'model' => '']);

    $result = app(Retriever::class)->retrieve('refund within 30 days', 2);

    expect($result->citations[0]->rerankScore)->toBe(0.9);
    Reranking::assertReranked(fn (RerankingPrompt $p): bool => $p->provider->name() === 'cohere' && $p->model !== '');
});

it('resolves a null rerank provider to ai.default_for_reranking, Lab enum included', function (): void {
    config()->set('ai.default_for_reranking', Lab::Jina);
    Reranking::fake([[new RankedDocument(0, 'a', 0.9), new RankedDocument(1, 'b', 0.5)]]);
    enableRerankingOverCorpus(['provider' => null]);

    app(Retriever::class)->retrieve('refund within 30 days', 2);

    Reranking::assertReranked(fn (RerankingPrompt $p): bool => $p->provider->name() === 'jina');
});

it('refuses a failover list in ai.default_for_reranking when the rerank provider is unset', function (?string $provider): void {
    config()->set('ai.default_for_reranking', ['cohere', 'jina']);
    config()->set('rag.retrieval.rerank.enabled', true);
    config()->set('rag.retrieval.rerank.provider', $provider);
    app()->forgetInstance(Retriever::class);

    $e = caught(fn () => app(Retriever::class));

    expect($e)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($e->getMessage())->toContain('ai.default_for_reranking')
        ->and($e->getMessage())->toContain('failover');
})->with([null, '']);

it('passes the configured rerank timeout to the SDK, 10 seconds by default', function (?int $timeout, int $expected): void {
    Reranking::fake([[new RankedDocument(0, 'a', 0.9), new RankedDocument(1, 'b', 0.5)]]);
    enableRerankingOverCorpus($timeout === null ? [] : ['timeout' => $timeout]);

    app(Retriever::class)->retrieve('refund within 30 days', 2);

    Reranking::assertReranked(fn (RerankingPrompt $p): bool => $p->timeout === $expected);
})->with([[null, 10], [4, 4]]);

it('refuses a rerank timeout below one second', function (): void {
    expect(caught(fn () => new RerankingRetriever(stubRetriever([]), null, null, 3, 5, Log::channel('null'), timeout: 0)))
        ->toBeInstanceOf(InvalidArgumentException::class);
});
