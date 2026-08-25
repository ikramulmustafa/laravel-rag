<?php

declare(strict_types=1);

namespace Ikram\Rag\Retrieval;

use JsonSerializable;

final class Citation implements JsonSerializable
{
    public function __construct(
        public readonly int $chunkId,
        public readonly int $documentId,
        public readonly string $source,
        public readonly string $text,
        public readonly int $position,
        public readonly float $score,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'chunk_id' => $this->chunkId,
            'document_id' => $this->documentId,
            'source' => $this->source,
            'position' => $this->position,
            'score' => round($this->score, 4),
            'text' => $this->text,
        ];
    }
}
