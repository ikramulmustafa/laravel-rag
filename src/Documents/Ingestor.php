<?php

declare(strict_types=1);

namespace Ikram\Rag\Documents;

use Ikram\Rag\Contracts\Chunker;
use Ikram\Rag\Contracts\Embedder;
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
     * @param  callable(int, int): bool  $callback
     */
    public function confirmWith(callable $callback): self
    {
        $this->confirm = $callback;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function ingest(
        string $source,
        string $content,
        string $collection = 'default',
        array $metadata = [],
    ): IngestResult {
        $hash = Document::hashFor($content);

        $existing = Document::query()
            ->where('source', $source)
            ->where('collection', $collection)
            ->first();

        // Unchanged content is the single largest source of accidental embedding
        // spend. Re-ingesting a nightly export of 10k documents where nine have
        // changed should cost nine documents, not ten thousand.
        if ($existing !== null
            && $existing->content_hash === $hash
            && $this->modelMatchesExistingChunks($existing)) {
            return new IngestResult($source, 0, 0, skipped: true);
        }

        $chunks = $this->chunker->chunk($content);

        if ($chunks === []) {
            return new IngestResult($source, 0, 0, skipped: true);
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

        return $this->connection->transaction(function () use (
            $existing, $source, $collection, $hash, $metadata, $chunks, $vectors,
        ): IngestResult {
            $document = $existing ?? new Document;
            $document->source = $source;
            $document->collection = $collection;
            $document->content_hash = $hash;
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

    private function modelMatchesExistingChunks(Document $document): bool
    {
        $models = $document->chunks()->distinct()->pluck('embedding_model')->all();

        return $models === [] || $models === [$this->embedder->modelId()];
    }
}
