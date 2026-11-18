<?php

declare(strict_types=1);

use Ikram\Rag\Tests\TestCase;
use Illuminate\Support\Facades\DB;

$otherDatabase = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rag-migration-guard.sqlite';

beforeAll(function () use ($otherDatabase): void {
    @unlink($otherDatabase);
    touch($otherDatabase);
});

afterAll(function () use ($otherDatabase): void {
    TestCase::$bootConfig = [];
    DB::purge('other');
    @unlink($otherDatabase);
});

it('refuses to run when the tables live on the default connection but RAG_CONNECTION points elsewhere', function () use ($otherDatabase): void {
    // The tables were migrated on `testing`. Pointing rag.connection at an empty
    // database now mimics an upgrade where RAG_CONNECTION was added afterwards.
    config()->set('database.connections.other', ['driver' => 'sqlite', 'database' => $otherDatabase, 'prefix' => '']);
    config()->set('rag.connection', 'other');

    $migration = require __DIR__.'/../../database/migrations/2026_11_19_000000_add_content_and_eval_config.php';

    try {
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'RAG_CONNECTION');
    } finally {
        // Teardown rolls the migrations back, and that has to hit the real tables.
        config()->set('rag.connection', null);
    }
});
