<?php

declare(strict_types=1);

use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Embedding\EmbedderRegistry;
use Ikram\Rag\Embedding\FakeEmbedder;
use Ikram\Rag\Embedding\SdkEmbedder;
use Ikram\Rag\Facades\Rag;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Mockery\Matcher\Closure;

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

    // One deprecation warning for the alias, and one for the config itself:
    // a published 0.2 config has none of the 0.3 keys.
    Log::shouldHaveReceived('warning')->twice();
    Log::shouldHaveReceived('warning')
        ->with('[laravel-rag] The "openai" embedding driver is deprecated; use "sdk" with RAG_EMBEDDING_PROVIDER=openai.')
        ->once();
    Log::shouldHaveReceived('warning')->with(predates03())->once();
});

it('warns about the alias once per application, however often it resolves', function (): void {
    Log::spy();
    config()->set('rag.embedding.driver', 'openai');

    $registry = app(EmbedderRegistry::class);
    $registry->make('openai');
    $registry->make('openai');

    Log::shouldHaveReceived('warning')->once();
});

it('refuses a 0.2 base_url that pointed somewhere other than OpenAI, before any request', function (): void {
    Log::spy();
    fakeSdkEmbeddings();

    config()->set('rag.embedding', [
        'driver' => 'openai',
        'model' => 'text-embedding-3-small',
        'dimensions' => 64,
        'api_key' => 'sk-internal-secret',
        'base_url' => 'https://llm.internal.example/v1',
        'batch_size' => 96,
    ]);
    rebuildEmbedder();

    $e = caught(fn () => Rag::ingest('policies/refunds.md', 'Customers may request a refund within 30 days.'));

    expect($e)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($e->getMessage())->toContain('[llm.internal.example]')
        ->and($e->getMessage())->toContain('RAG_EMBEDDING_DRIVER=sdk')
        ->and($e->getMessage())->toContain('"openai-compatible"')
        ->and($e->getMessage())->toContain('rag:reindex --stale')
        ->and($e->getMessage())->not->toContain('sk-internal-secret');

    Embeddings::assertNothingGenerated();
});

it('treats the OpenAI base_url with a trailing slash as the default', function (): void {
    Log::spy();
    config()->set('rag.embedding.driver', 'openai');
    config()->set('rag.embedding.base_url', 'https://api.openai.com/v1/');
    rebuildEmbedder();

    expect(app(Embedder::class))->toBeInstanceOf(SdkEmbedder::class);

    Log::shouldHaveReceived('warning')->once();
});

it('falls back to the openai provider when the alias sees an empty provider', function (): void {
    config()->set('ai.default_for_embeddings', 'ollama');
    config()->set('ai.providers.ollama', ['driver' => 'ollama']);
    config()->set('rag.embedding.driver', 'openai');
    config()->set('rag.embedding.provider', '');
    rebuildEmbedder();

    expect(app(Embedder::class)->modelId())->toBe('openai:text-embedding-3-small:64');
});

function predates03(): Closure
{
    return Mockery::on(fn (string $message): bool => str_contains($message, 'predates 0.3')
        && str_contains($message, '--tag=rag-config --force'));
}

it('refuses a custom RAG_EMBEDDING_BASE_URL on the sdk driver, before any request', function (): void {
    Log::spy();
    fakeSdkEmbeddings();

    // An env-only 0.2 user: no published config, so the merged one is 0.3's, with
    // driver sdk and the openai provider, and OPENAI_API_KEY holds their gateway token.
    config()->set('ai.providers.openai.key', 'sk-internal-secret');
    config()->set('rag.embedding.driver', 'sdk');
    config()->set('rag.embedding.base_url', 'https://internal-llm/v1');
    rebuildEmbedder();

    $e = caught(fn () => Rag::ingest('policies/refunds.md', 'Customers may request a refund within 30 days.'));

    expect($e)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($e->getMessage())->toContain('[internal-llm]')
        ->and($e->getMessage())->toContain('"openai-compatible"')
        ->and($e->getMessage())->toContain('remove base_url / RAG_EMBEDDING_BASE_URL')
        ->and($e->getMessage())->toContain('rag:reindex --stale')
        ->and($e->getMessage())->not->toContain('sk-internal-secret');

    Embeddings::assertNothingGenerated();
    Log::shouldNotHaveReceived('warning');
});

it('refuses a custom base_url left in a published 0.2 config after switching to sdk', function (): void {
    config()->set('rag.embedding', [
        'driver' => 'sdk',
        'model' => 'text-embedding-3-small',
        'dimensions' => 64,
        'base_url' => 'https://llm.internal.example/v1',
    ]);

    expect(fn () => app(EmbedderRegistry::class)->make('sdk'))
        ->toThrow(InvalidArgumentException::class, '[llm.internal.example]');
});

it('runs the sdk driver when base_url is unset, the default, or the default with a slash', function (?string $baseUrl): void {
    fakeSdkEmbeddings();
    config()->set('rag.embedding.driver', 'sdk');
    config()->set('rag.embedding.base_url', $baseUrl);
    rebuildEmbedder();

    expect(app(Embedder::class))->toBeInstanceOf(SdkEmbedder::class)
        ->and(app(Embedder::class)->embed(['refund policy'])[0])->toHaveCount(64);
})->with([null, '', 'https://api.openai.com/v1', 'https://api.openai.com/v1/']);

it('leaves custom drivers and fake alone whatever base_url says', function (): void {
    config()->set('rag.embedding.base_url', 'https://internal-llm/v1');
    Rag::extend('custom', fn (): Embedder => new FakeEmbedder(64));

    expect(app(EmbedderRegistry::class)->make('custom'))->toBeInstanceOf(FakeEmbedder::class)
        ->and(app(EmbedderRegistry::class)->make('fake'))->toBeInstanceOf(FakeEmbedder::class);
});

it('warns once that a published config predates 0.3', function (): void {
    Log::spy();
    config()->set('rag.embedding', [
        'driver' => 'fake',
        'model' => 'text-embedding-3-small',
        'dimensions' => 64,
        'api_key' => null,
        'base_url' => 'https://api.openai.com/v1',
        'batch_size' => 96,
    ]);

    $registry = app(EmbedderRegistry::class);
    $registry->make('fake');
    $registry->make('fake');

    Log::shouldHaveReceived('warning')->with(predates03())->once();
    Log::shouldHaveReceived('warning')->once();
});

it('does not warn about the config when it has the 0.3 keys', function (): void {
    Log::spy();
    config()->set('rag.embedding.driver', 'sdk');

    app(EmbedderRegistry::class)->make('sdk');
    app(EmbedderRegistry::class)->make('fake');

    Log::shouldNotHaveReceived('warning');
});

it('refuses the sdk driver with a published config that predates 0.3, before any request', function (array $block): void {
    Log::spy();
    fakeSdkEmbeddings();

    // Path B half done without re-publishing: RAG_EMBEDDING_DRIVER=sdk reaches the
    // 0.2 block, but RAG_EMBEDDING_PROVIDER has no key to land in, so the provider
    // would silently fall back to ai.default_for_embeddings (OpenAI).
    config()->set('rag.embedding', $block);
    rebuildEmbedder();

    $e = caught(fn () => Rag::ingest('policies/refunds.md', 'Customers may request a refund within 30 days.'));

    expect($e)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($e->getMessage())->toContain('predates 0.3')
        ->and($e->getMessage())->toContain('RAG_EMBEDDING_PROVIDER')
        ->and($e->getMessage())->toContain('--tag=rag-config --force');

    Embeddings::assertNothingGenerated();
})->with([
    'base_url at the 0.2 default' => [[
        'driver' => 'sdk',
        'model' => 'text-embedding-3-small',
        'dimensions' => 64,
        'api_key' => null,
        'base_url' => 'https://api.openai.com/v1',
        'batch_size' => 96,
    ]],
    'base_url removed' => [[
        'driver' => 'sdk',
        'model' => 'text-embedding-3-small',
        'dimensions' => 64,
        'api_key' => null,
        'batch_size' => 96,
    ]],
]);
