<?php

declare(strict_types=1);

namespace Ikram\Rag\Embedding;

use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Exceptions\EmbeddingFailed;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\PendingResponses\PendingEmbeddingsGeneration;
use Laravel\Ai\Providers\Provider;
use Throwable;

/**
 * Embeds through the Laravel AI SDK, so credentials, endpoints and providers all
 * live in `config/ai.php` and this package only decides what to embed and checks
 * what comes back.
 *
 * The SDK neither splits batches nor checks the response, and its gateways fill
 * the response's model from the *requested* model, so a substituted model can't
 * be detected from the metadata. What this class does instead: it pins one
 * provider (no failover lists), sends the width it expects, and refuses any
 * response whose count or width is wrong, or that holds a non-finite value,
 * before a single vector is stored.
 */
final class SdkEmbedder implements Embedder
{
    /**
     * @param  string  $provider  The provider's key in `config/ai.php`.
     * @param  string  $driver  That provider's SDK driver; it goes into modelId(), so renaming the key does not orphan a corpus.
     * @param  bool  $sendDimensions  False is "native" mode, for models that reject a dimensions parameter. The width is still checked.
     */
    public function __construct(
        private readonly string $provider,
        private readonly string $driver,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly bool $sendDimensions = true,
        private readonly int $batchSize = 96,
        private readonly int $timeout = 30,
        private readonly bool $cache = false,
    ) {
        if ($dimensions < 1 || $batchSize < 1) {
            throw new InvalidArgumentException('rag.embedding.dimensions and rag.embedding.batch_size must both be at least 1.');
        }
    }

    /**
     * Build from the `rag.embedding` config block. Every key has a default, so a
     * published 0.2 config that lacks the newer keys still resolves.
     *
     * `provider` may be a name or a Lab enum, as in config/ai.php.
     *
     * @param  array<string, mixed>  $config
     * @param  string|null  $defaultProvider  `ai.default_for_embeddings`, used when `provider` is not set.
     */
    public static function fromConfig(array $config, ?string $defaultProvider): self
    {
        $provider = $config['provider'] ?? null;
        $provider = $provider instanceof Lab ? $provider->value : $provider;
        $provider = $provider === '' ? null : $provider;
        $provider ??= $defaultProvider;

        if (is_array($provider)) {
            throw new InvalidArgumentException(
                'rag.embedding.provider must name a single provider; failover would store vectors under the wrong model id.'
            );
        }

        if (! is_string($provider) || $provider === '') {
            throw new InvalidArgumentException(
                'No embedding provider is configured. Set RAG_EMBEDDING_PROVIDER, or ai.default_for_embeddings in config/ai.php.'
            );
        }

        try {
            $instance = Ai::embeddingProvider($provider);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(
                config("ai.providers.{$provider}") === null
                    ? "Embedding provider [{$provider}] is not defined. Add it to the providers in config/ai.php, or set RAG_EMBEDDING_PROVIDER to one that is."
                    : "Embedding provider [{$provider}] is defined in config/ai.php but could not be built. Check its `driver` setting.",
                previous: $e,
            );
        }

        $model = $config['model'] ?? null;

        return new self(
            provider: $provider,
            driver: $instance instanceof Provider ? $instance->driver() : $provider,
            model: is_string($model) && $model !== '' ? $model : $instance->defaultEmbeddingsModel(),
            dimensions: (int) ($config['dimensions'] ?? 1536),
            sendDimensions: (bool) ($config['send_dimensions'] ?? true),
            batchSize: (int) ($config['batch_size'] ?? 96),
            timeout: (int) ($config['timeout'] ?? 30),
            cache: (bool) ($config['cache'] ?? false),
        );
    }

    public function embed(array $texts): array
    {
        $vectors = [];

        foreach (array_chunk(array_values($texts), $this->batchSize) as $batch) {
            foreach ($this->embedBatch($batch) as $vector) {
                $vectors[] = $vector;
            }
        }

        return $vectors;
    }

    /**
     * @param  list<string>  $batch
     * @return list<list<float>>
     */
    private function embedBatch(array $batch): array
    {
        try {
            $response = Embeddings::for($batch)
                // 0 is how the SDK spells "no width"; the Ollama and
                // openai-compatible gateways then leave it out of the request.
                ->dimensions($this->sendDimensions ? $this->dimensions : 0)
                ->timeout($this->timeout)
                ->when($this->cache, fn (PendingEmbeddingsGeneration $pending) => $pending->cache())
                ->generate($this->provider, $this->model);
        } catch (Throwable $e) {
            // The class name only: a provider's message can carry the response
            // body, and this message may end up in front of a model or a user.
            throw new EmbeddingFailed(
                "Embedding request to provider [{$this->provider}] failed (".class_basename($e).').',
                previous: $e,
            );
        }

        $embeddings = array_values($response->embeddings);

        if (count($embeddings) !== count($batch)) {
            throw new EmbeddingFailed(sprintf(
                'Embedding provider [%s] returned %d vectors for %d inputs. '
                .'Refusing to continue, since misaligned vectors would corrupt the index silently.',
                $this->provider,
                count($embeddings),
                count($batch),
            ));
        }

        $vectors = [];

        foreach ($embeddings as $vector) {
            if (! is_array($vector) || count($vector) !== $this->dimensions) {
                throw new EmbeddingFailed(sprintf(
                    'Embedding provider [%s] returned a vector of width %d; rag.embedding.dimensions is %d. '
                    .'Set it to the width model [%s] produces.',
                    $this->provider,
                    is_array($vector) ? count($vector) : 0,
                    $this->dimensions,
                    $this->model,
                ));
            }

            $floats = array_map(static fn (mixed $value): float => (float) $value, array_values($vector));

            // Some local models emit NaN. pgvector would reject the row with the whole
            // literal in its error, and JSON storage would keep nothing searchable.
            foreach ($floats as $value) {
                if (! is_finite($value)) {
                    throw new EmbeddingFailed(sprintf(
                        'Embedding provider [%s] returned a vector value that is not a finite number (NaN or infinity). '
                        .'Nothing was stored.',
                        $this->provider,
                    ));
                }
            }

            $vectors[] = $floats;
        }

        return $vectors;
    }

    public function modelId(): string
    {
        return EmbeddingModelId::make($this->driver, $this->model, $this->dimensions);
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function estimateTokens(array $texts): int
    {
        // The SDK has no token estimator. ~4 characters per token holds well
        // enough for English prose to catch "you are about to spend real money"
        // before it happens; it is not the provider's own accounting.
        return (int) ceil(array_sum(array_map(mb_strlen(...), $texts)) / 4);
    }
}
