<?php

declare(strict_types=1);

use Ikram\Rag\Embedding\FakeEmbedder;
use Ikram\Rag\Embedding\SdkEmbedder;
use Ikram\Rag\Exceptions\EmbeddingFailed;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

beforeEach(function (): void {
    config()->set('rag.embedding.dimensions', 64);
});

function openAiEmbedder(int $batchSize = 96, bool $sendDimensions = true): SdkEmbedder
{
    return new SdkEmbedder('openai', 'openai', 'text-embedding-3-small', 64, $sendDimensions, $batchSize);
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

it('sends the configured dimensions with every request', function (): void {
    fakeSdkEmbeddings();

    $vectors = openAiEmbedder()->embed(['refund policy', 'shipping times']);

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->dimensions === 64);

    expect($vectors)->toBe((new FakeEmbedder(64))->embed(['refund policy', 'shipping times']));
});

it('sends no width in native mode, and still checks the width it gets back', function (): void {
    // The SDK substitutes the provider's default width for 0, so only providers
    // whose default is 0 (Ollama, openai-compatible) receive "no width". That is
    // exactly the set native mode exists for.
    config()->set('ai.providers.local', ['driver' => 'ollama']);
    fakeSdkEmbeddings();

    $embedder = new SdkEmbedder('local', 'ollama', 'nomic-embed-text', 64, sendDimensions: false);

    expect($embedder->embed(['refund policy'])[0])->toHaveCount(64);

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->dimensions === 0);
    Embeddings::assertNotGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->dimensions === 64);
});

it('splits the input into batches of batch_size', function (): void {
    $sizes = [];

    fakeSdkEmbeddings(function (EmbeddingsPrompt $prompt) use (&$sizes): array {
        $sizes[] = count($prompt->inputs);

        return (new FakeEmbedder(64))->embed(array_values($prompt->inputs));
    });

    $texts = ['one', 'two', 'three', 'four', 'five'];

    expect(openAiEmbedder(batchSize: 2)->embed($texts))->toBe((new FakeEmbedder(64))->embed($texts))
        ->and($sizes)->toBe([2, 2, 1]);
});

it('refuses a response with the wrong number of vectors', function (): void {
    fakeSdkEmbeddings(fn (EmbeddingsPrompt $prompt): array => array_slice((new FakeEmbedder(64))->embed(array_values($prompt->inputs)), 1));

    $e = caught(fn () => openAiEmbedder()->embed(['refund policy', 'shipping times']));

    expect($e)->toBeInstanceOf(EmbeddingFailed::class)
        ->and($e->getMessage())->toContain('[openai]')
        ->and($e->getMessage())->not->toMatch('/\d\.\d{3}/')
        ->and(strtolower($e->getMessage()))->not->toContain('http');
});

it('refuses a response with vectors of the wrong width', function (): void {
    fakeSdkEmbeddings(fn (EmbeddingsPrompt $prompt): array => (new FakeEmbedder(32))->embed(array_values($prompt->inputs)));

    $e = caught(fn () => openAiEmbedder()->embed(['refund policy']));

    expect($e)->toBeInstanceOf(EmbeddingFailed::class)
        ->and($e->getMessage())->toContain('64')
        ->and($e->getMessage())->not->toMatch('/\d\.\d{3}/')
        ->and(strtolower($e->getMessage()))->not->toContain('http');
});

it('wraps an SDK failure without repeating what the provider said', function (): void {
    $failure = new RuntimeException('HTTP request returned status code 500: {"error":"secret body"}');

    fakeSdkEmbeddings(function () use ($failure): never {
        throw $failure;
    });

    $e = caught(fn () => openAiEmbedder()->embed(['refund policy']));

    expect($e)->toBeInstanceOf(EmbeddingFailed::class)
        ->and($e->getPrevious())->toBe($failure)
        ->and($e->getMessage())->toBe('Embedding request to provider [openai] failed (RuntimeException).');
});

it('passes the timeout through', function (): void {
    fakeSdkEmbeddings();

    (new SdkEmbedder('openai', 'openai', 'text-embedding-3-small', 64, timeout: 12))->embed(['refund policy']);

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->timeout === 12);
});

it('serves a repeated batch from the SDK cache when cache is on', function (): void {
    config()->set('ai.caching.embeddings.store', 'array');
    $requests = 0;

    fakeSdkEmbeddings(function (EmbeddingsPrompt $prompt) use (&$requests): array {
        $requests++;

        return (new FakeEmbedder(64))->embed(array_values($prompt->inputs));
    });

    $embedder = new SdkEmbedder('openai', 'openai', 'text-embedding-3-small', 64, cache: true);

    expect($embedder->embed(['refund policy']))->toBe($embedder->embed(['refund policy']))
        ->and($requests)->toBe(1);
});

it('makes no request for no input', function (): void {
    fakeSdkEmbeddings();

    expect(openAiEmbedder()->embed([]))->toBe([]);

    Embeddings::assertNothingGenerated();
});

it('rejects a failover list of providers', function (): void {
    expect(fn () => SdkEmbedder::fromConfig(['provider' => ['openai', 'gemini']], 'openai'))
        ->toThrow(InvalidArgumentException::class, 'must name a single provider; failover would store vectors under the wrong model id');
});

it('names an unknown provider and points at config/ai.php', function (): void {
    expect(fn () => SdkEmbedder::fromConfig(['provider' => 'nope'], 'openai'))
        ->toThrow(InvalidArgumentException::class, '[nope]')
        ->and(fn () => SdkEmbedder::fromConfig(['provider' => 'nope'], 'openai'))
        ->toThrow(InvalidArgumentException::class, 'config/ai.php');
});

it('refuses to guess when no provider is configured anywhere', function (): void {
    expect(fn () => SdkEmbedder::fromConfig([], null))
        ->toThrow(InvalidArgumentException::class, 'RAG_EMBEDDING_PROVIDER');
});

it('builds the model id from the provider driver, not its config key', function (): void {
    config()->set('ai.providers.main', ['driver' => 'openai', 'key' => 'x']);

    $embedder = SdkEmbedder::fromConfig(['provider' => 'main', 'model' => null, 'dimensions' => 64], null);

    expect($embedder->modelId())->toStartWith('openai:')
        ->and($embedder->modelId())->toBe('openai:text-embedding-3-small:64');
});

it('falls back to the SDK default provider and its default model', function (): void {
    $embedder = SdkEmbedder::fromConfig(['dimensions' => 64], 'openai');

    expect($embedder->modelId())->toBe('openai:text-embedding-3-small:64')
        ->and($embedder->dimensions())->toBe(64);
});

it('estimates four characters per token', function (): void {
    expect(openAiEmbedder()->estimateTokens(['abcd', 'ééé']))->toBe(2)
        ->and(openAiEmbedder()->estimateTokens([]))->toBe(0);
});
