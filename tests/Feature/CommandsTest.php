<?php

declare(strict_types=1);

use Ikram\Rag\Documents\Chunk;
use Ikram\Rag\Documents\Document;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Embedding\FakeEmbedder;
use Ikram\Rag\Facades\Rag;

it('ingests a directory of markdown files', function (): void {
    $dir = sys_get_temp_dir().'/rag-ingest-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/one.md', 'First document about refunds.');
    file_put_contents($dir.'/two.md', 'Second document about shipping.');
    file_put_contents($dir.'/skip.txt', 'Should not be ingested.');

    $this->artisan('rag:ingest', ['path' => $dir, '--force' => true])
        ->assertSuccessful();

    expect(Document::count())->toBe(2);

    array_map(unlink(...), glob($dir.'/*'));
    rmdir($dir);
});

it('fails cleanly when the ingest path does not exist', function (): void {
    $this->artisan('rag:ingest', ['path' => '/no/such/path', '--force' => true])
        ->assertFailed();
});

it('reports a missing eval suite with a usable example', function (): void {
    $this->artisan('rag:eval', ['--suite' => '/no/such/suite.json'])
        ->expectsOutputToContain('No eval suite at')
        ->assertFailed();
});

it('exits non-zero in ci mode when thresholds are breached', function (): void {
    app(Ingestor::class)->ingest('policies/refunds.md', 'Refunds within 30 days.');

    $path = sys_get_temp_dir().'/rag-ci-'.uniqid().'.json';
    file_put_contents($path, json_encode([
        'impossible' => ['query' => 'refund', 'expected_sources' => ['does/not/exist.md']],
    ]));

    $this->artisan('rag:eval', ['--suite' => $path, '--ci' => true])
        ->assertFailed();

    unlink($path);
});

it('exits zero in ci mode when thresholds are met', function (): void {
    app(Ingestor::class)->ingest('policies/refunds.md', 'Refunds are available within 30 days of delivery.');

    $path = sys_get_temp_dir().'/rag-ci-ok-'.uniqid().'.json';
    file_put_contents($path, json_encode([
        'refund' => ['query' => 'refunds within 30 days', 'expected_sources' => ['policies/refunds.md']],
    ]));

    $this->artisan('rag:eval', ['--suite' => $path, '--ci' => true])
        ->assertSuccessful();

    unlink($path);
});

it('writes a json report that can serve as a baseline', function (): void {
    app(Ingestor::class)->ingest('policies/refunds.md', 'Refunds within 30 days.');

    $suite = sys_get_temp_dir().'/rag-s-'.uniqid().'.json';
    $out = sys_get_temp_dir().'/rag-o-'.uniqid().'.json';

    file_put_contents($suite, json_encode([
        'refund' => ['query' => 'refund', 'expected_sources' => ['policies/refunds.md']],
    ]));

    $this->artisan('rag:eval', ['--suite' => $suite, '--save' => $out])->assertSuccessful();

    expect(json_decode((string) file_get_contents($out), true))
        ->toHaveKey('metrics');

    unlink($suite);
    unlink($out);
});

it('warns rather than failing when there is nothing to reindex', function (): void {
    $this->artisan('rag:reindex', ['--force' => true])->assertSuccessful();
});

it('exits non-zero in ci mode when a metric regressed against the baseline', function (): void {
    app(Ingestor::class)->ingest('policies/refunds.md', 'Refunds are available within 30 days of delivery.');

    $suite = sys_get_temp_dir().'/rag-reg-s-'.uniqid().'.json';
    $baseline = sys_get_temp_dir().'/rag-reg-b-'.uniqid().'.json';

    // The suite still passes its absolute floors; only the baseline says it got worse.
    file_put_contents($suite, json_encode([
        'refund' => ['query' => 'refunds within 30 days', 'expected_sources' => ['policies/refunds.md']],
    ]));
    file_put_contents($baseline, json_encode([
        'suite' => 'rag-reg-s',
        'metrics' => ['pass_rate' => 1.0, 'recall_at_k' => 1.0, 'mrr' => 1.0, 'mean_top_score' => 0.99],
    ]));

    $this->artisan('rag:eval', ['--suite' => $suite, '--baseline' => $baseline, '--ci' => true])
        ->expectsOutputToContain('mean_top_score')
        ->assertFailed();

    unlink($suite);
    unlink($baseline);
});

it('exits zero when the baseline shows no regression', function (): void {
    app(Ingestor::class)->ingest('policies/refunds.md', 'Refunds are available within 30 days of delivery.');

    $suite = sys_get_temp_dir().'/rag-noreg-s-'.uniqid().'.json';
    $baseline = sys_get_temp_dir().'/rag-noreg-b-'.uniqid().'.json';

    file_put_contents($suite, json_encode([
        'refund' => ['query' => 'refunds within 30 days', 'expected_sources' => ['policies/refunds.md']],
    ]));

    // Save a real run, then compare the same run against it.
    $this->artisan('rag:eval', ['--suite' => $suite, '--json' => true, '--save' => $baseline])
        ->assertSuccessful();

    $this->artisan('rag:eval', ['--suite' => $suite, '--baseline' => $baseline, '--ci' => true])
        ->assertSuccessful();

    unlink($suite);
    unlink($baseline);
});

it('reports a regression without failing when --ci is absent', function (): void {
    app(Ingestor::class)->ingest('policies/refunds.md', 'Refunds are available within 30 days of delivery.');

    $suite = sys_get_temp_dir().'/rag-info-s-'.uniqid().'.json';
    $baseline = sys_get_temp_dir().'/rag-info-b-'.uniqid().'.json';

    file_put_contents($suite, json_encode([
        'refund' => ['query' => 'refunds within 30 days', 'expected_sources' => ['policies/refunds.md']],
    ]));
    file_put_contents($baseline, json_encode([
        'metrics' => ['pass_rate' => 1.0, 'recall_at_k' => 1.0, 'mrr' => 1.0, 'mean_top_score' => 0.99],
    ]));

    $this->artisan('rag:eval', ['--suite' => $suite, '--baseline' => $baseline])
        ->assertSuccessful();

    unlink($suite);
    unlink($baseline);
});

it('warns when the baseline was produced by a different embedding model', function (): void {
    app(Ingestor::class)->ingest('policies/refunds.md', 'Refunds are available within 30 days of delivery.');

    $suite = sys_get_temp_dir().'/rag-model-s-'.uniqid().'.json';
    $baseline = sys_get_temp_dir().'/rag-model-b-'.uniqid().'.json';

    file_put_contents($suite, json_encode([
        'refund' => ['query' => 'refunds within 30 days', 'expected_sources' => ['policies/refunds.md']],
    ]));
    file_put_contents($baseline, json_encode([
        'embedding_model' => 'openai:text-embedding-3-small',
        'metrics' => ['pass_rate' => 1.0, 'recall_at_k' => 1.0, 'mrr' => 1.0, 'mean_top_score' => 0.1],
    ]));

    $this->artisan('rag:eval', ['--suite' => $suite, '--baseline' => $baseline])
        ->expectsOutputToContain('different embedding model')
        ->assertSuccessful();

    unlink($suite);
    unlink($baseline);
});

it('reindexes content that was ingested from the database', function (): void {
    Rag::ingest('db:1', 'Refunds are available within 30 days of delivery.');

    useEmbedder(new FakeEmbedder(32));

    $this->artisan('rag:reindex', ['--force' => true])->assertSuccessful();

    expect(Chunk::query()->pluck('embedding_model')->unique()->all())
        ->toBe(['fake:deterministic-hash:32']);
});

it('reindexes only stale documents with --stale', function (): void {
    $ingestor = app(Ingestor::class);
    $ingestor->ingest('db:fresh', 'Shipping takes three to five working days.');
    $ingestor->ingest('db:stale', 'Refunds are available within 30 days of delivery.');

    $fresh = Document::where('source', 'db:fresh')->firstOrFail();
    $stale = Document::where('source', 'db:stale')->firstOrFail();
    $freshChunkIds = $fresh->chunks()->pluck('id')->all();

    Chunk::where('document_id', $stale->id)->update(['embedding_model' => 'old:model']);

    $spy = useSpyEmbedder();

    $this->artisan('rag:reindex', ['--stale' => true, '--force' => true])->assertSuccessful();

    expect($spy->calls)->toBe(1)
        ->and($fresh->chunks()->pluck('id')->all())->toBe($freshChunkIds)
        ->and($stale->chunks()->pluck('embedding_model')->unique()->all())->toBe(['fake:deterministic-hash:64']);
});

it('warns and succeeds when --stale finds nothing stale', function (): void {
    app(Ingestor::class)->ingest('db:1', 'Refunds are available within 30 days of delivery.');

    $spy = useSpyEmbedder();

    $this->artisan('rag:reindex', ['--stale' => true])
        ->expectsOutputToContain('No stale documents')
        ->assertSuccessful();

    expect($spy->calls)->toBe(0);
});

it('keeps going and fails at the end when one document cannot be reindexed', function (): void {
    $ingestor = app(Ingestor::class);
    $ingestor->ingest('db:gone', 'Text that will lose its stored copy.');
    $ingestor->ingest('db:good', 'Annual leave accrues monthly.');

    Document::where('source', 'db:gone')->update(['content' => null]);

    useEmbedder(new FakeEmbedder(32));

    $this->artisan('rag:reindex', ['--force' => true])
        ->expectsOutputToContain('db:gone')
        ->assertFailed();

    $good = Document::where('source', 'db:good')->firstOrFail();

    expect($good->chunks()->pluck('embedding_model')->unique()->all())
        ->toBe(['fake:deterministic-hash:32']);
});

it('does not blank the hash when reindexing fails', function (): void {
    app(Ingestor::class)->ingest('db:1', 'Refunds are available within 30 days of delivery.');

    $hash = Document::firstOrFail()->content_hash;

    $spy = useSpyEmbedder();
    $spy->fail = true;

    $this->artisan('rag:reindex', ['--force' => true])
        ->expectsOutputToContain('embedding provider unavailable')
        ->assertFailed();

    expect(Document::firstOrFail()->content_hash)->toBe($hash);
});
