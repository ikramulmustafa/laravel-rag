<?php

declare(strict_types=1);

namespace Ikram\Rag;

use Closure;
use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Documents\Ingestor;
use Ikram\Rag\Documents\IngestResult;
use Ikram\Rag\Embedding\EmbedderRegistry;
use Ikram\Rag\Retrieval\RetrievalResult;
use Illuminate\Contracts\Container\Container;

/**
 * Thin front door. Deliberately thin: the interesting behaviour lives in Ingestor
 * and VectorRetriever, both of which are constructor-injectable and testable on
 * their own. This exists so `Rag::retrieve(...)` reads well in application code.
 *
 * The retriever and ingestor are fetched when used, not when this is built, so
 * that `Rag::extend()` in a service provider does not resolve the embedder
 * before the driver it names has been registered.
 */
final class RagManager
{
    public function __construct(
        private readonly Container $container,
        private readonly EmbedderRegistry $embedders,
    ) {}

    /** @param array<string, mixed> $filters */
    public function retrieve(string $query, ?int $limit = null, array $filters = []): RetrievalResult
    {
        return $this->retriever()->retrieve($query, $limit, $filters);
    }

    /** @param array<string, mixed> $metadata */
    public function ingest(
        string $source,
        string $content,
        string $collection = 'default',
        array $metadata = [],
    ): IngestResult {
        return $this->ingestor()->ingest($source, $content, $collection, $metadata);
    }

    /**
     * Register an embedding driver for `rag.embedding.driver` to name. Call it
     * from a service provider's register() or boot(): the embedder is a
     * singleton, and one already built keeps the driver it was built with.
     *
     * @param  Closure(Container): Embedder  $factory
     */
    public function extend(string $driver, Closure $factory): void
    {
        $this->embedders->extend($driver, $factory);
    }

    public function retriever(): Retriever
    {
        return $this->container->make(Retriever::class);
    }

    public function ingestor(): Ingestor
    {
        return $this->container->make(Ingestor::class);
    }
}
