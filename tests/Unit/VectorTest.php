<?php

declare(strict_types=1);

use Ikram\Rag\Retrieval\Vector;

it('formats a pgvector literal', function (): void {
    expect(Vector::toLiteral([0.5, -0.25]))->toBe('[0.5,-0.25]');
});

it('renders zero without a trailing dot', function (): void {
    expect(Vector::toLiteral([0.0]))->toBe('[0]');
});

it('avoids scientific notation, which Postgres rejects', function (): void {
    expect(Vector::toLiteral([0.00000001234]))->not->toContain('E');
});

it('round-trips through parsing', function (): void {
    $vector = [0.5, -0.25, 0.125];

    expect(Vector::fromLiteral(Vector::toLiteral($vector)))->toBe($vector);
});

it('parses an empty literal', function (): void {
    expect(Vector::fromLiteral('[]'))->toBe([]);
});
