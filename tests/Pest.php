<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Embedding\FakeEmbedder;
use Ikram\Rag\Facades\Rag;
use Ikram\Rag\RagManager;
use Ikram\Rag\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Swap the embedder and drop every singleton built on the old one, so the next
 * resolve of the retriever, ingestor or facade picks the replacement up.
 */
function useEmbedder(Embedder $embedder): void
{
    app()->instance(Embedder::class, $embedder);
    app()->forgetInstance(Retriever::class);
    app()->forgetInstance(Ingestor::class);
    app()->forgetInstance('rag');
    app()->forgetInstance(RagManager::class);
    Rag::clearResolvedInstance('rag');
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
