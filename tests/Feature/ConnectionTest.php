<?php

declare(strict_types=1);

use Ikram\Rag\Documents\Chunk;
use Ikram\Rag\Documents\Document;
use Ikram\Rag\Facades\Rag;
use Ikram\Rag\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A sqlite file, so `rag` is genuinely a different database from the default
// in-memory `testing` connection.
$ragDatabase = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rag-connection-test.sqlite';

beforeAll(function () use ($ragDatabase): void {
    @unlink($ragDatabase);
    touch($ragDatabase);

    TestCase::$bootConfig = [
        'database.connections.rag' => ['driver' => 'sqlite', 'database' => $ragDatabase, 'prefix' => ''],
        'rag.connection' => 'rag',
    ];
});

afterAll(function () use ($ragDatabase): void {
    TestCase::$bootConfig = [];
    DB::purge('rag');
    @unlink($ragDatabase);
});

it('stores documents and chunks on the rag connection when it differs from the default', function (): void {
    Rag::ingest('a.md', 'alpha beta gamma');

    expect(DB::connection('rag')->table('rag_documents')->count())->toBe(1)
        ->and(DB::connection('rag')->table('rag_chunks')->count())->toBeGreaterThanOrEqual(1)
        ->and(Schema::connection('testing')->hasTable('rag_documents'))->toBeFalse()
        ->and((new Document)->getConnectionName())->toBe('rag')
        ->and((new Chunk)->getConnectionName())->toBe('rag')
        ->and(Rag::retrieve('alpha')->count())->toBeGreaterThanOrEqual(1);
});
