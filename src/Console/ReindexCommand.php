<?php

declare(strict_types=1);

namespace Ikram\Rag\Console;

use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Documents\Document;
use Ikram\Rag\Documents\Ingestor;
use Illuminate\Console\Command;

/**
 * Rebuilds every chunk against the currently configured embedder.
 *
 * Needed after any change to the embedding model or dimensionality, because the
 * retriever refuses to search a corpus whose vectors came from a different model.
 * That refusal is the point: this command is the sanctioned way out of it.
 */
final class ReindexCommand extends Command
{
    protected $signature = 'rag:reindex
        {--collection= : Limit to one collection}
        {--force : Skip confirmation}';

    protected $description = 'Re-embed all stored documents with the current embedding model';

    public function handle(Ingestor $ingestor, Embedder $embedder): int
    {
        $query = Document::query()
            ->when($this->option('collection'), fn ($q, $c) => $q->where('collection', $c));

        $total = $query->count();

        if ($total === 0) {
            $this->components->warn('No documents to reindex.');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf(
            'Re-embedding %d document(s) with %s. This calls the embedding API for every chunk.',
            $total,
            $embedder->modelId(),
        ));

        if (! $this->option('force') && ! $this->confirm('Continue?', default: false)) {
            return self::FAILURE;
        }

        $failed = [];

        $this->withProgressBar($query->cursor(), function (Document $document) use ($ingestor, &$failed): void {
            $content = is_readable($document->source)
                ? (string) file_get_contents($document->source)
                : null;

            if ($content === null) {
                $failed[] = $document->source;

                return;
            }

            // Clearing the hash forces a rebuild; otherwise unchanged content
            // short-circuits and the old vectors survive, which is the exact
            // situation reindex exists to fix.
            $document->update(['content_hash' => '']);

            $ingestor->ingest(
                source: $document->source,
                content: $content,
                collection: $document->collection,
                metadata: $document->metadata ?? [],
            );
        });

        $this->newLine(2);

        if ($failed !== []) {
            $this->components->error(count($failed).' source(s) could not be re-read:');

            foreach ($failed as $source) {
                $this->line("  <fg=red>✗</> {$source}");
            }

            $this->line('');
            $this->line('  <fg=gray>These still hold stale vectors. Re-ingest them from a readable path.</>');

            return self::FAILURE;
        }

        $this->components->info('Reindex complete.');

        return self::SUCCESS;
    }
}
