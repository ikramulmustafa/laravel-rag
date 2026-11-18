<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Embedding\FakeEmbedder;
use Ikram\Rag\Exceptions\DimensionMismatch;
use Ikram\Rag\Exceptions\MixedEmbeddingModels;
use Ikram\Rag\Facades\Rag;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $ingestor = app(Ingestor::class);

    $ingestor->ingest('policies/refunds.md', 'Customers may request a refund within 30 days of delivery for damaged goods.');
    $ingestor->ingest('policies/shipping.md', 'Standard shipping takes 3 to 5 working days across the mainland.');
    $ingestor->ingest('handbook/leave.md', 'Annual leave accrues monthly and carries over to the following year.');
});

it('ranks the relevant document first', function (): void {
    $result = app(Retriever::class)->retrieve('refund for damaged goods', limit: 3);

    expect($result->isEmpty())->toBeFalse()
        ->and($result->citations[0]->source)->toBe('policies/refunds.md');
});

it('always returns citations alongside text', function (): void {
    $result = app(Retriever::class)->retrieve('shipping time', limit: 2);

    foreach ($result->citations as $citation) {
        expect($citation->source)->not->toBeEmpty()
            ->and($citation->documentId)->toBeGreaterThan(0);
    }
});

it('respects the limit', function (): void {
    expect(app(Retriever::class)->retrieve('policy', limit: 2)->count())
        ->toBeLessThanOrEqual(2);
});

it('records which model was used for the search', function (): void {
    expect(app(Retriever::class)->retrieve('leave')->embeddingModel)
        ->toBe('fake:deterministic-hash:64');
});

it('filters by collection', function (): void {
    app(Ingestor::class)->ingest('other/thing.md', 'refund related text in another collection', 'other');

    $result = app(Retriever::class)->retrieve('refund', limit: 10, filters: ['collection' => 'other']);

    expect($result->sources())->toBe(['other/thing.md']);
});

it('refuses to search a corpus containing more than one embedding model', function (): void {
    DB::table('rag_chunks')->limit(1)->update(['embedding_model' => 'openai:text-embedding-3-small']);

    expect(fn () => app(Retriever::class)->retrieve('anything'))
        ->toThrow(MixedEmbeddingModels::class, 'different embedding models');
});

it('refuses when the corpus model differs from the configured one', function (): void {
    DB::table('rag_chunks')->update(['embedding_model' => 'openai:text-embedding-3-large']);

    expect(fn () => app(Retriever::class)->retrieve('anything'))
        ->toThrow(MixedEmbeddingModels::class, 'rag:reindex');
});

it('searches an empty corpus without throwing', function (): void {
    DB::table('rag_chunks')->delete();

    expect(app(Retriever::class)->retrieve('anything')->isEmpty())->toBeTrue();
});

// Asserts the marker format, not the ranking. Which document wins is covered by
// 'ranks the relevant document first' above, and must not be asserted twice here:
// FakeEmbedder hashes tokens into 64 buckets, and md5('refund') and md5('shipping')
// both land in bucket 40, so a single-token query is decided by that collision
// rather than by relevance.
it('builds a prompt block with numbered source markers', function (): void {
    $result = app(Retriever::class)->retrieve('refund for damaged goods', limit: 2);
    $prompt = $result->toPrompt();

    expect($result->count())->toBe(2)
        ->and($prompt)->toContain("[1] source: {$result->citations[0]->source}")
        ->and($prompt)->toContain("[2] source: {$result->citations[1]->source}")
        ->and($prompt)->toContain($result->citations[0]->text);
});

it('filters by a metadata key', function (): void {
    app(Ingestor::class)->ingest('manuals/a.md', 'refund handling for damaged goods', 'default', ['team' => 'support']);
    app(Ingestor::class)->ingest('manuals/b.md', 'refund handling for damaged goods', 'default', ['team' => 'finance']);

    $result = app(Retriever::class)->retrieve('refund damaged goods', limit: 10, filters: ['team' => 'support']);

    expect($result->sources())->toBe(['manuals/a.md']);
});

it('filters by several values for one metadata key', function (): void {
    app(Ingestor::class)->ingest('manuals/a.md', 'refund handling one', 'default', ['team' => 'support']);
    app(Ingestor::class)->ingest('manuals/b.md', 'refund handling two', 'default', ['team' => 'finance']);
    app(Ingestor::class)->ingest('manuals/c.md', 'refund handling three', 'default', ['team' => 'legal']);

    $result = app(Retriever::class)->retrieve('refund handling', limit: 10, filters: ['team' => ['support', 'legal']]);

    expect($result->sources())->toEqualCanonicalizing(['manuals/a.md', 'manuals/c.md']);
});

it('treats legacy suffix-less ids as the current model', function (): void {
    $ids = DB::table('rag_chunks')->orderBy('id')->pluck('id');
    DB::table('rag_chunks')
        ->whereIn('id', $ids->take(max(1, intdiv($ids->count(), 2)))->all())
        ->update(['embedding_model' => 'fake:deterministic-hash']);

    expect(DB::table('rag_chunks')->distinct()->pluck('embedding_model')->sort()->values()->all())
        ->toBe(['fake:deterministic-hash', 'fake:deterministic-hash:64'])
        ->and(Rag::retrieve('refund for damaged goods')->isEmpty())->toBeFalse();
});

it('scopes the mixed-model guard to the searched collection', function (): void {
    $ingestor = app(Ingestor::class);
    $ingestor->ingest('a/refunds.md', 'refund for damaged goods in collection a', 'a');
    $ingestor->ingest('b/refunds.md', 'refund for damaged goods in collection b', 'b');

    DB::table('rag_chunks')
        ->whereIn('document_id', DB::table('rag_documents')->where('collection', 'b')->pluck('id')->all())
        ->update(['embedding_model' => 'other:model:64']);

    expect(Rag::retrieve('refund', 10, ['collection' => 'a'])->sources())->toBe(['a/refunds.md'])
        ->and(fn () => Rag::retrieve('refund'))->toThrow(MixedEmbeddingModels::class, 'other:model:64');
});

it('re-checks the corpus on every search', function (): void {
    expect(Rag::retrieve('refund')->isEmpty())->toBeFalse();

    DB::table('rag_chunks')->limit(1)->update(['embedding_model' => 'other:model:64']);

    expect(fn () => Rag::retrieve('refund'))->toThrow(MixedEmbeddingModels::class);
});

it('rejects a stored vector of another width on the in-PHP path', function (): void {
    DB::table('rag_chunks')->limit(1)->update(['embedding' => json_encode(array_fill(0, 32, 0.1))]);

    expect(fn () => Rag::retrieve('refund'))
        ->toThrow(DimensionMismatch::class, 'rag:reindex');
});

it('rejects a query vector that does not match the configured width', function (): void {
    useEmbedder(new class implements Embedder
    {
        public function embed(array $texts): array
        {
            return (new FakeEmbedder(32))->embed($texts);
        }

        public function modelId(): string
        {
            return 'fake:deterministic-hash:64';
        }

        public function dimensions(): int
        {
            return 64;
        }

        public function estimateTokens(array $texts): int
        {
            return 0;
        }
    });

    expect(fn () => Rag::retrieve('refund'))
        ->toThrow(DimensionMismatch::class, '32');
});

it('returns an empty result for a blank query without embedding', function (): void {
    useEmbedder(new class implements Embedder
    {
        public function embed(array $texts): array
        {
            throw new LogicException('A blank query must not be embedded.');
        }

        public function modelId(): string
        {
            return 'fake:deterministic-hash:64';
        }

        public function dimensions(): int
        {
            return 64;
        }

        public function estimateTokens(array $texts): int
        {
            return 0;
        }
    });

    $result = Rag::retrieve("   \n\t");
    $unicode = Rag::retrieve("\u{00A0}\u{3000}");

    expect($result->isEmpty())->toBeTrue()
        ->and($result->embeddingModel)->toBe('fake:deterministic-hash:64')
        ->and($unicode->isEmpty())->toBeTrue();
});

it('answers a blank query before checking the corpus for mixed models', function (): void {
    DB::table('rag_chunks')->limit(1)->update(['embedding_model' => 'other:model:64']);

    expect(Rag::retrieve('   ')->isEmpty())->toBeTrue();
});

it('uses retrieval.default_limit when no limit is given', function (): void {
    $ingestor = app(Ingestor::class);
    $ingestor->ingest('policies/returns.md', 'Returns are accepted for unused items in their packaging.');
    $ingestor->ingest('policies/warranty.md', 'The warranty covers manufacturing faults for two years.');

    config()->set('rag.retrieval.default_limit', 2);
    app()->forgetInstance(Retriever::class);
    app()->forgetInstance('rag');
    Rag::clearResolvedInstance('rag');

    expect(Rag::retrieve('policy refund shipping leave returns warranty')->count())->toBe(2);
});

it('rejects filter keys that are not plain identifiers', function (): void {
    expect(fn () => Rag::retrieve('x', null, ['a->b' => 1]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a limit below one', function (): void {
    expect(fn () => Rag::retrieve('refund', 0))
        ->toThrow(InvalidArgumentException::class, 'Retrieval limit must be at least 1, got 0.');
});

it('rejects a configured default limit below one', function (): void {
    config()->set('rag.retrieval.default_limit', 0);
    app()->forgetInstance(Retriever::class);
    app()->forgetInstance('rag');
    Rag::clearResolvedInstance('rag');

    expect(fn () => Rag::retrieve('refund'))
        ->toThrow(InvalidArgumentException::class, 'Retrieval limit must be at least 1, got 0.');
});

it('does not take a stored full id for a legacy one in the mixed-model guard', function (): void {
    useEmbedder(new class implements Embedder
    {
        public function embed(array $texts): array
        {
            return (new FakeEmbedder(64))->embed($texts);
        }

        public function modelId(): string
        {
            return 'drv:foo:768:64';
        }

        public function dimensions(): int
        {
            return 64;
        }

        public function estimateTokens(array $texts): int
        {
            return 0;
        }
    });

    DB::table('rag_chunks')->update(['embedding_model' => 'drv:foo:768']);

    expect(fn () => Rag::retrieve('refund'))->toThrow(MixedEmbeddingModels::class, 'drv:foo:768');
});

it('ignores chunks without an embedding in the mixed-model guard', function (): void {
    // What a resize leaves behind until the reindex reaches a document: the
    // vector is cleared, the old model id stays.
    DB::table('rag_chunks')
        ->whereIn('document_id', DB::table('rag_documents')->where('source', 'policies/shipping.md')->pluck('id')->all())
        ->update(['embedding' => null, 'embedding_model' => 'fake:deterministic-hash:32']);

    $sources = Rag::retrieve('refund for damaged goods', 10)->sources();

    expect($sources)->toContain('policies/refunds.md')
        ->and($sources)->not->toContain('policies/shipping.md');
});
