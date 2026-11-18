<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Embedding\FakeEmbedder;
use Ikram\Rag\Facades\Rag;
use Ikram\Rag\RagManager;
use Ikram\Rag\Tests\TestCase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Gateway\FakeEmbeddingGateway;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Swap the embedder and drop every singleton built on the old one, so the next
 * resolve of the retriever, ingestor or facade picks the replacement up.
 */
function useEmbedder(Embedder $embedder): void
{
    app()->instance(Embedder::class, $embedder);
    forgetEmbedderDependents();
}

/**
 * Drop the embedder itself as well, so the next resolve goes back through the
 * driver registry and picks up whatever `rag.embedding` now says.
 */
function rebuildEmbedder(): void
{
    app()->forgetInstance(Embedder::class);
    forgetEmbedderDependents();
}

function forgetEmbedderDependents(): void
{
    app()->forgetInstance(Retriever::class);
    app()->forgetInstance(Ingestor::class);
    app()->forgetInstance('rag');
    app()->forgetInstance(RagManager::class);
    Rag::clearResolvedInstance('rag');
}

/**
 * Fake the SDK's embedding gateway. By default every request is answered by the
 * deterministic FakeEmbedder at the width it asked for (64 when it asked for
 * none, as native mode does), so SDK-driven tests still assert real retrieval
 * ordering. A request the fake does not answer throws instead of reaching a
 * provider.
 *
 * @param  (Closure(EmbeddingsPrompt): mixed)|null  $respond
 */
function fakeSdkEmbeddings(?Closure $respond = null): FakeEmbeddingGateway
{
    return Embeddings::fake(
        $respond ?? fn (EmbeddingsPrompt $prompt): array => (new FakeEmbedder($prompt->dimensions ?: 64))->embed(array_values($prompt->inputs)),
    )->preventStrayEmbeddings();
}

/**
 * Swap in an embedder that counts embed() calls and delegates to the
 * deterministic FakeEmbedder, so a test can prove a path spent nothing.
 * The returned object's public `calls` is the number of embed() invocations;
 * setting `fail` makes every call throw.
 */
function useSpyEmbedder(int $dimensions = 64): object
{
    $spy = new class($dimensions) implements Embedder
    {
        public int $calls = 0;

        public bool $fail = false;

        public function __construct(private readonly int $dimensions) {}

        public function embed(array $texts): array
        {
            $this->calls++;

            if ($this->fail) {
                throw new RuntimeException('embedding provider unavailable');
            }

            return (new FakeEmbedder($this->dimensions))->embed($texts);
        }

        public function modelId(): string
        {
            return "fake:deterministic-hash:{$this->dimensions}";
        }

        public function dimensions(): int
        {
            return $this->dimensions;
        }

        public function estimateTokens(array $texts): int
        {
            return 0;
        }
    };

    useEmbedder($spy);

    return $spy;
}

/** Catch what `$fn` throws, so a test can look at the whole exception. */
function caught(Closure $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Expected an exception, nothing was thrown.');
}

/**
 * Skip the calling test unless it is running against Postgres. The pgsql group is
 * excluded from the default run in phpunit.xml; this is the second guard, so
 * `pest --group=pgsql` on a machine without Postgres skips instead of erroring.
 */
function skipUnlessPgsql(): void
{
    if (config('database.default') !== 'pgsql') {
        test()->markTestSkipped('Needs Postgres with pgvector (DB_CONNECTION=pgsql).');
    }
}
