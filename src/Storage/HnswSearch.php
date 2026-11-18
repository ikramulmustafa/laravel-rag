<?php

declare(strict_types=1);

namespace Ikram\Rag\Storage;

/**
 * Settings for a search through pgvector's HNSW index.
 *
 * An HNSW scan returns at most `hnsw.ef_search` rows, 40 by default, and WHERE
 * conditions are applied after it. Left alone, a limit above 40 comes back short,
 * as does the reranker's candidate pool, and a filter on a collection that is 5%
 * of the corpus gets about 2 of those 40 rows. The retriever raises ef_search
 * for each search and, on pgvector 0.8 and later, turns on iterative scans for
 * filtered ones, so the index keeps going until the limit is filled.
 */
final class HnswSearch
{
    /** pgvector's own default, kept as the floor so small searches cost nothing extra. */
    public const DEFAULT_EF_SEARCH = 40;

    /** The largest value pgvector accepts for hnsw.ef_search. */
    public const MAX_EF_SEARCH = 1000;

    /** Candidates asked of the index per result wanted. */
    public const CANDIDATES_PER_RESULT = 4;

    /** The first pgvector release with `hnsw.iterative_scan`; older ones reject the setting. */
    public const ITERATIVE_SCAN_SINCE = '0.8.0';

    /**
     * max(current, min(1000, max(40, limit × 4))): four candidates per result
     * leaves room for the approximate search to miss a few and still fill the
     * limit, and a value the app has already tuned higher is never lowered.
     *
     * @param  int|null  $current  The session's hnsw.ef_search, or null when unknown.
     */
    public static function efSearchFor(int $limit, ?int $current = null): int
    {
        $wanted = min(self::MAX_EF_SEARCH, max(self::DEFAULT_EF_SEARCH, $limit * self::CANDIDATES_PER_RESULT));

        return max($current ?? 0, $wanted);
    }

    /** Whether this `pg_extension.extversion` of `vector` has iterative index scans. */
    public static function supportsIterativeScan(?string $extversion): bool
    {
        return is_string($extversion)
            && $extversion !== ''
            && version_compare($extversion, self::ITERATIVE_SCAN_SINCE, '>=');
    }
}
