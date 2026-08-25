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
use Ikram\Rag\Embedding\FakeEmbedder;
use Ikram\Rag\Embedding\OpenAIEmbedder;
use Ikram\Rag\Eval\EvalRunRecorder;
use Ikram\Rag\Retrieval\VectorRetriever;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class RagServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rag.php', 'rag');

        $this->app->singleton(Embedder::class, function (Container $app): Embedder {
            $driver = config('rag.embedding.driver', 'openai');

            return match ($driver) {
                'openai' => new OpenAIEmbedder(
                    http: $app->make(HttpFactory::class),
                    apiKey: (string) config('rag.embedding.api_key'),
                    model: (string) config('rag.embedding.model'),
                    baseUrl: (string) config('rag.embedding.base_url'),
                    batchSize: (int) config('rag.embedding.batch_size'),
                ),
                'fake' => new FakeEmbedder((int) config('rag.embedding.dimensions', 64)),
                default => throw new InvalidArgumentException(
                    "Unknown embedding driver [{$driver}]. Supported: openai, fake."
                ),
            };
        });

        $this->app->singleton(Chunker::class, fn (): Chunker => new RecursiveChunker(
            size: (int) config('rag.chunking.size'),
            overlap: (int) config('rag.chunking.overlap'),
        ));

        $this->app->singleton(Retriever::class, fn (Container $app): Retriever => new VectorRetriever(
            embedder: $app->make(Embedder::class),
            connection: $app->make('db')->connection(config('rag.connection')),
            minScore: (float) config('rag.retrieval.min_score'),
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
            retriever: $app->make(Retriever::class),
            ingestor: $app->make(Ingestor::class),
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
