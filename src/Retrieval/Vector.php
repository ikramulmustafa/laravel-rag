<?php

declare(strict_types=1);

namespace Ikram\Rag\Retrieval;

/**
 * Formatting for pgvector literals.
 *
 * Deliberately a plain final class rather than a method on the Chunk model: this is
 * pure string handling with no persistence concern, and keeping it framework-free
 * means it is unit-testable without booting Laravel.
 */
final class Vector
{
    /**
     * pgvector expects `[0.1,-0.25,0]`.
     *
     * sprintf with %.8F rather than %g or string casting, because PHP renders small
     * floats in scientific notation ("1.234E-8") and Postgres rejects that literal.
     * The trailing-zero trim keeps the payload small on large corpora.
     *
     * @param  list<float>  $vector
     */
    public static function toLiteral(array $vector): string
    {
        return '['.implode(',', array_map(
            static fn (float $v): string => rtrim(rtrim(sprintf('%.8F', $v), '0'), '.') ?: '0',
            $vector,
        )).']';
    }

    /**
     * Parse a literal back into floats. Used by the non-Postgres fallback path
     * and by tests asserting round-trip fidelity.
     *
     * @return list<float>
     */
    public static function fromLiteral(string $literal): array
    {
        $inner = trim($literal, '[]');

        if ($inner === '') {
            return [];
        }

        return array_map(floatval(...), explode(',', $inner));
    }
}
