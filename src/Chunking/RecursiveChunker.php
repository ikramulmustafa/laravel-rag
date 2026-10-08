<?php

declare(strict_types=1);

namespace Ikram\Rag\Chunking;

use Ikram\Rag\Contracts\Chunker;
use InvalidArgumentException;

/**
 * Splits on the largest natural boundary that fits, falling back to smaller ones:
 * paragraphs, then lines, then sentences, then hard character cuts.
 *
 * Splitting mid-sentence is the most common cause of retrieval that looks fine in
 * aggregate but returns unusable fragments for specific queries, so the fallback
 * order matters more than the chunk size does.
 */
final class RecursiveChunker implements Chunker
{
    /** @var list<string> */
    private array $separators = ["\n\n", "\n", '. ', ' '];

    public function __construct(
        private readonly int $size = 1000,
        private readonly int $overlap = 200,
    ) {
        if ($overlap >= $size) {
            throw new InvalidArgumentException(
                "Chunk overlap ({$overlap}) must be smaller than chunk size ({$size}); "
                .'equal or greater values make the splitter loop forever.'
            );
        }

        if ($size < 1) {
            throw new InvalidArgumentException('Chunk size must be at least 1.');
        }
    }

    public function chunk(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $chunks = [];
        $cursor = 0;
        $index = 0;
        $total = mb_strlen($text);

        while ($cursor < $total) {
            $end = min($cursor + $this->size, $total);

            if ($end < $total) {
                $end = $this->findBoundary($text, $cursor, $end);
            }

            $slice = trim(mb_substr($text, $cursor, $end - $cursor));

            if ($slice !== '') {
                $chunks[] = new TextChunk($slice, $index++, $cursor, $end);
            }

            // This chunk reached the end of the text. Stepping back by the overlap from
            // here would only re-emit the tail this chunk already holds, as a duplicate.
            if ($end >= $total) {
                break;
            }

            $next = $end - $this->overlap;

            // Guard against pathological inputs where the boundary search returns
            // a position that would leave the cursor stationary.
            $cursor = $next > $cursor ? $next : $end;
        }

        return $chunks;
    }

    /**
     * Walk the separator list from largest to smallest, returning the first
     * boundary found within the window. Falls back to a hard cut.
     */
    private function findBoundary(string $text, int $start, int $end): int
    {
        $window = mb_substr($text, $start, $end - $start);
        $floor = (int) ($this->size * 0.5);

        foreach ($this->separators as $separator) {
            $position = mb_strrpos($window, $separator);

            if ($position !== false && $position > $floor) {
                return $start + $position + mb_strlen($separator);
            }
        }

        return $end;
    }
}
