<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Embedder;
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
    $chunksBefore = Chunk::query()->orderBy('id')->get(['id', 'embedding_model'])->toArray();

    $spy = useSpyEmbedder();
    $spy->fail = true;

    $this->artisan('rag:reindex', ['--force' => true])
        ->expectsOutputToContain('embedding provider unavailable')
        ->assertFailed();

    expect(Document::firstOrFail()->content_hash)->toBe($hash)
        ->and(Chunk::query()->orderBy('id')->get(['id', 'embedding_model'])->toArray())->toBe($chunksBefore);
});

it('does not reindex chunks that carry the legacy form of the current model id with --stale', function (): void {
    app(Ingestor::class)->ingest('db:1', 'Refunds are available within 30 days of delivery.');

    Chunk::query()->update(['embedding_model' => 'fake:deterministic-hash']);

    $spy = useSpyEmbedder();

    $this->artisan('rag:reindex', ['--stale' => true])
        ->expectsOutputToContain('No stale documents')
        ->assertSuccessful();

    expect($spy->calls)->toBe(0);
});

it('reindexes a document with a null embedding with --stale', function (): void {
    $ingestor = app(Ingestor::class);
    $ingestor->ingest('db:fresh', 'Shipping takes three to five working days.');
    $ingestor->ingest('db:empty', 'Refunds are available within 30 days of delivery.');

    $empty = Document::where('source', 'db:empty')->firstOrFail();
    Chunk::where('document_id', $empty->id)->update(['embedding' => null]);

    $spy = useSpyEmbedder();

    $this->artisan('rag:reindex', ['--stale' => true, '--force' => true])->assertSuccessful();

    expect($spy->calls)->toBe(1)
        ->and(Chunk::where('document_id', $empty->id)->whereNull('embedding')->count())->toBe(0);
});

it('reports every failed document even when two collections share a source', function (): void {
    $ingestor = app(Ingestor::class);
    $ingestor->ingest('docs/refunds.md', 'Refunds within 30 days.', collection: 'a');
    $ingestor->ingest('docs/refunds.md', 'Refunds within 30 days.', collection: 'b');

    $spy = useSpyEmbedder();
    $spy->fail = true;

    $this->artisan('rag:reindex', ['--force' => true])
        ->expectsOutputToContain('2 document(s) could not be reindexed')
        ->expectsOutputToContain('a/docs/refunds.md')
        ->expectsOutputToContain('b/docs/refunds.md')
        ->assertFailed();
});

/**
 * A 32-wide fake embedder that runs `$onEmbed` with the texts before answering,
 * so a test can land an app ingest in the middle of a reindex.
 */
function embedderThatInterrupts(Closure $onEmbed): void
{
    useEmbedder(new class($onEmbed) implements Embedder
    {
        public function __construct(private readonly Closure $onEmbed) {}

        public function embed(array $texts): array
        {
            ($this->onEmbed)($texts);

            return (new FakeEmbedder(32))->embed($texts);
        }

        public function modelId(): string
        {
            return 'fake:deterministic-hash:32';
        }

        public function dimensions(): int
        {
            return 32;
        }

        public function estimateTokens(array $texts): int
        {
            return 0;
        }
    });
}

/** What an app ingest of a new revision leaves in the row, written behind the reindex's back. */
function reviseBehindTheReindex(string $source, string $content): void
{
    Document::where('source', $source)->update(['content' => $content, 'content_hash' => Document::hashFor($content)]);
}

it('reads each document just before re-embedding it, not when its page was loaded', function (): void {
    $ingestor = app(Ingestor::class);
    $ingestor->ingest('db:a', 'Alpha refunds are available within 30 days.');
    $ingestor->ingest('db:b', 'Beta shipping takes five days.');

    // While document a is embedded, the app saves a new revision of b.
    embedderThatInterrupts(function (array $texts): void {
        if (str_contains(implode(' ', $texts), 'Alpha')) {
            reviseBehindTheReindex('db:b', 'Beta shipping now takes two days.');
        }
    });

    $this->artisan('rag:reindex', ['--force' => true])->assertSuccessful();

    $b = Document::where('source', 'db:b')->firstOrFail();

    expect($b->content)->toBe('Beta shipping now takes two days.')
        ->and($b->chunks()->pluck('text')->all())->toBe(['Beta shipping now takes two days.'])
        ->and($b->chunks()->pluck('embedding_model')->unique()->all())->toBe(['fake:deterministic-hash:32']);
});

it('leaves a document alone when a newer ingest lands while it is re-embedded', function (): void {
    app(Ingestor::class)->ingest('db:b', 'Beta shipping takes five days.');

    $chunksBefore = Chunk::query()->orderBy('id')->get(['id', 'text', 'embedding_model'])->toArray();

    // The new revision is saved during b's own embedding call, after the reindex read it.
    embedderThatInterrupts(function (array $texts): void {
        if (str_contains(implode(' ', $texts), 'five days')) {
            reviseBehindTheReindex('db:b', 'Beta shipping now takes two days.');
        }
    });

    $this->artisan('rag:reindex', ['--force' => true])
        ->expectsOutputToContain('changed while it was being reindexed')
        ->assertSuccessful();

    $b = Document::where('source', 'db:b')->firstOrFail();

    expect($b->content)->toBe('Beta shipping now takes two days.')
        ->and($b->content_hash)->toBe(Document::hashFor('Beta shipping now takes two days.'))
        ->and(Chunk::query()->orderBy('id')->get(['id', 'text', 'embedding_model'])->toArray())->toBe($chunksBefore);
});

it('passes over a document deleted while the reindex runs', function (): void {
    $ingestor = app(Ingestor::class);
    $ingestor->ingest('db:a', 'Alpha refunds are available within 30 days.');
    $ingestor->ingest('db:b', 'Beta shipping takes five days.');

    embedderThatInterrupts(function (array $texts): void {
        if (str_contains(implode(' ', $texts), 'Alpha')) {
            Document::where('source', 'db:b')->delete();
        }
    });

    $this->artisan('rag:reindex', ['--force' => true])->assertSuccessful();

    expect(Document::query()->pluck('source')->all())->toBe(['db:a']);
});

it('runs --resize as --stale where the vector width is not fixed', function (): void {
    $ingestor = app(Ingestor::class);
    $ingestor->ingest('db:fresh', 'Shipping takes three to five working days.');
    $ingestor->ingest('db:stale', 'Refunds are available within 30 days of delivery.');

    $stale = Document::where('source', 'db:stale')->firstOrFail();
    Chunk::where('document_id', $stale->id)->update(['embedding_model' => 'old:model']);

    $spy = useSpyEmbedder();

    $this->artisan('rag:reindex', ['--resize' => true, '--force' => true])
        ->expectsOutputToContain('Vector width is not fixed on this database; running --stale.')
        ->assertSuccessful();

    expect($spy->calls)->toBe(1)
        ->and($stale->chunks()->pluck('embedding_model')->unique()->all())->toBe(['fake:deterministic-hash:64']);
});

it('refuses --resize with --collection before changing anything', function (): void {
    app(Ingestor::class)->ingest('db:1', 'Refunds are available within 30 days of delivery.', collection: 'a');
    Chunk::query()->update(['embedding_model' => 'old:model']);

    $spy = useSpyEmbedder();

    $this->artisan('rag:reindex', ['--resize' => true, '--collection' => 'a', '--force' => true])
        ->expectsOutputToContain('--collection')
        ->assertFailed();

    expect($spy->calls)->toBe(0)
        ->and(Chunk::query()->pluck('embedding_model')->unique()->all())->toBe(['old:model']);
});

/**
 * Load config/rag.php with the given environment variables in place, restoring
 * whatever was there before in every place Laravel's env() can read it from.
 *
 * @param  array<string, string|null>  $vars
 * @return array<string, mixed>
 */
function ragConfigWithEnv(array $vars): array
{
    $saved = [];

    foreach ($vars as $name => $value) {
        $saved[$name] = [getenv($name), $_SERVER[$name] ?? null, $_ENV[$name] ?? null];

        if ($value === null) {
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);
        } else {
            putenv("{$name}={$value}");
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
    }

    try {
        return require __DIR__.'/../../config/rag.php';
    } finally {
        foreach ($saved as $name => [$env, $server, $envVar]) {
            $env === false ? putenv($name) : putenv("{$name}={$env}");

            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }

            if ($envVar === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $envVar;
            }
        }
    }
}

it('gates on the configured thresholds, so a stricter floor fails a suite that passes at the defaults', function (): void {
    app(Ingestor::class)->ingest('policies/refunds.md', 'Refunds are available within 30 days of delivery.');

    $path = sys_get_temp_dir().'/rag-ci-floor-'.uniqid().'.json';
    file_put_contents($path, json_encode([
        'refund' => ['query' => 'refunds within 30 days', 'expected_sources' => ['policies/refunds.md']],
    ]));

    try {
        // Control: the same suite clears the default floors.
        $this->artisan('rag:eval', ['--suite' => $path, '--ci' => true, '--no-record' => true])
            ->assertSuccessful();

        config(['rag.eval.thresholds.mrr' => 1.1]);

        $this->artisan('rag:eval', ['--suite' => $path, '--ci' => true, '--no-record' => true])
            ->assertFailed();
    } finally {
        unlink($path);
    }
});

it('reads the eval floors from RAG_EVAL_MIN_* environment variables', function (): void {
    $config = ragConfigWithEnv([
        'RAG_EVAL_MIN_PASS_RATE' => '0.95',
        'RAG_EVAL_MIN_RECALL_AT_K' => '0.9',
        'RAG_EVAL_MIN_MRR' => '0.85',
    ]);

    expect($config['eval']['thresholds'])->toBe(['pass_rate' => 0.95, 'recall_at_k' => 0.9, 'mrr' => 0.85]);
});

it('keeps the default floors when a RAG_EVAL_MIN_* variable is empty or unset', function (): void {
    $config = ragConfigWithEnv([
        'RAG_EVAL_MIN_PASS_RATE' => '',
        'RAG_EVAL_MIN_RECALL_AT_K' => null,
        'RAG_EVAL_MIN_MRR' => '',
    ]);

    expect($config['eval']['thresholds'])->toBe(['pass_rate' => 0.90, 'recall_at_k' => 0.80, 'mrr' => 0.60]);
});
