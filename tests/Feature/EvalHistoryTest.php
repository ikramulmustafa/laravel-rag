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
        ->and($row->embedding_model)->toBe('fake:deterministic-hash')
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

    expect($report->embeddingModel)->toBe('fake:deterministic-hash')
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
