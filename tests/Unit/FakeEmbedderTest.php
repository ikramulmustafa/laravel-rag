<?php

declare(strict_types=1);

use Ikram\Rag\Embedding\FakeEmbedder;
use Ikram\Rag\Retrieval\VectorRetriever;

it('produces vectors of the configured width', function (): void {
    $vectors = (new FakeEmbedder(32))->embed(['hello world']);

    expect($vectors[0])->toHaveCount(32);
});

it('is deterministic across calls', function (): void {
    $embedder = new FakeEmbedder;

    expect($embedder->embed(['same text'])[0])
        ->toBe($embedder->embed(['same text'])[0]);
});

it('scores shared vocabulary higher than unrelated text', function (): void {
    $embedder = new FakeEmbedder;

    [$query, $related, $unrelated] = $embedder->embed([
        'refund policy for damaged goods',
        'our refund policy covers damaged goods returned within 30 days',
        'the quarterly engineering hiring plan for the platform team',
    ]);

    expect(VectorRetriever::cosine($query, $related))
        ->toBeGreaterThan(VectorRetriever::cosine($query, $unrelated));
});

it('declares itself fake so it cannot be mistaken for production', function (): void {
    expect((new FakeEmbedder)->modelId())->toStartWith('fake:');
});

it('returns a zero vector for text with no tokens', function (): void {
    expect((new FakeEmbedder(8))->embed(['!!! ???'])[0])->toBe(array_fill(0, 8, 0.0));
});
