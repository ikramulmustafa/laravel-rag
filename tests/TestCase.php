<?php

declare(strict_types=1);

namespace Ikram\Rag\Tests;

use Ikram\Rag\RagServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Config a test file wants in place before the application boots and the
     * package migrations run. Set from beforeAll(), which runs ahead of setUp();
     * a beforeEach() would be too late, the tables would already exist on the
     * default connection.
     *
     * @var array<string, mixed>
     */
    public static array $bootConfig = [];

    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, RagServiceProvider::class];
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

        // The pgsql group runs against a real Postgres with pgvector, which CI
        // provides. Local runs and the main matrix never set DB_CONNECTION=pgsql,
        // so they stay on SQLite. Setting it moves the whole process to Postgres,
        // so only the pgsql group should be run that way.
        if (env('DB_CONNECTION') === 'pgsql') {
            $app['config']->set('database.default', 'pgsql');
            $app['config']->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'rag_test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]);
        }

        $app['config']->set('ai.default_for_embeddings', 'openai');
        $app['config']->set('ai.providers.openai.key', 'test-key');

        $app['config']->set('rag.embedding.driver', 'fake');
        $app['config']->set('rag.embedding.dimensions', 64);
        $app['config']->set('rag.chunking.size', 200);
        $app['config']->set('rag.chunking.overlap', 40);

        foreach (self::$bootConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
