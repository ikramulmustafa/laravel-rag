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
use Ikram\Rag\Retrieval\VectorRetriever;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

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

        $this->app->singleton(Retriever::class, fn (Container $app): Retriever => new VectorRetriever(
            embedder: $app->make(Embedder::class),
            connection: $app->make('db')->connection(config('rag.connection')),
            minScore: (float) config('rag.retrieval.min_score'),
            defaultLimit: (int) config('rag.retrieval.default_limit', 5),
        ));

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
