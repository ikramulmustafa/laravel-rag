<?php

declare(strict_types=1);

namespace Ikram\Rag\Documents;

final class IngestResult
{
    public function __construct(
        public readonly string $source,
        public readonly int $chunkCount,
        public readonly int $documentId,
        public readonly bool $skipped,
        // True when nothing was written because the stored document no longer
        // matched the expected hash: a newer revision, or a deletion, won.
        public readonly bool $superseded = false,
    ) {}
}
