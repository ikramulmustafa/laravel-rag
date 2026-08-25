<?php

declare(strict_types=1);

namespace Ikram\Rag\Facades;

use Ikram\Rag\Documents\IngestResult;
use Ikram\Rag\RagManager;
use Ikram\Rag\Retrieval\RetrievalResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static RetrievalResult retrieve(string $query, int $limit = 5, array<string, mixed> $filters = [])
 * @method static IngestResult ingest(string $source, string $content, string $collection = 'default', array<string, mixed> $metadata = [])
 *
 * @see RagManager
 */
final class Rag extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'rag';
    }
}
