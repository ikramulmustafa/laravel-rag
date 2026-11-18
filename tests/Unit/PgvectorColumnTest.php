<?php

declare(strict_types=1);

use Ikram\Rag\Storage\PgvectorColumn;

it('reads the width out of a vector column type', function (): void {
    expect(PgvectorColumn::parseWidth('vector(64)'))->toBe(64)
        ->and(PgvectorColumn::parseWidth('vector(3072)'))->toBe(3072);
});

it('has no width for an unsized vector column', function (): void {
    expect(PgvectorColumn::parseWidth('vector'))->toBeNull();
});

it('has no width for a type that is not vector', function (): void {
    expect(PgvectorColumn::parseWidth('halfvec(64)'))->toBeNull()
        ->and(PgvectorColumn::parseWidth('jsonb'))->toBeNull();
});

it('stops indexing at pgvector\'s hnsw limit', function (): void {
    expect(PgvectorColumn::MAX_INDEXED_DIMENSIONS)->toBe(2000);
});
