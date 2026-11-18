<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Embedding\EmbedderRegistry;
use Ikram\Rag\Embedding\FakeEmbedder;
use Ikram\Rag\Embedding\SdkEmbedder;
use Ikram\Rag\Facades\Rag;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;

it('registers sdk, fake and the openai alias', function (): void {
    expect(app(EmbedderRegistry::class)->drivers())->toBe(['sdk', 'fake', 'openai']);
});

it('lists the registered drivers when the driver is unknown', function (): void {
    expect(fn () => app(EmbedderRegistry::class)->make('nope'))
        ->toThrow(InvalidArgumentException::class, 'Unknown embedding driver [nope]. Registered: sdk, fake, openai.');
});

it('builds the fake driver at the configured width', function (): void {
    config()->set('rag.embedding.dimensions', 32);
    rebuildEmbedder();

    expect(app(Embedder::class))->toBeInstanceOf(FakeEmbedder::class)
        ->and(app(Embedder::class)->dimensions())->toBe(32);
});

it('builds the sdk driver from config/ai.php', function (): void {
    config()->set('rag.embedding.driver', 'sdk');
    rebuildEmbedder();

    expect(app(Embedder::class))->toBeInstanceOf(SdkEmbedder::class)
        ->and(app(Embedder::class)->modelId())->toBe('openai:text-embedding-3-small:64');
});

it('accepts a Lab enum as the SDK default provider', function (): void {
    config()->set('ai.default_for_embeddings', Lab::OpenAI);

    expect(app(EmbedderRegistry::class)->make('sdk')->modelId())->toBe('openai:text-embedding-3-small:64');
});

it('rejects a failover list in the SDK default provider with the reason', function (): void {
    config()->set('ai.default_for_embeddings', ['openai', 'gemini']);

    expect(fn () => app(EmbedderRegistry::class)->make('sdk'))
        ->toThrow(InvalidArgumentException::class, 'must name a single provider');
});

it('resolves a driver added with Rag::extend', function (): void {
    $custom = new FakeEmbedder(64);

    config()->set('rag.embedding.driver', 'custom');
    rebuildEmbedder();

    // Registered before anything resolves the embedder, as a service provider would.
    Rag::extend('custom', fn (): Embedder => $custom);

    expect(app(Embedder::class))->toBe($custom)
        ->and(app(EmbedderRegistry::class)->drivers())->toContain('custom');
});

it('refuses a factory that does not return an Embedder', function (): void {
    app(EmbedderRegistry::class)->extend('broken', fn (): stdClass => new stdClass);

    expect(fn () => app(EmbedderRegistry::class)->make('broken'))
        ->toThrow(UnexpectedValueException::class, '[broken]');
});

it('boots a published 0.2 config through the deprecated openai alias', function (): void {
    Log::spy();
    fakeSdkEmbeddings();

    config()->set('rag.embedding', [
        'driver' => 'openai',
        'model' => 'text-embedding-3-small',
        'dimensions' => 64,
        'api_key' => 'sk-legacy',
        'base_url' => 'https://api.openai.com/v1',
        'batch_size' => 96,
    ]);
    rebuildEmbedder();

    $embedder = app(Embedder::class);

    expect($embedder)->toBeInstanceOf(SdkEmbedder::class)
        ->and($embedder->modelId())->toBe('openai:text-embedding-3-small:64');

    Rag::ingest('policies/refunds.md', 'Customers may request a refund within 30 days of delivery for damaged goods.');
    Rag::ingest('policies/shipping.md', 'Standard shipping takes 3 to 5 working days across the mainland.');

    expect(Rag::retrieve('refund for damaged goods')->sources()[0])->toBe('policies/refunds.md');

    Log::shouldHaveReceived('warning')->once();
    Log::shouldHaveReceived('warning')
        ->with('[laravel-rag] The "openai" embedding driver is deprecated; use "sdk" with RAG_EMBEDDING_PROVIDER=openai.');
});

it('warns about the alias once per application, however often it resolves', function (): void {
    Log::spy();
    config()->set('rag.embedding.driver', 'openai');

    $registry = app(EmbedderRegistry::class);
    $registry->make('openai');
    $registry->make('openai');

    Log::shouldHaveReceived('warning')->once();
});

it('warns once more when a 0.2 base_url pointed somewhere other than OpenAI', function (): void {
    Log::spy();
    config()->set('rag.embedding.base_url', 'https://llm.internal.example/v1');

    $registry = app(EmbedderRegistry::class);
    $registry->make('openai');
    $registry->make('openai');

    Log::shouldHaveReceived('warning')->twice();
    Log::shouldHaveReceived('warning')->with(Mockery::on(
        fn (string $message): bool => str_contains($message, 'base_url') && str_contains($message, 'openai-compatible'),
    ))->once();
});
