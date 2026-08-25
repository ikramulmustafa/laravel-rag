<?php

declare(strict_types=1);

namespace Ikram\Rag;

use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Documents\IngestResult;
use Ikram\Rag\Retrieval\RetrievalResult;

/**
 * Thin front door. Deliberately thin: the interesting behaviour lives in Ingestor
 * and VectorRetriever, both of which are constructor-injectable and testable on
 * their own. This exists so `Rag::retrieve(...)` reads well in application code.
 */
final class RagManager
{
    public function __construct(
        private readonly Retriever $retriever,
        private readonly Ingestor $ingestor,
    ) {}

    /** @param array<string, mixed> $filters */
    public function retrieve(string $query, int $limit = 5, array $filters = []): RetrievalResult
    {
        return $this->retriever->retrieve($query, $limit, $filters);
    }

    /** @param array<string, mixed> $metadata */
    public function ingest(
        string $source,
        string $content,
        string $collection = 'default',
        array $metadata = [],
    ): IngestResult {
        return $this->ingestor->ingest($source, $content, $collection, $metadata);
    }

    public function retriever(): Retriever
    {
        return $this->retriever;
    }

    public function ingestor(): Ingestor
    {
        return $this->ingestor;
    }
}
