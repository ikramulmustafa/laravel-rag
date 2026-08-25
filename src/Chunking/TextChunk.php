<?php

declare(strict_types=1);

namespace Ikram\Rag\Chunking;

final class TextChunk
{
    public function __construct(
        public readonly string $text,
        public readonly int $index,
        public readonly int $startOffset,
        public readonly int $endOffset,
    ) {}

    public function length(): int
    {
        return mb_strlen($this->text);
    }
}
