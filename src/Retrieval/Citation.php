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
        public readonly ?float $rerankScore = null,
    ) {}

    /** The same citation, carrying the score a reranker gave it. `score` stays the vector similarity. */
    public function withRerankScore(float $score): self
    {
        return new self($this->chunkId, $this->documentId, $this->source, $this->text, $this->position, $this->score, $score);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = [
            'chunk_id' => $this->chunkId,
            'document_id' => $this->documentId,
            'source' => $this->source,
            'position' => $this->position,
            'score' => round($this->score, 4),
            'text' => $this->text,
        ];

        if ($this->rerankScore !== null) {
            $data['rerank_score'] = round($this->rerankScore, 4);
        }

        return $data;
    }
}
