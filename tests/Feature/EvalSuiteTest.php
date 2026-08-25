<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Eval\EvalCase;
use Ikram\Rag\Eval\EvalReport;
use Ikram\Rag\Eval\EvalSuite;
use Ikram\Rag\Exceptions\EvalThresholdNotMet;

beforeEach(function (): void {
    $ingestor = app(Ingestor::class);

    $ingestor->ingest('policies/refunds.md', 'Customers may request a refund within 30 days of delivery for damaged goods.');
    $ingestor->ingest('policies/shipping.md', 'Standard shipping takes 3 to 5 working days across the mainland.');
});

it('passes when the expected source is retrieved', function (): void {
    $report = (new EvalSuite('t', [
        new EvalCase('refund', 'refund damaged goods', ['policies/refunds.md']),
    ]))->run(app(Retriever::class));

    expect($report->passed())->toBeTrue()
        ->and($report->metric('recall_at_k'))->toBe(1.0);
});

it('fails and explains when the expected source is missing', function (): void {
    $report = (new EvalSuite('t', [
        new EvalCase('missing', 'refund damaged goods', ['policies/nonexistent.md']),
    ]))->run(app(Retriever::class));

    expect($report->passed())->toBeFalse()
        ->and($report->failures()[0]->failures[0])->toContain('not in top-');
});

it('scores mrr by the rank of the first correct source', function (): void {
    $report = (new EvalSuite('t', [
        new EvalCase('top', 'refund damaged goods', ['policies/refunds.md']),
    ], k: 5))->run(app(Retriever::class));

    expect($report->metric('mrr'))->toBe(1.0);
});

it('enforces must_contain on retrieved text', function (): void {
    $report = (new EvalSuite('t', [
        new EvalCase('contains', 'refund', ['policies/refunds.md'], mustContain: ['30 days']),
    ]))->run(app(Retriever::class));

    expect($report->passed())->toBeTrue();
});

it('fails must_not_contain when forbidden text leaks in', function (): void {
    $report = (new EvalSuite('t', [
        new EvalCase('forbidden', 'refund', ['policies/refunds.md'], mustNotContain: ['refund']),
    ]))->run(app(Retriever::class));

    expect($report->passed())->toBeFalse()
        ->and($report->failures()[0]->failures[0])->toContain('forbidden text');
});

it('throws when a threshold is breached', function (): void {
    $report = (new EvalSuite('t', [
        new EvalCase('missing', 'anything', ['policies/nonexistent.md']),
    ]))->run(app(Retriever::class));

    expect(fn () => $report->assertThresholds(['recall_at_k' => 0.8]))
        ->toThrow(EvalThresholdNotMet::class, 'below threshold');
});

it('detects regression against a baseline beyond tolerance', function (): void {
    $good = (new EvalSuite('t', [
        new EvalCase('a', 'refund damaged goods', ['policies/refunds.md']),
    ]))->run(app(Retriever::class));

    $bad = (new EvalSuite('t', [
        new EvalCase('a', 'refund damaged goods', ['policies/nonexistent.md']),
    ]))->run(app(Retriever::class));

    expect($bad->regressionsAgainst($good, ['recall_at_k' => 0.05]))
        ->not->toBeEmpty();
});

it('reports no regression when scores hold steady', function (): void {
    $suite = new EvalSuite('t', [new EvalCase('a', 'refund damaged goods', ['policies/refunds.md'])]);

    $first = $suite->run(app(Retriever::class));
    $second = $suite->run(app(Retriever::class));

    expect($second->regressionsAgainst($first))->toBe([]);
});

it('rejects an empty suite', function (): void {
    expect(fn () => new EvalSuite('empty', []))
        ->toThrow(InvalidArgumentException::class, 'no cases');
});

it('rejects a case that expects no answer but names sources', function (): void {
    expect(fn () => new EvalCase('bad', 'q', ['a.md'], expectNoAnswer: true))
        ->toThrow(InvalidArgumentException::class);
});

it('serialises a report to json with failure reasons intact', function (): void {
    $report = (new EvalSuite('t', [
        new EvalCase('missing', 'q', ['policies/nonexistent.md']),
    ]))->run(app(Retriever::class));

    $json = json_decode((string) json_encode($report), true);

    expect($json['suite'])->toBe('t')
        ->and($json['failures'][0]['id'])->toBe('missing')
        ->and($json['failures'][0]['reasons'])->not->toBeEmpty();
});

it('loads a suite from a json file', function (): void {
    $path = sys_get_temp_dir().'/rag-suite-'.uniqid().'.json';

    file_put_contents($path, json_encode([
        'refund' => ['query' => 'refund damaged goods', 'expected_sources' => ['policies/refunds.md']],
    ]));

    $report = EvalSuite::fromJsonFile($path)->run(app(Retriever::class));

    expect($report->passed())->toBeTrue();

    unlink($path);
});

it('rejects a malformed suite file', function (): void {
    $path = sys_get_temp_dir().'/rag-bad-'.uniqid().'.json';
    file_put_contents($path, 'not json at all');

    expect(fn () => EvalSuite::fromJsonFile($path))
        ->toThrow(InvalidArgumentException::class, 'not valid JSON');

    unlink($path);
});

// A metric of 0.7666… is written to a baseline file as 0.7667. Compared back at full
// float precision that is a -0.0000333 drop, so a zero tolerance called every run a
// regression and --ci --baseline could never pass. Both sides are rounded to the
// precision the file actually holds.
it('does not call a round-trip through a saved baseline a regression', function (): void {
    $report = (new EvalSuite('t', [
        new EvalCase('a', 'refund damaged goods', ['policies/refunds.md']),
        new EvalCase('b', 'shipping mainland working days', ['policies/refunds.md']),
        new EvalCase('c', 'refund damaged goods', ['policies/refunds.md']),
    ]))->run(app(Retriever::class));

    // 3 cases means at least one metric cannot be held exactly at 4 decimal places.
    expect($report->metric('mrr'))->not->toBe(round($report->metric('mrr'), 4));

    $roundTripped = EvalReport::baselineFromArray(
        (array) json_decode((string) json_encode($report), true)
    );

    expect($report->regressionsAgainst($roundTripped))->toBe([])
        ->and($report->diff($roundTripped)['mrr']['delta'])->toBe(0.0);
});
