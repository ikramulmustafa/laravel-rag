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

            $provider = config('rag.retrieval.rerank.provider');
            $provider = $provider instanceof Lab ? $provider->value : $provider;

            if (is_array($provider)) {
                throw new InvalidArgumentException(
                    'rag.retrieval.rerank.provider must be a single provider name or null; failover lists are not allowed, '
                    .'because scores from different rerankers are not comparable within a run.'
                );
            }

            if ($provider !== null && ! is_string($provider)) {
                throw new InvalidArgumentException('rag.retrieval.rerank.provider must be a provider name string or null.');
            }

            $model = config('rag.retrieval.rerank.model');

            return new RerankingRetriever(
                inner: $retriever,
                provider: $provider,
                model: is_string($model) ? $model : null,
                candidates: (int) config('rag.retrieval.rerank.candidates', 3),
                defaultLimit: $limit,
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
