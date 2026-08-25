<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Chunker;
use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Documents\Chunk;
use Ikram\Rag\Documents\Document;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Exceptions\IngestionAborted;

it('stores a document with its chunks', function (): void {
    $result = app(Ingestor::class)->ingest('policies/refunds.md', str_repeat('refund policy text. ', 60));

    expect($result->skipped)->toBeFalse()
        ->and($result->chunkCount)->toBeGreaterThan(0)
        ->and(Document::count())->toBe(1)
        ->and(Chunk::count())->toBe($result->chunkCount);
});

it('skips re-embedding when content has not changed', function (): void {
    $ingestor = app(Ingestor::class);
    $content = 'Stable content that will not change between runs.';

    $ingestor->ingest('doc.md', $content);
    $second = $ingestor->ingest('doc.md', $content);

    expect($second->skipped)->toBeTrue()
        ->and(Document::count())->toBe(1);
});

it('replaces old chunks instead of orphaning them when content changes', function (): void {
    $ingestor = app(Ingestor::class);

    $ingestor->ingest('doc.md', str_repeat('original wording here. ', 40));
    $before = Chunk::count();

    $ingestor->ingest('doc.md', 'completely different and much shorter');

    expect(Document::count())->toBe(1)
        ->and(Chunk::count())->toBeLessThan($before)
        ->and(Chunk::where('text', 'like', '%original wording%')->count())->toBe(0);
});

it('records which model produced each vector', function (): void {
    app(Ingestor::class)->ingest('doc.md', 'some content worth embedding');

    expect(Chunk::first()->embedding_model)->toBe('fake:deterministic-hash');
});

it('aborts before spending when the confirmation callback declines', function (): void {
    $ingestor = (new Ingestor(
        embedder: app(Embedder::class),
        chunker: app(Chunker::class),
        connection: app('db')->connection(),
        tokenWarningThreshold: 1,
    ))->confirmWith(fn (): bool => false);

    expect(fn () => $ingestor->ingest('big.md', str_repeat('expensive text ', 500)))
        ->toThrow(IngestionAborted::class);

    expect(Document::count())->toBe(0);
});

it('keeps documents in separate collections apart', function (): void {
    $ingestor = app(Ingestor::class);

    $ingestor->ingest('shared-name.md', 'content A', 'policies');
    $ingestor->ingest('shared-name.md', 'content B', 'handbook');

    expect(Document::count())->toBe(2);
});
