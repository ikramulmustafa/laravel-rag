<?php

declare(strict_types=1);

namespace Ikram\Rag\Console;

use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Documents\Document;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Embedding\EmbeddingModelId;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Rebuilds chunks against the currently configured embedder.
 *
 * Needed after any change to the embedding model or dimensionality, because the
 * retriever refuses to search a corpus whose vectors came from a different model.
 * That refusal is the point: this command is the sanctioned way out of it.
 *
 * Text comes from the stored copy first, so content that was ingested from a
 * database row (no file behind it) reindexes like any other. The file at `source`
 * is only the fallback for rows ingested before 0.3 stored the text.
 */
final class ReindexCommand extends Command
{
    protected $signature = 'rag:reindex
        {--collection= : Limit to one collection}
        {--stale : Only documents with a chunk from another embedding model, or without an embedding}
        {--force : Skip confirmation}';

    protected $description = 'Re-embed stored documents with the current embedding model';

    public function handle(Ingestor $ingestor, Embedder $embedder): int
    {
        $current = $embedder->modelId();

        $query = Document::query()
            ->when($this->option('collection'), fn ($q, $c) => $q->where('collection', $c))
            ->when($this->option('stale'), fn ($q) => $q->whereHas(
                'chunks',
                // Grouped so the OR cannot escape the document_id constraint.
                fn ($chunks) => $chunks->where(fn ($stale) => $stale
                    ->whereNotIn('embedding_model', EmbeddingModelId::accepted($current))
                    ->orWhereNull('embedding')),
            ));

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->components->warn($this->option('stale') ? 'No stale documents to reindex.' : 'No documents to reindex.');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf(
            'Re-embedding %d document(s) with %s. This calls the embedding API for every chunk.',
            $total,
            $current,
        ));

        if (! $this->option('force') && ! $this->confirm('Continue?', default: false)) {
            return self::FAILURE;
        }

        /** @var list<string> $failed one line per document: collection, source, reason */
        $failed = [];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(100, function ($documents) use ($ingestor, &$failed, $bar): void {
            foreach ($documents as $document) {
                try {
                    $content = $document->content ?? (is_readable($document->source)
                        ? file_get_contents($document->source)
                        : false);

                    if (! is_string($content)) {
                        throw new RuntimeException('source text not stored and the file is not readable');
                    }

                    // force rebuilds even when the text is unchanged, which is
                    // exactly the situation reindex exists to fix. The hash is
                    // only rewritten by a successful ingest, so a failure here
                    // leaves the document as it was.
                    $ingestor->ingest(
                        source: $document->source,
                        content: $content,
                        collection: $document->collection,
                        metadata: $document->metadata ?? [],
                        force: true,
                    );
                } catch (Throwable $e) {
                    // A list, not keyed by source: the same source can exist in two collections.
                    $failed[] = "{$document->collection}/{$document->source} — ".class_basename($e).': '.$e->getMessage();
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($failed !== []) {
            $this->components->error(count($failed).' document(s) could not be reindexed:');

            foreach ($failed as $line) {
                $this->line("  <fg=red>✗</> {$line}");
            }

            $this->line('');
            $this->line('  <fg=gray>These still hold their old vectors. Fix the cause, or re-ingest them, then run again with --stale.</>');

            return self::FAILURE;
        }

        $this->components->info('Reindex complete.');

        return self::SUCCESS;
    }
}
