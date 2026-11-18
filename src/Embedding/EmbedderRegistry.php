<?php

declare(strict_types=1);

namespace Ikram\Rag\Embedding;

use Closure;
use Ikram\Rag\Contracts\Embedder;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Laravel\Ai\Enums\Lab;
use UnexpectedValueException;

/**
 * Maps `rag.embedding.driver` to an Embedder. Built in: `sdk` (the Laravel AI
 * SDK), `fake` (deterministic, network-free) and `openai`, a deprecated alias
 * kept because a published 0.2 config still says `driver => 'openai'` and
 * `mergeConfigFrom` is shallow, so those apps would otherwise fail to boot on
 * upgrade. The alias goes in 1.0.
 *
 * A container singleton, so the deprecation warnings are tracked per
 * application instance rather than in static state: each app warns once, and
 * the next test's fresh app warns again.
 */
final class EmbedderRegistry
{
    private const OPENAI_BASE_URL = 'https://api.openai.com/v1';

    /** @var array<string, Closure> */
    private array $factories = [];

    /** @var array<string, true> */
    private array $warned = [];

    public function __construct(private readonly Container $container)
    {
        $this->factories['sdk'] = fn (): Embedder => $this->sdk($this->config());
        $this->factories['fake'] = fn (): Embedder => new FakeEmbedder((int) config('rag.embedding.dimensions', 1536));
        $this->factories['openai'] = fn (): Embedder => $this->openAiAlias();
    }

    /**
     * Register a driver. The factory receives the container and must return an
     * Embedder; registering an existing name replaces it.
     *
     * The Embedder is a singleton, so call this from a service provider's
     * register() or boot(), before anything resolves the embedder. A driver
     * registered afterwards is not picked up by the instance already built.
     */
    public function extend(string $driver, Closure $factory): void
    {
        $this->factories[$driver] = $factory;
    }

    public function make(string $driver): Embedder
    {
        $factory = $this->factories[$driver] ?? throw new InvalidArgumentException(sprintf(
            'Unknown embedding driver [%s]. Registered: %s.',
            $driver,
            implode(', ', $this->drivers()),
        ));

        $embedder = $factory($this->container);

        if (! $embedder instanceof Embedder) {
            throw new UnexpectedValueException(sprintf(
                'The factory for embedding driver [%s] returned %s, not an %s.',
                $driver,
                get_debug_type($embedder),
                Embedder::class,
            ));
        }

        return $embedder;
    }

    /** @return list<string> */
    public function drivers(): array
    {
        return array_keys($this->factories);
    }

    private function openAiAlias(): Embedder
    {
        $config = $this->config();

        $this->warnOnce('openai-driver', '[laravel-rag] The "openai" embedding driver is deprecated; use "sdk" with RAG_EMBEDDING_PROVIDER=openai.');

        // 0.2 read the endpoint from here. Now only config/ai.php does, so anyone
        // who pointed it elsewhere is about to send their text to OpenAI instead.
        $baseUrl = $config['base_url'] ?? null;

        if (is_string($baseUrl) && $baseUrl !== '' && rtrim($baseUrl, '/') !== self::OPENAI_BASE_URL) {
            $this->warnOnce('base-url', '[laravel-rag] rag.embedding.base_url is no longer read. '
                .'Define an "openai-compatible" provider with that url in config/ai.php and set RAG_EMBEDDING_PROVIDER to its name.');
        }

        return $this->sdk([...$config, 'provider' => $config['provider'] ?? 'openai']);
    }

    /**
     * Hand `ai.default_for_embeddings` over as a name. The SDK also accepts a Lab
     * enum there, and a failover list, which goes in as the provider so that
     * SdkEmbedder rejects it with the reason rather than "nothing configured".
     *
     * @param  array<string, mixed>  $config
     */
    private function sdk(array $config): Embedder
    {
        $default = config('ai.default_for_embeddings');

        if ($default instanceof Lab) {
            $default = $default->value;
        }

        if (is_array($default)) {
            $config['provider'] ??= $default;
        }

        return SdkEmbedder::fromConfig($config, is_string($default) ? $default : null);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = config('rag.embedding', []);

        return is_array($config) ? $config : [];
    }

    private function warnOnce(string $key, string $message): void
    {
        if (isset($this->warned[$key])) {
            return;
        }

        $this->warned[$key] = true;

        logger()->warning($message);
    }
}
