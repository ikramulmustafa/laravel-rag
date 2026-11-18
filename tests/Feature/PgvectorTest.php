<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Documents\Chunk;
use Ikram\Rag\Documents\Document;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Embedding\FakeEmbedder;
use Ikram\Rag\Exceptions\MixedEmbeddingModels;
use Ikram\Rag\Facades\Rag;
use Ikram\Rag\Retrieval\Vector;
use Ikram\Rag\Storage\PgvectorColumn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        // An exact scan, with no index to use, still answers.
        ->and(Rag::retrieve('refund for damaged goods', 3)->isEmpty())->toBeFalse()
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

it('keeps search working while a resize is only partly re-embedded', function (): void {
    // Neither stored nor a readable file, so this one document fails to re-embed.
    Document::where('source', 'policies/shipping.md')->update(['content' => null]);

    switchToWidth(32);

    $this->artisan('rag:reindex', ['--resize' => true, '--force' => true])
        ->expectsOutputToContain('1 document(s) could not be reindexed')
        // One expectation per written line: the footer is a single write, and each
        // expectation consumes the write it matches.
        ->expectsOutputToContain('left out of search until they are re-embedded. Fix the cause, or re-ingest them, then run rag:reindex --stale')
        ->assertFailed();

    $sources = Rag::retrieve('refund for damaged goods', 10)->sources();

    expect(PgvectorColumn::currentWidth(DB::connection()))->toBe(32)
        ->and($sources)->toContain('policies/refunds.md')
        ->and($sources)->not->toContain('policies/shipping.md');
})->group('pgsql');

it('refuses to resize a column that is not a vector column', function (): void {
    DB::statement('DROP INDEX rag_chunks_embedding_idx');
    DB::statement('ALTER TABLE rag_chunks ALTER COLUMN embedding TYPE halfvec(64) USING embedding::halfvec(64)');

    switchToWidth(32);

    $this->artisan('rag:reindex', ['--resize' => true, '--force' => true])
        ->expectsOutputToContain('halfvec(64)')
        ->assertFailed();

    expect(DB::scalar("SELECT format_type(atttypid, atttypmod) FROM pg_attribute WHERE attrelid = 'rag_chunks'::regclass AND attname = 'embedding'"))
        ->toBe('halfvec(64)')
        ->and(Chunk::query()->whereNull('embedding')->count())->toBe(0);
})->group('pgsql');

it('says to migrate when there is no chunks table to resize', function (): void {
    Schema::drop('rag_chunks');

    switchToWidth(32);

    $this->artisan('rag:reindex', ['--resize' => true, '--force' => true])
        ->expectsOutputToContain('php artisan migrate')
        ->assertFailed();
})->group('pgsql');

/**
 * Insert one document with one chunk holding exactly `$vector`, tagged with the
 * current embedder's id so the mixed-model guard lets it through.
 *
 * @param  list<float>  $vector
 */
function seedChunk(string $collection, string $source, array $vector): void
{
    $now = now();

    $documentId = DB::table('rag_documents')->insertGetId([
        'source' => $source,
        'collection' => $collection,
        'content_hash' => Document::hashFor($source),
        'content' => $source,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $chunkId = DB::table('rag_chunks')->insertGetId([
        'document_id' => $documentId,
        'text' => $source,
        'position' => 0,
        'embedding_model' => app(Embedder::class)->modelId(),
        'metadata' => '[]',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::statement('UPDATE rag_chunks SET embedding = ?::vector WHERE id = ?', [Vector::toLiteral($vector), $chunkId]);
}

/**
 * `$count` vectors near `$base`, every one distinct: vector i has component
 * i % width raised by a step that grows with i / width.
 *
 * Distinct matters. pgvector stores identical vectors as one HNSW element with
 * several heap rows, so a scan bounded by ef_search elements can hand back many
 * more rows than that. The fake embedder's 64 hash buckets make hundreds of
 * near-identical texts collapse onto a few dozen vectors, which hid the cap.
 *
 * @param  list<float>  $base
 * @return list<list<float>>
 */
function distinctVectorsNear(array $base, int $count): array
{
    $width = count($base);
    $vectors = [];

    for ($i = 0; $i < $count; $i++) {
        $vector = $base;
        $vector[$i % $width] += 0.01 * (1 + intdiv($i, $width));
        $vectors[] = $vector;
    }

    return $vectors;
}

/** What the planner and pgvector were doing, for an assertion message. */
function hnswDiagnostics(string $plan): string
{
    $show = static function (string $setting): string {
        try {
            return (string) DB::scalar("SHOW {$setting}");
        } catch (Throwable $e) {
            return 'unavailable ('.class_basename($e).')';
        }
    };

    $stats = DB::selectOne(
        "SELECT n_live_tup, last_analyze, last_autoanalyze FROM pg_stat_user_tables WHERE relname = 'rag_chunks'"
    );

    return implode("\n", [
        'plan:',
        $plan,
        'enable_seqscan='.$show('enable_seqscan').', enable_sort='.$show('enable_sort')
            .', hnsw.ef_search='.$show('hnsw.ef_search').', hnsw.iterative_scan='.$show('hnsw.iterative_scan'),
        'pgvector '.DB::scalar("SELECT extversion FROM pg_extension WHERE extname = 'vector'")
            .', index present: '.(hasEmbeddingIndex() ? 'yes' : 'no')
            .', rows: '.DB::scalar('SELECT count(*) FROM rag_chunks')
            .', distinct vectors: '.DB::scalar('SELECT count(DISTINCT embedding::text) FROM rag_chunks'),
        'stats: '.json_encode($stats),
    ]);
}

/*
 * An HNSW index scan returns about hnsw.ef_search rows (40 by default; the bound
 * is approximate, the layer-0 search can yield a few more), and filters are
 * applied after it. So without the retriever's own settings a limit well above
 * 40 came back short, and a small collection got only its share of those rows.
 */
it('returns more rows than the index default and fills a filtered search on a small collection', function (): void {
    $embedder = app(Embedder::class);

    // 380 rows close to the query in a large collection, 20 (5%) further from it in
    // a small one, so the rows nearest the query all come from the large collection.
    foreach (distinctVectorsNear($embedder->embed(['refunds'])[0], 380) as $i => $vector) {
        seedChunk('bulk', "bulk/{$i}.md", $vector);
    }

    foreach (distinctVectorsNear($embedder->embed(['warranty claims for refunds'])[0], 20) as $i => $vector) {
        seedChunk('rare', "rare/{$i}.md", $vector);
    }

    DB::statement('ANALYZE rag_documents');
    DB::statement('ANALYZE rag_chunks');

    // Leave the planner no cheaper plan than the HNSW index, as on a large table.
    // Session settings on the connection the retriever also uses.
    DB::statement('SET enable_seqscan = off');
    DB::statement('SET enable_sort = off');

    $literal = Vector::toLiteral($embedder->embed(['refunds'])[0]);
    $raw = 'SELECT id FROM rag_chunks ORDER BY embedding <=> ?::vector LIMIT 60';

    $plan = implode("\n", array_map(
        static fn (object $row): string => (string) array_values((array) $row)[0],
        DB::select("EXPLAIN (FORMAT TEXT) {$raw}", [$literal]),
    ));
    $capped = count(DB::select($raw, [$literal]));
    $diagnostics = hnswDiagnostics($plan);

    // The precondition, proved rather than assumed: every vector is its own index
    // element, the index is what answers, and at its default it stops short of the
    // 60 rows asked for. Only "short" is asserted: the size of the cap is
    // approximate (45 rows was seen at ef_search 40 on pgvector 0.8.7).
    expect((int) DB::scalar('SELECT count(DISTINCT embedding::text) FROM rag_chunks'))
        ->toBe((int) DB::scalar('SELECT count(*) FROM rag_chunks'), $diagnostics)
        ->and(str_contains($plan, 'rag_chunks_embedding_idx'))->toBeTrue("The raw query did not use the HNSW index.\n{$diagnostics}")
        ->and($capped)->toBeLessThan(60, "The index at its default ef_search should stop short of 60 rows.\n{$diagnostics}");

    DB::enableQueryLog();

    $all = app(Retriever::class)->retrieve('refunds', 60);
    $rare = app(Retriever::class)->retrieve('refunds', 30, ['collection' => 'rare']);

    $sql = array_column(DB::getQueryLog(), 'query');

    expect($all->count())->toBe(60, $diagnostics)
        ->and($rare->count())->toBe(20, $diagnostics)
        ->and(collect($rare->sources())->every(fn (string $s): bool => str_starts_with($s, 'rare/')))->toBeTrue()
        ->and($sql)->toContain('SET LOCAL hnsw.ef_search = 240')
        ->and($sql)->toContain('SET LOCAL hnsw.ef_search = 120')
        ->and($sql)->toContain('SET LOCAL hnsw.iterative_scan = relaxed_order')
        ->and(collect($sql)->contains(fn (string $q): bool => str_contains($q, '<=>') && str_contains($q, '::vector')))->toBeTrue();

    // Still in score order, whatever order a relaxed scan produced.
    $scores = array_map(fn ($c): float => $c->score, $rare->citations);
    $sorted = $scores;
    rsort($sorted);

    expect($scores)->toBe($sorted);
})->group('pgsql');

it('does not ask for an iterative scan without filters', function (): void {
    DB::enableQueryLog();

    app(Retriever::class)->retrieve('refund for damaged goods', 3);

    $sql = array_column(DB::getQueryLog(), 'query');

    expect($sql)->toContain('SET LOCAL hnsw.ef_search = 40')
        ->and($sql)->not->toContain('SET LOCAL hnsw.iterative_scan = relaxed_order');
})->group('pgsql');

it('keeps an ef_search the app tuned higher than the limit needs', function (): void {
    DB::statement('SET hnsw.ef_search = 200');
    DB::enableQueryLog();

    app(Retriever::class)->retrieve('refund for damaged goods', 3);

    $sql = array_column(DB::getQueryLog(), 'query');

    expect($sql)->toContain('SET LOCAL hnsw.ef_search = 200')
        ->and($sql)->not->toContain('SET LOCAL hnsw.ef_search = 40')
        // Settings only, no row counts: the cap's size is approximate. SET LOCAL
        // ended with the search's transaction, so the app's own value is untouched.
        ->and(DB::scalar('SHOW hnsw.ef_search'))->toBe('200');
})->group('pgsql');
