<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Eval\EvalCase;
use Ikram\Rag\Eval\EvalRunRecorder;
use Ikram\Rag\Eval\EvalSuite;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(Ingestor::class)->ingest('policies/refunds.md', 'Customers may request a refund within 30 days of delivery for damaged goods.');
});

it('records a run so scores can be compared over time', function (): void {
    $report = (new EvalSuite('history', [
        new EvalCase('refund', 'refund damaged goods', ['policies/refunds.md']),
    ]))->run(app(Retriever::class));

    app(EvalRunRecorder::class)->record($report);

    $row = DB::table('rag_eval_runs')->first();

    expect($row)->not->toBeNull()
        ->and($row->suite)->toBe('history')
        ->and($row->embedding_model)->toBe('fake:deterministic-hash:64')
        ->and((int) $row->total_cases)->toBe(1)
        ->and(json_decode((string) $row->metrics, true))->toHaveKey('recall_at_k');
});

it('records failure reasons alongside the scores', function (): void {
    $report = (new EvalSuite('history', [
        new EvalCase('missing', 'refund', ['policies/nonexistent.md']),
    ]))->run(app(Retriever::class));

    app(EvalRunRecorder::class)->record($report);

    $failures = json_decode((string) DB::table('rag_eval_runs')->value('failures'), true);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['id'])->toBe('missing');
});

it('carries the embedding model on the report, so a run is traceable to its vectors', function (): void {
    $report = (new EvalSuite('history', [
        new EvalCase('refund', 'refund', ['policies/refunds.md']),
    ]))->run(app(Retriever::class));

    expect($report->embeddingModel)->toBe('fake:deterministic-hash:64')
        ->and(json_decode((string) json_encode($report), true))
        ->toHaveKey('embedding_model');
});

it('stores a run for every eval invocation', function (): void {
    $suite = sys_get_temp_dir().'/rag-hist-'.uniqid().'.json';

    file_put_contents($suite, json_encode([
        'refund' => ['query' => 'refund damaged goods', 'expected_sources' => ['policies/refunds.md']],
    ]));

    $this->artisan('rag:eval', ['--suite' => $suite])->assertSuccessful();
    $this->artisan('rag:eval', ['--suite' => $suite])->assertSuccessful();

    expect(DB::table('rag_eval_runs')->count())->toBe(2);

    unlink($suite);
});

it('does not store a run when --no-record is given', function (): void {
    $suite = sys_get_temp_dir().'/rag-norec-'.uniqid().'.json';

    file_put_contents($suite, json_encode([
        'refund' => ['query' => 'refund damaged goods', 'expected_sources' => ['policies/refunds.md']],
    ]));

    $this->artisan('rag:eval', ['--suite' => $suite, '--no-record' => true])->assertSuccessful();

    expect(DB::table('rag_eval_runs')->count())->toBe(0);

    unlink($suite);
});

it('keeps stdout pure json so --json output stays machine-readable', function (): void {
    $suite = sys_get_temp_dir().'/rag-json-'.uniqid().'.json';

    file_put_contents($suite, json_encode([
        'refund' => ['query' => 'refund damaged goods', 'expected_sources' => ['policies/refunds.md']],
    ]));

    expect(Artisan::call('rag:eval', ['--suite' => $suite, '--json' => true]))->toBe(0);

    expect(json_decode(trim(Artisan::output()), true))->toHaveKey('metrics');

    unlink($suite);
});

/**
 * @return array{0: string, 1: string}
 */
function evalConfigFixtures(): array
{
    $suite = sys_get_temp_dir().'/rag-cfg-'.uniqid().'.json';

    file_put_contents($suite, json_encode([
        'refund' => ['query' => 'refund damaged goods', 'expected_sources' => ['policies/refunds.md']],
    ]));

    return [$suite, sys_get_temp_dir().'/rag-cfg-base-'.uniqid().'.json'];
}

it('records the configuration the run used', function (): void {
    [$suite] = evalConfigFixtures();

    $this->artisan('rag:eval', ['--suite' => $suite, '--k' => 3])->assertSuccessful();

    $config = json_decode((string) DB::table('rag_eval_runs')->value('config'), true);

    expect($config)->toHaveKeys(['k', 'chunk_size', 'chunk_overlap', 'min_score', 'rerank', 'embedding_model'])
        ->and($config['k'])->toBe(3)
        ->and($config['embedding_model'])->toBe('fake:deterministic-hash:64')
        ->and($config['rerank'])->toBe(['enabled' => false, 'provider' => null, 'model' => null, 'candidates' => 3]);

    unlink($suite);
});

it('writes the configuration into a --save file', function (): void {
    [$suite, $saved] = evalConfigFixtures();

    $this->artisan('rag:eval', ['--suite' => $suite, '--save' => $saved, '--no-record' => true])->assertSuccessful();

    $decoded = json_decode((string) file_get_contents($saved), true);

    expect($decoded['config'])->toHaveKeys(['k', 'chunk_size', 'embedding_model']);

    unlink($suite);
    unlink($saved);
});

it('accepts a pre-0.3 baseline without crying model mismatch, and says config was not compared', function (): void {
    [$suite, $baseline] = evalConfigFixtures();

    file_put_contents($baseline, json_encode([
        'suite' => 'old',
        'k' => 5,
        'embedding_model' => 'fake:deterministic-hash',
        'metrics' => ['recall_at_k' => 1.0],
    ]));

    $this->artisan('rag:eval', ['--suite' => $suite, '--baseline' => $baseline, '--no-record' => true])
        ->expectsOutputToContain('Baseline predates config recording; settings were not compared.')
        ->doesntExpectOutputToContain('different embedding model')
        ->assertSuccessful();

    unlink($suite);
    unlink($baseline);
});

it('warns which settings differ from the baseline', function (): void {
    [$suite, $baseline] = evalConfigFixtures();

    file_put_contents($baseline, json_encode([
        'suite' => 'old',
        'k' => 9,
        'embedding_model' => 'fake:deterministic-hash:64',
        'metrics' => ['recall_at_k' => 1.0],
        'config' => [
            'k' => 9,
            'chunk_size' => 200,
            'chunk_overlap' => 40,
            'min_score' => 0.0,
            'rerank' => ['enabled' => false, 'provider' => null, 'model' => null, 'candidates' => 3],
            'embedding_model' => 'fake:deterministic-hash:64',
        ],
    ]));

    $this->artisan('rag:eval', ['--suite' => $suite, '--baseline' => $baseline, '--no-record' => true])
        ->expectsOutputToContain('Baseline was run with different settings: k.')
        ->assertSuccessful();

    unlink($suite);
    unlink($baseline);
});

it('keeps --json stdout pure when comparing against a baseline', function (): void {
    [$suite, $baseline] = evalConfigFixtures();

    file_put_contents($baseline, json_encode(['metrics' => ['recall_at_k' => 1.0]]));

    Artisan::call('rag:eval', ['--suite' => $suite, '--baseline' => $baseline, '--json' => true, '--no-record' => true]);

    expect(json_decode(trim(Artisan::output()), true))->toHaveKey('config');

    unlink($suite);
    unlink($baseline);
});
