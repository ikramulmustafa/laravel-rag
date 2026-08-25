<?php

declare(strict_types=1);

namespace Ikram\Rag\Contracts;

interface Embedder
{
    /**
     * Embed a batch of texts. Returns one vector per input, in the same order.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array;

    /**
     * Identifier of the model producing these vectors, e.g. "openai:text-embedding-3-small".
     *
     * Stored alongside every chunk. Vectors from different models are not
     * comparable, so the retriever uses this to refuse mixed-provenance searches
     * rather than returning quietly meaningless results.
     */
    public function modelId(): string;

    /**
     * Vector dimensionality. Must match the pgvector column width.
     */
    public function dimensions(): int;

    /**
     * Approximate token count, used by the ingest cost guard before spending money.
     *
     * @param  list<string>  $texts
     */
    public function estimateTokens(array $texts): int;
}
