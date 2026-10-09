<?php

declare(strict_types=1);

use Ikram\Rag\Chunking\RecursiveChunker;

it('returns nothing for empty input', function (): void {
    expect((new RecursiveChunker)->chunk('   '))->toBe([]);
});

it('keeps short text as a single chunk', function (): void {
    $chunks = (new RecursiveChunker(size: 1000))->chunk('A short document.');

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->text)->toBe('A short document.')
        ->and($chunks[0]->index)->toBe(0);
});

it('keeps text longer than the overlap but shorter than the size as a single chunk', function (): void {
    $text = trim(str_repeat('Lorem ipsum dolor sit amet. ', 10));

    $chunks = (new RecursiveChunker(size: 1000, overlap: 200))->chunk($text);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->text)->toBe($text);
});

it('never emits a final chunk that only repeats the previous chunk\'s tail', function (): void {
    $chunks = (new RecursiveChunker(size: 100, overlap: 20))->chunk(str_repeat('word ', 200));
    $last = end($chunks);

    expect($last->endOffset)->toBe(mb_strlen(trim(str_repeat('word ', 200))));

    foreach (array_slice($chunks, 1) as $i => $chunk) {
        expect($chunk->startOffset)->toBeLessThan($chunks[$i]->endOffset)
            ->and($chunk->endOffset)->toBeGreaterThan($chunks[$i]->endOffset);
    }
});

it('prefers paragraph boundaries over hard cuts', function (): void {
    $text = str_repeat('alpha ', 20)."\n\n".str_repeat('beta ', 20);
    $chunks = (new RecursiveChunker(size: 130, overlap: 20))->chunk($text);

    expect(count($chunks))->toBeGreaterThan(1)
        ->and($chunks[0]->text)->not->toContain('beta');
});

it('rejects overlap greater than or equal to size', function (): void {
    expect(fn () => new RecursiveChunker(size: 100, overlap: 100))
        ->toThrow(InvalidArgumentException::class, 'must be smaller than chunk size');
});

it('always advances so pathological input cannot loop forever', function (): void {
    $chunks = (new RecursiveChunker(size: 50, overlap: 49))->chunk(str_repeat('x', 5000));

    expect(count($chunks))->toBeLessThan(5000)
        ->and(count($chunks))->toBeGreaterThan(0);
});

it('numbers chunks sequentially from zero', function (): void {
    $chunks = (new RecursiveChunker(size: 100, overlap: 10))->chunk(str_repeat('word ', 200));

    expect(array_map(fn ($c) => $c->index, $chunks))
        ->toBe(range(0, count($chunks) - 1));
});
