<?php

declare(strict_types=1);

namespace Ikram\Rag\Console;

use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Documents\Document;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Embedding\EmbeddingModelId;
use Ikram\Rag\Storage\PgvectorColumn;
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
 *
 * On pgvector a change of width also needs the column changed, since `vector(N)`
 * takes nothing else: `--resize` does that first, then reindexes as `--stale`.
 */
final class ReindexCommand extends Command
{
    protected $signature = 'rag:reindex
        {--collection= : Limit to one collection}
        {--stale : Only documents with a chunk from another embedding model, or without an embedding}
        {--resize : On pgvector, change the vector column to the configured width first, then reindex as --stale}
        {--force : Skip confirmation}';

    protected $description = 'Re-embed stored documents with the current embedding model';

    public function handle(Ingestor $ingestor, Embedder $embedder): int
    {
        $current = $embedder->modelId();
        $staleOnly = (bool) $this->option('stale');
        $confirmed = (bool) $this->option('force');
        $resized = false;

        if ($this->option('resize')) {
            $outcome = $this->resize($embedder);

            if ($outcome === 'stop') {
                return self::FAILURE;
            }

            // After a resize every vector is NULL, so --stale selects the whole
            // corpus, and a run that fails part-way resumes where it stopped.
            $staleOnly = true;
            $resized = $outcome === 'resized';
            $confirmed = $confirmed || $resized;
        }

        $query = Document::query()
            ->when($this->option('collection'), fn ($q, $c) => $q->where('collection', $c))
            ->when($staleOnly, fn ($q) => $q->whereHas(
                'chunks',
                // Grouped so the OR cannot escape the document_id constraint.
                fn ($chunks) => $chunks->where(fn ($stale) => $stale
                    ->whereNotIn('embedding_model', EmbeddingModelId::accepted($current))
                    ->orWhereNull('embedding')),
            ));

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->components->warn($staleOnly ? 'No stale documents to reindex.' : 'No documents to reindex.');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf(
            'Re-embedding %d document(s) with %s. This calls the embedding API for every chunk.',
            $total,
            $current,
        ));

        if (! $confirmed && ! $this->confirm('Continue?', default: false)) {
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
            // After a resize there are no old vectors to fall back on.
            $this->line($resized
                ? '  <fg=gray>These have no vectors and are left out of search until they are re-embedded. Fix the cause, or re-ingest them, then run rag:reindex --stale.</>'
                : '  <fg=gray>These still hold their old vectors. Fix the cause, or re-ingest them, then run again with --stale.</>');

            return self::FAILURE;
        }

        $this->components->info('Reindex complete.');

        return self::SUCCESS;
    }

    /**
     * Bring the pgvector column to the embedder's width before reindexing.
     *
     * The target is the embedder's width rather than the raw config value: it is
     * the same for the built-in drivers, and it is what every new vector will be,
     * so the column can never be resized to a width the ingest then rejects.
     *
     * @return 'stop'|'unchanged'|'resized' stop: refused or declined, nothing changed
     */
    private function resize(Embedder $embedder): string
    {
        // The column is shared by every collection, and resizing it clears all of
        // their vectors, so reindexing only one would leave the rest unsearchable.
        if ($this->option('collection')) {
            $this->components->error('--resize changes the vector column for every collection, so it cannot be combined with --collection.');

            return 'stop';
        }

        $connection = (new Document)->getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            $this->components->info('Vector width is not fixed on this database; running --stale.');

            return 'unchanged';
        }

        $type = PgvectorColumn::currentType($connection);

        if ($type === null) {
            $this->components->error('There is no rag_chunks.embedding column to resize. Run php artisan migrate first.');

            return 'stop';
        }

        // Resizing replaces the column, so anything other than a vector column
        // (say one converted to halfvec by hand) would lose every value it holds.
        if (! PgvectorColumn::isVector($type)) {
            $this->components->error("rag_chunks.embedding is {$type}, not a pgvector vector column. --resize only changes vector columns, so nothing was changed.");

            return 'stop';
        }

        $target = $embedder->dimensions();
        $width = PgvectorColumn::parseWidth($type);

        if ($width === $target) {
            $this->components->info("The vector column is already {$target} dimensions wide; running --stale.");

            return 'unchanged';
        }

        $change = sprintf('the vector column%s to %d dimensions', $width === null ? '' : " from {$width}", $target);
        $impact = sprintf(
            'This clears every stored vector: search returns no results for the %d document(s) until re-embedding finishes.',
            Document::query()->count(),
        );

        if ($this->option('force')) {
            $this->components->warn("Resizing {$change}. {$impact}");
        } elseif (! $this->confirm("Resize {$change}? {$impact}", default: false)) {
            return 'stop';
        }

        if (! PgvectorColumn::resize($connection, $target)) {
            $this->components->warn(sprintf(
                'pgvector cannot index more than %d dimensions, so the column is left unindexed and every search scans it in full. '
                .'A smaller RAG_EMBEDDING_DIMENSIONS keeps the index, e.g. text-embedding-3-large at 1536.',
                PgvectorColumn::MAX_INDEXED_DIMENSIONS,
            ));
        }

        return 'resized';
    }
}
