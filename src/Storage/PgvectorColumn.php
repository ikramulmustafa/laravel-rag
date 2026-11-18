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
     * The width in a `format_type()` result: `vector(64)` is 64. A bare `vector`
     * has no fixed width, and any other type is not a pgvector column at all.
     */
    public static function parseWidth(string $formatType): ?int
    {
        return preg_match('/^vector\((\d+)\)$/', trim($formatType), $match) === 1
            ? (int) $match[1]
            : null;
    }

    /** The width the column is declared with, or null when it has none. */
    public static function currentWidth(Connection $connection): ?int
    {
        $type = $connection->scalar(
            "SELECT format_type(atttypid, atttypmod) FROM pg_attribute WHERE attrelid = 'rag_chunks'::regclass AND attname = 'embedding'"
        );

        return is_string($type) ? self::parseWidth($type) : null;
    }

    /**
     * Change the column to `vector($dimensions)`, clearing every stored vector.
     *
     * One transaction, so a failure leaves the old column and its index in place.
     * `USING NULL` because a vector cannot be cast to another width; the chunks
     * keep their text and are re-embedded afterwards. The retriever skips NULL
     * embeddings, so search returns fewer results meanwhile rather than errors.
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
            $connection->statement('DROP INDEX IF EXISTS rag_chunks_embedding_idx');
            $connection->statement("ALTER TABLE rag_chunks ALTER COLUMN embedding TYPE vector({$dimensions}) USING NULL");

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
