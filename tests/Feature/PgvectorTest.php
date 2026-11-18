<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Exceptions\MixedEmbeddingModels;
use Ikram\Rag\Facades\Rag;
use Illuminate\Support\Facades\DB;

/*
 * The pgvector query path: the `<=>` cosine operator, the ::vector cast and the
 * NULL-embedding guard. SQLite cannot reach any of it, so these run only in the
 * CI job that has Postgres, via `pest --group=pgsql`. Elsewhere they are excluded
 * by phpunit.xml, and skip if selected anyway.
 */
beforeEach(function (): void {
    skipUnlessPgsql();

    $ingestor = app(Ingestor::class);

    $ingestor->ingest('policies/refunds.md', 'Customers may request a refund within 30 days of delivery for damaged goods.');
    $ingestor->ingest('policies/shipping.md', 'Standard shipping takes 3 to 5 working days across the mainland.');
    $ingestor->ingest('handbook/leave.md', 'Annual leave accrues monthly and carries over to the following year.');
})->group('pgsql');

it('retrieves through the pgvector operator', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $result = app(Retriever::class)->retrieve('refund for damaged goods', limit: 3);

    expect($result->isEmpty())->toBeFalse()
        ->and($result->citations[0]->source)->toBe('policies/refunds.md')
        ->and($result->citations[0]->score)->toBeGreaterThan($result->citations[1]->score);
})->group('pgsql');

it('excludes chunks whose embedding is null', function (): void {
    expect(Rag::retrieve('refund for damaged goods', 10)->sources())->toContain('policies/refunds.md');

    DB::statement(
        'UPDATE rag_chunks SET embedding = NULL WHERE document_id IN (SELECT id FROM rag_documents WHERE source = ?)',
        ['policies/refunds.md'],
    );

    $sources = Rag::retrieve('refund for damaged goods', 10)->sources();

    expect($sources)->not->toContain('policies/refunds.md')
        ->and($sources)->toContain('policies/shipping.md');
})->group('pgsql');

it('scopes the guard per collection on pgsql', function (): void {
    $ingestor = app(Ingestor::class);
    $ingestor->ingest('a/refunds.md', 'refund for damaged goods in collection a', 'a');
    $ingestor->ingest('b/refunds.md', 'refund for damaged goods in collection b', 'b');

    DB::table('rag_chunks')
        ->whereIn('document_id', DB::table('rag_documents')->where('collection', 'b')->pluck('id')->all())
        ->update(['embedding_model' => 'other:model:64']);

    expect(Rag::retrieve('refund', 10, ['collection' => 'a'])->sources())->toBe(['a/refunds.md'])
        ->and(fn () => Rag::retrieve('refund'))->toThrow(MixedEmbeddingModels::class, 'other:model:64');
})->group('pgsql');
