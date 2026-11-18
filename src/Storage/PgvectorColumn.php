<?php

declare(strict_types=1);

namespace Ikram\Rag\Storage;

use Illuminate\Database\Connection;
use InvalidArgumentException;

/**
 * The pgvector `rag_chunks.embedding` column, whose width is fixed in its type.
 *
 * On JSON storage any width fits, but `vector(N)` rejects every vector that is not
 * N wide, so moving to a model of another width means changing the column. That
 * clears every stored vector, which is why only `rag:reindex --resize` calls it.
 */
final class PgvectorColumn
{
    /**
     * pgvector's HNSW index refuses `vector` columns wider than this. Wider columns
     * stay unindexed and every search scans them in full: correct, but slow on a
     * large corpus.
     */
    public const MAX_INDEXED_DIMENSIONS = 2000;

    /**
     * Whether a `format_type()` result is a pgvector `vector` column, sized or not.
     * `halfvec`, `sparsevec` and arrays are not: their values are not vectors this
     * package wrote, and resizing would wipe them.
     */
    public static function isVector(string $formatType): bool
    {
        return preg_match('/^vector(\(\d+\))?$/', trim($formatType)) === 1;
    }

    /**
     * The width in a `format_type()` result: `vector(64)` is 64. A bare `vector`
     * has no fixed width, and any other type is not a pgvector column at all.
     */
    public static function parseWidth(string $formatType): ?int
    {
        return preg_match('/^vector\((\d+)\)$/', trim($formatType), $match) === 1
            ? (int) $match[1]
            : null;
    }

    /**
     * The declared type of `rag_chunks.embedding`, e.g. `vector(64)`, or null when
     * the table or the column does not exist. `to_regclass` rather than a
     * `::regclass` cast, which would throw on a missing table instead.
     */
    public static function currentType(Connection $connection): ?string
    {
        $type = $connection->scalar(
            "SELECT format_type(atttypid, atttypmod) FROM pg_attribute WHERE attrelid = to_regclass('rag_chunks') AND attname = 'embedding' AND NOT attisdropped"
        );

        return is_string($type) ? $type : null;
    }

    /** The width the column is declared with, or null when it has none. */
    public static function currentWidth(Connection $connection): ?int
    {
        $type = self::currentType($connection);

        return $type === null ? null : self::parseWidth($type);
    }

    /**
     * Replace the column with a `vector($dimensions)` one, clearing every stored vector.
     *
     * One transaction, so a failure leaves the old column and its index in place.
     * Dropping and re-adding the column rather than `ALTER COLUMN ... TYPE`: a type
     * change rewrites the whole table under an exclusive lock, while a dropped
     * column is only marked dead and the new one starts out NULL with no rewrite.
     * The chunks keep their text and are re-embedded afterwards. The retriever
     * skips NULL embeddings, so search returns fewer results meanwhile, not errors.
     *
     * @return bool whether the column is indexed, which it is not above
     *              MAX_INDEXED_DIMENSIONS
     */
    public static function resize(Connection $connection, int $dimensions): bool
    {
        if ($dimensions < 1) {
            throw new InvalidArgumentException("A vector column needs at least 1 dimension, {$dimensions} given.");
        }

        return $connection->transaction(function () use ($connection, $dimensions): bool {
            // Dropping the column would take the index with it; dropped by name so
            // the order of events does not rest on that.
            $connection->statement('DROP INDEX IF EXISTS rag_chunks_embedding_idx');
            $connection->statement("ALTER TABLE rag_chunks DROP COLUMN embedding, ADD COLUMN embedding vector({$dimensions})");

            if ($dimensions > self::MAX_INDEXED_DIMENSIONS) {
                return false;
            }

            $connection->statement(
                'CREATE INDEX rag_chunks_embedding_idx ON rag_chunks '
                .'USING hnsw (embedding vector_cosine_ops)'
            );

            return true;
        });
    }
}
