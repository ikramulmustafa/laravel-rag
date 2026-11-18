<?php

declare(strict_types=1);

namespace Ikram\Rag;

use Ikram\Rag\Chunking\RecursiveChunker;
use Ikram\Rag\Console\EvalCommand;
use Ikram\Rag\Console\IngestCommand;
use Ikram\Rag\Console\ReindexCommand;
use Ikram\Rag\Contracts\Chunker;
use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Embedding\EmbedderRegistry;
use Ikram\Rag\Eval\EvalRunRecorder;
use Ikram\Rag\Retrieval\RerankingRetriever;
use Ikram\Rag\Retrieval\VectorRetriever;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Ai\Enums\Lab;

final class RagServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rag.php', 'rag');

        $this->app->singleton(EmbedderRegistry::class, fn (Container $app): EmbedderRegistry => new EmbedderRegistry($app));

        $this->app->singleton(Embedder::class, fn (Container $app): Embedder => $app->make(EmbedderRegistry::class)
            ->make((string) config('rag.embedding.driver', 'sdk')));

        $this->app->singleton(Chunker::class, fn (): Chunker => new RecursiveChunker(
            size: (int) config('rag.chunking.size'),
            overlap: (int) config('rag.chunking.overlap'),
        ));

        $this->app->singleton(Retriever::class, function (Container $app): Retriever {
            $limit = (int) config('rag.retrieval.default_limit', 5);

            $retriever = new VectorRetriever(
                embedder: $app->make(Embedder::class),
                connection: $app->make('db')->connection(config('rag.connection')),
                minScore: (float) config('rag.retrieval.min_score'),
                defaultLimit: $limit,
            );

            if (! config('rag.retrieval.rerank.enabled', false)) {
                return $retriever;
            }

            $provider = self::rerankProvider();
            $model = config('rag.retrieval.rerank.model');

            return new RerankingRetriever(
                inner: $retriever,
                provider: $provider,
                // Blank is unset, as for the provider; '' would reach the provider as a model name.
                model: is_string($model) && $model !== '' ? $model : null,
                candidates: (int) config('rag.retrieval.rerank.candidates', 3),
                defaultLimit: $limit,
                timeout: (int) config('rag.retrieval.rerank.timeout', 10),
                logger: $app->make('log'),
            );
        });

        $this->app->singleton(Ingestor::class, fn (Container $app): Ingestor => new Ingestor(
            embedder: $app->make(Embedder::class),
            chunker: $app->make(Chunker::class),
            connection: $app->make('db')->connection(config('rag.connection')),
            tokenWarningThreshold: (int) config('rag.ingestion.token_warning_threshold'),
        ));

        $this->app->singleton(EvalRunRecorder::class, fn (Container $app): EvalRunRecorder => new EvalRunRecorder(
            connection: $app->make('db')->connection(config('rag.connection')),
        ));

        $this->app->singleton('rag', fn (Container $app): RagManager => new RagManager(
            container: $app,
            embedders: $app->make(EmbedderRegistry::class),
        ));

        $this->app->alias('rag', RagManager::class);
    }

    /**
     * The reranking provider, resolved here rather than left to the SDK so the
     * single-provider rule also covers `ai.default_for_reranking`: a failover
     * list there would mix scores from different rerankers within one run.
     *
     * A blank RAG_RERANK_PROVIDER is unset. Passed on as '', the SDK would not
     * fall back to the reranking default but to `ai.default`, the text provider,
     * which usually cannot rerank, so reranking would quietly never happen.
     */
    private static function rerankProvider(): ?string
    {
        $setting = 'rag.retrieval.rerank.provider';
        $provider = self::providerName(config($setting));

        if ($provider === null) {
            $setting = 'ai.default_for_reranking';
            $provider = self::providerName(config($setting));
        }

        if (is_array($provider)) {
            throw new InvalidArgumentException(
                "{$setting} must be a single provider name for reranking; failover lists are not allowed, "
                .'because scores from different rerankers are not comparable within a run.'
            );
        }

        if ($provider !== null && ! is_string($provider)) {
            throw new InvalidArgumentException("{$setting} must be a provider name string or null.");
        }

        return $provider;
    }

    /** A Lab enum as its name, and blank as null; anything else is returned as it is, for the caller to check. */
    private static function providerName(mixed $value): mixed
    {
        $value = $value instanceof Lab ? $value->value : $value;

        return $value === '' ? null : $value;
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/rag.php' => config_path('rag.php'),
            ], 'rag-config');

            $this->commands([
                IngestCommand::class,
                EvalCommand::class,
                ReindexCommand::class,
            ]);
        }
    }
}
