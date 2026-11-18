<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Chunker;
use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Documents\Chunk;
use Ikram\Rag\Documents\Document;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Embedding\FakeEmbedder;
use Ikram\Rag\Exceptions\EmbeddingFailed;
use Ikram\Rag\Exceptions\IngestionAborted;
use Ikram\Rag\Facades\Rag;
use Ikram\Rag\RagManager;

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

    expect(Chunk::first()->embedding_model)->toBe('fake:deterministic-hash:64');
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

/**
 * An embedder that reports the configured width but hands back whatever the
 * test asks for, to prove the Ingestor checks what it is given.
 *
 * @param  callable(list<string>): list<list<float>>  $embed
 */
function misbehavingEmbedder(callable $embed): Embedder
{
    return new class($embed) implements Embedder
    {
        /** @var callable(list<string>): list<list<float>> */
        private $embed;

        public function __construct(callable $embed)
        {
            $this->embed = $embed;
        }

        public function embed(array $texts): array
        {
            return ($this->embed)($texts);
        }

        public function modelId(): string
        {
            return 'fake:deterministic-hash:64';
        }

        public function dimensions(): int
        {
            return 64;
        }

        public function estimateTokens(array $texts): int
        {
            return 0;
        }
    };
}

function useIngestionEmbedder(Embedder $embedder): void
{
    app()->instance(Embedder::class, $embedder);
    app()->forgetInstance(Ingestor::class);
    app()->forgetInstance('rag');
    app()->forgetInstance(RagManager::class);
    Rag::clearResolvedInstance('rag');
}

it('refuses an embedder that returns the wrong number of vectors', function (): void {
    useIngestionEmbedder(misbehavingEmbedder(
        fn (array $texts): array => array_slice((new FakeEmbedder(64))->embed($texts), 1),
    ));

    expect(fn () => Rag::ingest('doc.md', 'some content worth embedding'))
        ->toThrow(EmbeddingFailed::class, 'vectors');

    expect(Document::count())->toBe(0)
        ->and(Chunk::count())->toBe(0);
});

it('refuses an embedder that returns vectors of the wrong width', function (): void {
    useIngestionEmbedder(misbehavingEmbedder(
        fn (array $texts): array => (new FakeEmbedder(32))->embed($texts),
    ));

    expect(fn () => Rag::ingest('doc.md', 'some content worth embedding'))
        ->toThrow(EmbeddingFailed::class, '32');

    expect(Document::count())->toBe(0)
        ->and(Chunk::count())->toBe(0);
});

it('skips unchanged content whose chunks carry the legacy id of the current model', function (): void {
    $ingestor = app(Ingestor::class);
    $content = 'Stable content embedded before ids carried a width.';

    $ingestor->ingest('doc.md', $content);
    Chunk::query()->update(['embedding_model' => 'fake:deterministic-hash']);

    expect($ingestor->ingest('doc.md', $content)->skipped)->toBeTrue()
        ->and(Chunk::first()->embedding_model)->toBe('fake:deterministic-hash');
});

it('stores the source text on the document', function (): void {
    $text = 'The exact source text, kept so a reindex needs no original file.';

    app(Ingestor::class)->ingest('doc.md', $text);

    expect(Document::first()->content)->toBe($text);
});

it('backfills content when an unchanged legacy document is skipped', function (): void {
    $spy = useSpyEmbedder();
    $text = 'Legacy document ingested before content was stored.';

    Rag::ingest('doc.md', $text);
    Document::query()->update(['content' => null]);
    $before = $spy->calls;

    $result = Rag::ingest('doc.md', $text);

    expect($result->skipped)->toBeTrue()
        ->and(Document::first()->content)->toBe($text)
        ->and($spy->calls)->toBe($before);
});

it('re-embeds unchanged content when forced', function (): void {
    $ingestor = app(Ingestor::class);
    $text = 'Content that is unchanged but must be embedded again.';

    $ingestor->ingest('doc.md', $text);
    $second = $ingestor->ingest('doc.md', $text, force: true);

    expect($second->skipped)->toBeFalse()
        ->and($second->chunkCount)->toBeGreaterThan(0);
});

it('removes stale chunks when content becomes empty', function (): void {
    $ingestor = app(Ingestor::class);

    $first = $ingestor->ingest('doc.md', str_repeat('some words to chunk. ', 30));
    $second = $ingestor->ingest('doc.md', '');

    expect($second->skipped)->toBeTrue()
        ->and($second->documentId)->toBe($first->documentId)
        ->and(Chunk::count())->toBe(0)
        ->and(Document::first()->content_hash)->toBe(Document::hashFor(''))
        ->and(Document::first()->content)->toBe('');
});

it('writes nothing for empty content of an unknown document', function (): void {
    $result = app(Ingestor::class)->ingest('new.md', '');

    expect($result->skipped)->toBeTrue()
        ->and(Document::count())->toBe(0);
});

it('returns the existing document id when skipping', function (): void {
    $ingestor = app(Ingestor::class);

    $first = $ingestor->ingest('doc.md', 'Same text both times.');
    $second = $ingestor->ingest('doc.md', 'Same text both times.');

    expect($second->skipped)->toBeTrue()
        ->and($second->documentId)->toBe($first->documentId);
});

it('does not keep a confirm callback on the shared ingestor', function (): void {
    config()->set('rag.ingestion.token_warning_threshold', 1);
    app()->forgetInstance(Ingestor::class);
    app()->forgetInstance('rag');
    app()->forgetInstance(RagManager::class);
    Rag::clearResolvedInstance('rag');

    $confirming = app(Ingestor::class)->confirmWith(fn (): bool => false);

    expect($confirming)->not->toBe(app(Ingestor::class))
        ->and(Rag::ingest('big.md', str_repeat('plenty of words to embed. ', 200))->skipped)->toBeFalse()
        ->and(Document::count())->toBe(1);
});
