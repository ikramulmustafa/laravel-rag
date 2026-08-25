<?php

declare(strict_types=1);

namespace Ikram\Rag\Contracts;

use Ikram\Rag\Chunking\TextChunk;

interface Chunker
{
    /**
     * Split raw text into overlapping chunks suitable for embedding.
     *
     * @return list<TextChunk>
     */
    public function chunk(string $text): array;
}
