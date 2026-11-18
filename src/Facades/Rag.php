<?php

declare(strict_types=1);

namespace Ikram\Rag\Facades;

use Closure;
use Ikram\Rag\Ai\SearchKnowledgeBase;
use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Documents\IngestResult;
use Ikram\Rag\RagManager;
use Ikram\Rag\Retrieval\RetrievalResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static RetrievalResult retrieve(string $query, ?int $limit = null, array<string, mixed> $filters = [])
 * @method static IngestResult ingest(string $source, string $content, string $collection = 'default', array<string, mixed> $metadata = [])
 * @method static SearchKnowledgeBase tool(?string $collection = null, array<string, mixed> $filters = [], list<string> $allowedFilters = [], ?int $limit = null, ?string $name = null, ?string $description = null, int $maxChars = 8000)
 * @method static void extend(string $driver, Closure $factory)
 * @method static Retriever retriever()
 * @method static Ingestor ingestor()
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
