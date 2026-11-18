<?php

declare(strict_types=1);

namespace Ikram\Rag\Documents;

use Ikram\Rag\Contracts\Chunker;
use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Embedding\EmbeddingModelId;
use Ikram\Rag\Exceptions\EmbeddingFailed;
use Ikram\Rag\Exceptions\IngestionAborted;
use Ikram\Rag\Retrieval\Vector;
use Illuminate\Database\Connection;

final class Ingestor
{
    /** @var null|callable(int, int): bool */
    private $confirm = null;

    public function __construct(
        private readonly Embedder $embedder,
        private readonly Chunker $chunker,
        private readonly Connection $connection,
        private readonly int $tokenWarningThreshold = 100_000,
    ) {}

    /**
     * Called with (estimatedTokens, chunkCount) before anything is sent to the
     * embedding API. Return false to abort. The console command wires this to a
     * prompt; leaving it unset means non-interactive callers proceed unimpeded.
     *
     * Returns a clone. The container shares one Ingestor, so mutating it would let
     * one command's prompt silently gate every later ingest in the same process.
     *
     * @param  callable(int, int): bool  $callback
     */
    public function confirmWith(callable $callback): self
    {
        $clone = clone $this;
        $clone->confirm = $callback;

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function ingest(
        string $source,
        string $content,
        string $collection = 'default',
        array $metadata = [],
        bool $force = false,
    ): IngestResult {
        $hash = Document::hashFor($content);

        $existing = Document::query()
            ->where('source', $source)
            ->where('collection', $collection)
            ->first();

        // Unchanged content is the single largest source of accidental embedding
        // spend. Re-ingesting a nightly export of 10k documents where nine have
        // changed should cost nine documents, not ten thousand.
        if (! $force
            && $existing !== null
            && $existing->content_hash === $hash
            && $this->modelMatchesExistingChunks($existing)) {
            // Documents ingested before the text was stored get it for free here,
            // so a later reindex can rebuild them without the original file.
            if ($existing->content === null) {
                $existing->content = $content;
                $existing->save();
            }

            return new IngestResult($source, 0, $existing->id, skipped: true);
        }

        $chunks = $this->chunker->chunk($content);

        if ($chunks === []) {
            if ($existing === null) {
                return new IngestResult($source, 0, 0, skipped: true);
            }

            // The document was emptied. Leaving its old chunks would keep
            // retrieving text the source no longer contains.
            return $this->connection->transaction(function () use ($existing, $source, $hash, $content, $metadata): IngestResult {
                $existing->content_hash = $hash;
                $existing->content = $content;
                $existing->metadata = $metadata;
                $existing->save();
                $existing->chunks()->delete();

                return new IngestResult($source, 0, $existing->id, skipped: true);
            });
        }

        $texts = array_map(static fn ($c): string => $c->text, $chunks);
        $tokens = $this->embedder->estimateTokens($texts);

        if ($tokens > $this->tokenWarningThreshold && $this->confirm !== null) {
            if (! ($this->confirm)($tokens, count($chunks))) {
                throw new IngestionAborted(
                    "Ingestion of '{$source}' aborted before embedding (~{$tokens} tokens)."
                );
            }
        }

        $vectors = $this->embedder->embed($texts);
        $this->guardVectors($source, $vectors, count($chunks));

        return $this->connection->transaction(function () use (
            $existing, $source, $collection, $hash, $content, $metadata, $chunks, $vectors,
        ): IngestResult {
            $document = $existing ?? new Document;
            $document->source = $source;
            $document->collection = $collection;
            $document->content_hash = $hash;
            $document->content = $content;
            $document->metadata = $metadata;
            $document->save();

            // Replace rather than merge. Partial updates leave orphaned chunks from
            // the previous revision, which then surface in retrieval as text that no
            // longer exists in the source document.
            $document->chunks()->delete();

            $usePgvector = $this->connection->getDriverName() === 'pgsql';
            $model = $this->embedder->modelId();
            $now = now();

            foreach ($chunks as $i => $chunk) {
                $vector = $vectors[$i];

                $row = [
                    'document_id' => $document->id,
                    'text' => $chunk->text,
                    'position' => $chunk->index,
                    'embedding_model' => $model,
                    'metadata' => json_encode($metadata),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($usePgvector) {
                    $id = $this->connection->table('rag_chunks')->insertGetId($row);

                    $this->connection->statement(
                        'UPDATE rag_chunks SET embedding = ?::vector WHERE id = ?',
                        [Vector::toLiteral($vector), $id],
                    );
                } else {
                    $row['embedding'] = json_encode($vector);
                    $this->connection->table('rag_chunks')->insert($row);
                }
            }

            return new IngestResult($source, count($chunks), $document->id, skipped: false);
        });
    }

    /**
     * The embedder's output is checked before anything is written. A short batch
     * would pair chunks with the wrong vectors, or none, and a vector of the wrong
     * width is stored happily as JSON and only fails, or worse ranks, at search time.
     *
     * @param  array<int, mixed>  $vectors
     */
    private function guardVectors(string $source, array $vectors, int $expectedCount): void
    {
        if (! array_is_list($vectors) || count($vectors) !== $expectedCount) {
            throw new EmbeddingFailed(sprintf(
                "The embedder returned %d vectors for %d chunks of '%s'. Nothing was stored.",
                count($vectors),
                $expectedCount,
                $source,
            ));
        }

        $expectedWidth = $this->embedder->dimensions();

        foreach ($vectors as $vector) {
            $width = is_array($vector) ? count($vector) : 0;

            if ($width !== $expectedWidth) {
                throw new EmbeddingFailed(sprintf(
                    "The embedder returned a vector of %d dimensions for '%s' but is configured for %d. "
                    .'Nothing was stored. Check rag.embedding.dimensions; if it changed, run `php artisan rag:reindex`.',
                    $width,
                    $source,
                    $expectedWidth,
                ));
            }
        }
    }

    /**
     * Legacy ids written by 0.2 count as the current model (see EmbeddingModelId),
     * so upgrading does not re-embed an unchanged corpus.
     */
    private function modelMatchesExistingChunks(Document $document): bool
    {
        $current = $this->embedder->modelId();

        foreach ($document->chunks()->distinct()->pluck('embedding_model') as $stored) {
            if (! EmbeddingModelId::matches((string) $stored, $current)) {
                return false;
            }
        }

        return true;
    }
}
