<?php

declare(strict_types=1);

use Ikram\Rag\Storage\HnswSearch;

it('asks the index for four candidates per result, never fewer than the pgvector default', function (int $limit, int $expected): void {
    expect(HnswSearch::efSearchFor($limit))->toBe($expected);
})->with([
    'small limit keeps the default' => [5, 40],
    'exactly ten' => [10, 40],
    'above the default' => [11, 44],
    'the largest rerank pool' => [100, 400],
    'capped at pgvector\'s maximum' => [250, 1000],
    'far above the maximum' => [5000, 1000],
]);

it('uses iterative scans only from pgvector 0.8.0', function (?string $version, bool $expected): void {
    expect(HnswSearch::supportsIterativeScan($version))->toBe($expected);
})->with([
    ['0.8.0', true],
    ['0.8.7', true],
    ['0.10.0', true],
    ['1.0.0', true],
    ['0.7.4', false],
    ['0.5.1', false],
    [null, false],
    ['', false],
]);

it('never lowers an ef_search the app has tuned higher', function (int $limit, ?int $current, int $expected): void {
    expect(HnswSearch::efSearchFor($limit, $current))->toBe($expected);
})->with([
    'tuned above what the limit needs' => [5, 200, 200],
    'tuned above, large limit wins' => [100, 200, 400],
    'at the default' => [60, 40, 240],
    'tuned below the default' => [5, 10, 40],
    'unknown' => [60, null, 240],
]);
