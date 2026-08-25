<?php

declare(strict_types=1);

use Ikram\Rag\Retrieval\VectorRetriever;

it('returns 1 for identical vectors', function (): void {
    expect(VectorRetriever::cosine([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]))
        ->toEqualWithDelta(1.0, 0.0001);
});

it('returns 0 for orthogonal vectors', function (): void {
    expect(VectorRetriever::cosine([1.0, 0.0], [0.0, 1.0]))
        ->toEqualWithDelta(0.0, 0.0001);
});

it('returns 0 rather than dividing by zero', function (): void {
    expect(VectorRetriever::cosine([0.0, 0.0], [1.0, 1.0]))->toBe(0.0);
});

it('is unaffected by magnitude', function (): void {
    expect(VectorRetriever::cosine([1.0, 1.0], [5.0, 5.0]))
        ->toEqualWithDelta(1.0, 0.0001);
});
