<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Documents\Chunk;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Embedding\FakeEmbedder;
use Ikram\Rag\Exceptions\MixedEmbeddingModels;
use Ikram\Rag\Facades\Rag;
use Ikram\Rag\Storage\PgvectorColumn;
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

/** Whether the HNSW index on rag_chunks.embedding exists. */
function hasEmbeddingIndex(): bool
{
    return (int) DB::scalar(
        "SELECT count(*) FROM pg_indexes WHERE tablename = 'rag_chunks' AND indexname = 'rag_chunks_embedding_idx'"
    ) === 1;
}

/** Point the package at a new width, as a user would by changing RAG_EMBEDDING_DIMENSIONS. */
function switchToWidth(int $dimensions): void
{
    config(['rag.embedding.dimensions' => $dimensions]);
    useEmbedder(new FakeEmbedder($dimensions));
}

it('resizes the vector column and reindexes', function (): void {
    $this->artisan('rag:ingest', ['path' => __DIR__.'/../Fixtures/corpus', '--force' => true])->assertSuccessful();

    expect(PgvectorColumn::currentWidth(DB::connection()))->toBe(64)
        ->and(hasEmbeddingIndex())->toBeTrue();

    switchToWidth(32);

    $this->artisan('rag:reindex', ['--resize' => true, '--force' => true])->assertSuccessful();

    expect(PgvectorColumn::currentWidth(DB::connection()))->toBe(32)
        ->and(hasEmbeddingIndex())->toBeTrue()
        ->and(Chunk::query()->whereNull('embedding')->count())->toBe(0)
        ->and(Chunk::query()->pluck('embedding_model')->unique()->all())->toBe(['fake:deterministic-hash:32'])
        ->and(Rag::retrieve('refund for damaged goods', 3)->isEmpty())->toBeFalse();
})->group('pgsql');

it('leaves wide columns unindexed', function (): void {
    switchToWidth(2001);

    $this->artisan('rag:reindex', ['--resize' => true, '--force' => true])
        ->expectsOutputToContain('unindexed')
        ->assertSuccessful();

    expect(PgvectorColumn::currentWidth(DB::connection()))->toBe(2001)
        ->and(hasEmbeddingIndex())->toBeFalse()
        ->and(Chunk::query()->whereNull('embedding')->count())->toBe(0)
        ->and(PgvectorColumn::resize(DB::connection(), 2001))->toBeFalse()
        ->and(hasEmbeddingIndex())->toBeFalse();
})->group('pgsql');

it('asks before resizing and changes nothing when declined', function (): void {
    switchToWidth(32);

    $this->artisan('rag:reindex', ['--resize' => true])
        ->expectsConfirmation(
            'Resize the vector column from 64 to 32 dimensions? This clears every stored vector: '
            .'search returns no results for the 3 document(s) until re-embedding finishes.',
            'no',
        )
        ->assertFailed();

    expect(PgvectorColumn::currentWidth(DB::connection()))->toBe(64)
        ->and(hasEmbeddingIndex())->toBeTrue()
        ->and(Chunk::query()->whereNull('embedding')->count())->toBe(0);
})->group('pgsql');

it('runs a normal --stale reindex when the width already matches', function (): void {
    $spy = useSpyEmbedder();

    $this->artisan('rag:reindex', ['--resize' => true, '--force' => true])
        ->expectsOutputToContain('already 64 dimensions wide')
        ->expectsOutputToContain('No stale documents')
        ->assertSuccessful();

    expect($spy->calls)->toBe(0)
        ->and(hasEmbeddingIndex())->toBeTrue();
})->group('pgsql');

it('migrates a column wider than 2000 without an index', function (): void {
    $migrations = ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true];

    config(['rag.embedding.dimensions' => 2001]);

    $this->artisan('migrate:rollback', $migrations)->assertSuccessful();
    $this->artisan('migrate', $migrations)->assertSuccessful();

    expect(PgvectorColumn::currentWidth(DB::connection()))->toBe(2001)
        ->and(hasEmbeddingIndex())->toBeFalse();
})->group('pgsql');
