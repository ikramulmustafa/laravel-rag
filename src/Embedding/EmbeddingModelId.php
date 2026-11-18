<?php

declare(strict_types=1);

namespace Ikram\Rag\Embedding;

/**
 * The one place that knows what an embedding model id looks like and when two of
 * them describe the same vectors.
 *
 * Ids are `{driver}:{model}:{dimensions}`. 0.2 wrote them without the width, and
 * those rows are matched on read rather than rewritten: an UPDATE of every chunk
 * on pgvector re-inserts the whole table into the HNSW index under a long lock.
 * A legacy id is only ever accepted against the full id it is a prefix of, and
 * stored widths are still checked on every search, so this never lets vectors of
 * another width through.
 *
 * Framework-free on purpose: the standalone verifier loads it.
 */
final class EmbeddingModelId
{
    public static function make(string $driver, string $model, int $dimensions): string
    {
        return "{$driver}:{$model}:{$dimensions}";
    }

    /**
     * The id as 0.2 wrote it, without the trailing `:<width>`, or null when the id
     * carries no width to strip.
     */
    public static function legacyForm(string $id): ?string
    {
        if (preg_match('/^(.+):\d+$/', $id, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * True when vectors stored under `$stored` can be searched with the embedder
     * identified by `$current`. Deliberately one-directional: a full stored id is
     * never matched against a suffix-less current one, because then nothing says
     * the stored width is the one in use.
     */
    public static function matches(string $stored, string $current): bool
    {
        return $stored === $current || $stored === self::legacyForm($current);
    }
}
