<?php

declare(strict_types=1);

use Ikram\Rag\Retrieval\Citation;
use Ikram\Rag\Retrieval\RetrievalResult;

it('reports the highest score even when citations are not in score order', function (): void {
    $result = new RetrievalResult('q', [
        new Citation(1, 1, 'a.md', 'a', 0, 0.4),
        new Citation(2, 1, 'a.md', 'b', 1, 0.9),
        new Citation(3, 2, 'b.md', 'c', 0, 0.6),
    ], 'm');

    expect($result->topScore())->toBe(0.9);
});

it('reports a zero top score for an empty result', function (): void {
    expect((new RetrievalResult('q', [], 'm'))->topScore())->toBe(0.0);
});

it('serialises rerank_score only when the citation was reranked', function (): void {
    $plain = (new Citation(1, 1, 'a.md', 'a', 0, 0.5))->jsonSerialize();
    $reranked = (new Citation(1, 1, 'a.md', 'a', 0, 0.5))->withRerankScore(0.123456)->jsonSerialize();

    expect($plain)->not->toHaveKey('rerank_score')
        ->and($reranked['rerank_score'])->toBe(0.1235);
});
