<?php

declare(strict_types=1);

namespace Ikram\Rag\Contracts;

use Ikram\Rag\Retrieval\RetrievalResult;

interface Retriever
{
    /**
     * Retrieve the most relevant chunks for a query.
     *
     * @param  array<string, mixed>  $filters  Metadata filters, e.g. ['collection' => 'policies']
     */
    public function retrieve(string $query, int $limit = 5, array $filters = []): RetrievalResult;
}
