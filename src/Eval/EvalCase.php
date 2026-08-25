<?php

declare(strict_types=1);

namespace Ikram\Rag\Eval;

use InvalidArgumentException;

/**
 * One question with a known-good answer location.
 *
 * `expectedSources` is deliberately about *where* the answer lives rather than what
 * the model says. Retrieval quality is measurable without an LLM in the loop, it is
 * cheap, it is deterministic, and it is where most RAG regressions actually happen.
 */
final class EvalCase
{
    /**
     * @param  list<string>  $expectedSources
     * @param  list<string>  $mustContain  Substrings that should appear in retrieved text
     * @param  list<string>  $mustNotContain
     */
    public function __construct(
        public readonly string $id,
        public readonly string $query,
        public readonly array $expectedSources = [],
        public readonly array $mustContain = [],
        public readonly array $mustNotContain = [],
        public readonly bool $expectNoAnswer = false,
    ) {
        if ($query === '') {
            throw new InvalidArgumentException("Eval case '{$id}' has an empty query.");
        }

        if ($expectNoAnswer && $expectedSources !== []) {
            throw new InvalidArgumentException(
                "Eval case '{$id}' expects no answer but also lists expected sources."
            );
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(string $id, array $data): self
    {
        return new self(
            id: $id,
            query: (string) ($data['query'] ?? ''),
            expectedSources: array_values((array) ($data['expected_sources'] ?? [])),
            mustContain: array_values((array) ($data['must_contain'] ?? [])),
            mustNotContain: array_values((array) ($data['must_not_contain'] ?? [])),
            expectNoAnswer: (bool) ($data['expect_no_answer'] ?? false),
        );
    }
}
