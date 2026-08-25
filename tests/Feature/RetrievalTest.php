<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Exceptions\MixedEmbeddingModels;
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
        ->toBe('fake:deterministic-hash');
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
