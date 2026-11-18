<?php

declare(strict_types=1);

namespace Ikram\Rag\Retrieval;

use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Retrieval always travels with its citations.
 *
 * There is no API here for "just give me the context string" without also carrying
 * where each piece came from. An answer you cannot trace back to a source is the
 * thing that makes LLM failures invisible, so tracing is not optional.
 *
 * @implements IteratorAggregate<int, Citation>
 */
final class RetrievalResult implements Countable, IteratorAggregate, JsonSerializable
{
    /** @param list<Citation> $citations */
    public function __construct(
        public readonly string $query,
        public readonly array $citations,
        public readonly string $embeddingModel,
    ) {}

    public function isEmpty(): bool
    {
        return $this->citations === [];
    }

    public function count(): int
    {
        return count($this->citations);
    }

    public function getIterator(): Traversable
    {
        yield from $this->citations;
    }

    /** @return list<int> */
    public function documentIds(): array
    {
        return array_values(array_unique(
            array_map(static fn (Citation $c): int => $c->documentId, $this->citations)
        ));
    }

    /** @return list<string> */
    public function sources(): array
    {
        return array_values(array_unique(
            array_map(static fn (Citation $c): string => $c->source, $this->citations)
        ));
    }

    /**
     * Highest vector score, not the first citation's: a reranker reorders citations,
     * so position no longer says which one matched the query most closely.
     */
    public function topScore(): float
    {
        return $this->citations === []
            ? 0.0
            : max(array_map(static fn (Citation $c): float => $c->score, $this->citations));
    }

    /**
     * Context block for a prompt, with source markers so the model can cite and
     * so a groundedness check has something to verify against.
     */
    public function toPrompt(): string
    {
        $blocks = [];

        foreach ($this->citations as $i => $citation) {
            $n = $i + 1;
            $blocks[] = "[{$n}] source: {$citation->source}\n{$citation->text}";
        }

        return implode("\n\n", $blocks);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'query' => $this->query,
            'embedding_model' => $this->embeddingModel,
            'count' => $this->count(),
            'citations' => $this->citations,
        ];
    }
}
