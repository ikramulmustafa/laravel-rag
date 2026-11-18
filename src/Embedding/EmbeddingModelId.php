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
     *
     * A stored id only counts as legacy when it has no width suffix of its own.
     * `drv:foo:768` is the full id of `foo` at 768, not a legacy id of a model
     * named `foo:768`, so it never matches `drv:foo:768:1536`.
     */
    public static function matches(string $stored, string $current): bool
    {
        return $stored === $current
            || (self::legacyForm($stored) === null && $stored === self::legacyForm($current));
    }

    /**
     * Every stored id that matches `$current`, for a `WHERE ... IN` that has to
     * agree with matches().
     *
     * @return list<string>
     */
    public static function accepted(string $current): array
    {
        $legacy = self::legacyForm($current);

        return $legacy !== null && self::matches($legacy, $current) ? [$current, $legacy] : [$current];
    }
}
