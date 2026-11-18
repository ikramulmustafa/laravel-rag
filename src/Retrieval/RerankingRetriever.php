<?php

declare(strict_types=1);

namespace Ikram\Rag\Retrieval;

use Ikram\Rag\Contracts\Retriever;
use InvalidArgumentException;
use Laravel\Ai\Reranking;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Vector search finds what is near the query; a reranker judges what answers it.
 * This wraps any retriever, over-fetches `limit * candidates`, and lets the SDK's
 * reranking model pick the best `limit`.
 *
 * Reranking is an enhancement, never a dependency: when the provider fails, the
 * search still answers, in vector order, and a warning is logged. The warning
 * carries the exception class and provider name only, never the provider's
 * response body.
 */
final class RerankingRetriever implements Retriever
{
    /** Most candidates ever fetched and sent to the reranker, whatever limit and multiplier say. */
    public const MAX_POOL = 100;

    public function __construct(
        private readonly Retriever $inner,
        private readonly ?string $provider,
        private readonly ?string $model,
        private readonly int $candidates,
        private readonly int $defaultLimit,
        private readonly LoggerInterface $logger,
    ) {
        if ($candidates < 1 || $candidates > 10) {
            throw new InvalidArgumentException('rag.retrieval.rerank.candidates must be between 1 and 10.');
        }
    }

    public function retrieve(string $query, ?int $limit = null, array $filters = []): RetrievalResult
    {
        $limit ??= $this->defaultLimit;

        if ($limit < 1) {
            throw new InvalidArgumentException('Retrieval limit must be at least 1.');
        }

        $result = $this->inner->retrieve($query, min($limit * $this->candidates, self::MAX_POOL), $filters);

        // The SDK refuses blank documents, so those citations sit out the rerank.
        $pool = array_values(array_filter(
            $result->citations,
            static fn (Citation $c): bool => trim($c->text) !== '',
        ));

        if (count($pool) < 2) {
            return $this->withCitations($result, array_slice($result->citations, 0, $limit));
        }

        try {
            $response = Reranking::of(array_map(static fn (Citation $c): string => $c->text, $pool))
                ->limit($limit)
                ->rerank($query, $this->provider, $this->model);
        } catch (Throwable $e) {
            $this->logger->warning('Reranking failed; returning vector order.', [
                'exception' => $e::class,
                'provider' => $this->provider ?? 'default',
            ]);

            return $this->withCitations($result, array_slice($result->citations, 0, $limit));
        }

        $ranked = [];
        $seen = [];

        // Deliberately not padded with unranked candidates: if the reranker returns
        // fewer than `limit`, its judgement is that the rest are not relevant enough.
        foreach ($response->results as $document) {
            if (! isset($pool[$document->index]) || isset($seen[$document->index])) {
                continue;
            }

            $seen[$document->index] = true;
            $ranked[] = $pool[$document->index]->withRerankScore($document->score);

            if (count($ranked) === $limit) {
                break;
            }
        }

        // A response with nothing usable in it is as good as a failure.
        if ($ranked === []) {
            $ranked = array_slice($result->citations, 0, $limit);
        }

        return $this->withCitations($result, $ranked);
    }

    /** @param list<Citation> $citations */
    private function withCitations(RetrievalResult $result, array $citations): RetrievalResult
    {
        return new RetrievalResult($result->query, $citations, $result->embeddingModel);
    }
}
