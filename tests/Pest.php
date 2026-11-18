<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Embedding\FakeEmbedder;
use Ikram\Rag\Facades\Rag;
use Ikram\Rag\RagManager;
use Ikram\Rag\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Swap in an embedder that counts embed() calls and delegates to the
 * deterministic FakeEmbedder, so a test can prove a path spent nothing.
 * The returned object's public `calls` is the number of embed() invocations.
 */
function useSpyEmbedder(): object
{
    $spy = new class implements Embedder
    {
        public int $calls = 0;

        public function embed(array $texts): array
        {
            $this->calls++;

            return (new FakeEmbedder(64))->embed($texts);
        }

        public function modelId(): string
        {
            return 'fake:deterministic-hash:64';
        }

        public function dimensions(): int
        {
            return 64;
        }

        public function estimateTokens(array $texts): int
        {
            return 0;
        }
    };

    app()->instance(Embedder::class, $spy);
    app()->forgetInstance(Ingestor::class);
    app()->forgetInstance('rag');
    app()->forgetInstance(RagManager::class);
    Rag::clearResolvedInstance('rag');

    return $spy;
}
