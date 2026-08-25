<?php

declare(strict_types=1);

namespace Ikram\Rag\Console;

use Ikram\Rag\Documents\Ingestor;
use Illuminate\Console\Command;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

final class IngestCommand extends Command
{
    private const DEFAULT_PATTERN = '*.md';

    /**
     * `--pattern` deliberately declares no default here. Laravel's signature parser
     * reads `=*` as "this option accepts an array" before it reads `=` as "this option
     * has a default", so `{--pattern=*.md}` silently becomes an array option whose
     * default is `.md`. The default lives in DEFAULT_PATTERN instead.
     */
    protected $signature = 'rag:ingest
        {path : File or directory to ingest}
        {--collection=default : Logical grouping, filterable at retrieval time}
        {--pattern= : Glob pattern when path is a directory (default: *.md)}
        {--force : Skip the spend confirmation prompt}';

    protected $description = 'Chunk, embed and store documents for retrieval';

    public function handle(Ingestor $ingestor): int
    {
        $path = (string) $this->argument('path');

        if (! file_exists($path)) {
            $this->components->error("Path does not exist: {$path}");

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $ingestor->confirmWith(function (int $tokens, int $chunks): bool {
                $this->newLine();
                $this->components->warn(sprintf(
                    'About to embed %s chunks, roughly %s tokens.',
                    number_format($chunks),
                    number_format($tokens),
                ));

                return $this->confirm('Continue?', default: false);
            });
        }

        $files = $this->resolveFiles($path);

        if ($files === []) {
            $this->components->warn('No matching files found.');

            return self::SUCCESS;
        }

        $ingested = 0;
        $skipped = 0;
        $chunks = 0;

        $this->withProgressBar($files, function (SplFileInfo $file) use (
            $ingestor, &$ingested, &$skipped, &$chunks,
        ): void {
            $result = $ingestor->ingest(
                source: self::normalisePath($file->getPathname()),
                content: (string) file_get_contents($file->getPathname()),
                collection: (string) $this->option('collection'),
                metadata: ['filename' => $file->getFilename()],
            );

            $result->skipped ? $skipped++ : $ingested++;
            $chunks += $result->chunkCount;
        });

        $this->newLine(2);
        $this->components->info(sprintf(
            '%d ingested (%d chunks), %d unchanged and skipped.',
            $ingested,
            $chunks,
            $skipped,
        ));

        if ($ingested > 0) {
            $this->line('  <fg=gray>Next: `php artisan rag:eval` to check retrieval still holds up.</>');
        }

        return self::SUCCESS;
    }

    /**
     * A stored source is an identifier, not a filesystem path, and eval suites match
     * against it by exact string. Left as-is, a corpus ingested on Windows records
     * `docs\policy.md` while the suite committed alongside it says `docs/policy.md`,
     * so every expected source misses and recall reads 0.000 with no error anywhere —
     * the same silent-wrongness this package exists to catch, in its own tooling.
     */
    private static function normalisePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /** @return list<SplFileInfo> */
    private function resolveFiles(string $path): array
    {
        if (is_file($path)) {
            return [new SplFileInfo($path)];
        }

        $pattern = (string) ($this->option('pattern') ?: self::DEFAULT_PATTERN);

        $finder = (new Finder)
            ->files()
            ->in($path)
            ->name($pattern);

        return iterator_to_array($finder, false);
    }
}
