<?php

declare(strict_types=1);

namespace Ikram\Rag\Eval;

use Ikram\Rag\Retrieval\RetrievalResult;

final class CaseResult
{
    /** @param list<string> $failures */
    public function __construct(
        public readonly EvalCase $case,
        public readonly RetrievalResult $retrieval,
        public readonly array $failures,
    ) {}

    public function passed(): bool
    {
        return $this->failures === [];
    }
}
