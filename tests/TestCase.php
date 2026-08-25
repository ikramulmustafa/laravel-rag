<?php

declare(strict_types=1);

namespace Ikram\Rag\Tests;

use Ikram\Rag\RagServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RagServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // SQLite in memory with the deterministic embedder. The entire suite runs
        // with no network and no API key, which is what makes it viable to run on
        // every push rather than nightly.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('rag.embedding.driver', 'fake');
        $app['config']->set('rag.embedding.dimensions', 64);
        $app['config']->set('rag.chunking.size', 200);
        $app['config']->set('rag.chunking.overlap', 40);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
