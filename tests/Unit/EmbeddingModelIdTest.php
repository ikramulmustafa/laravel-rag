<?php

declare(strict_types=1);

use Ikram\Rag\Embedding\EmbeddingModelId;

it('joins driver, model and dimensions', function (): void {
    expect(EmbeddingModelId::make('openai', 'text-embedding-3-small', 1536))
        ->toBe('openai:text-embedding-3-small:1536');
});

it('strips the width suffix to get the legacy form', function (): void {
    expect(EmbeddingModelId::legacyForm('openai:text-embedding-3-small:1536'))->toBe('openai:text-embedding-3-small')
        ->and(EmbeddingModelId::legacyForm('fake:deterministic-hash:64'))->toBe('fake:deterministic-hash');
});

it('has no legacy form for an id without a width suffix', function (): void {
    expect(EmbeddingModelId::legacyForm('ollama:nomic-embed-text'))->toBeNull();
});

it('matches a stored legacy id against the current full id', function (): void {
    expect(EmbeddingModelId::matches('openai:x', 'openai:x:1536'))->toBeTrue()
        ->and(EmbeddingModelId::matches('openai:x:1536', 'openai:x:1536'))->toBeTrue();
});

it('does not match a stored id of another width', function (): void {
    expect(EmbeddingModelId::matches('openai:x:768', 'openai:x:1536'))->toBeFalse();
});

it('does not match a stored full id against a suffix-less current id', function (): void {
    expect(EmbeddingModelId::matches('openai:x:1536', 'openai:x'))->toBeFalse();
});
