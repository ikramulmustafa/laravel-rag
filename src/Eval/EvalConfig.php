<?php

declare(strict_types=1);

namespace Ikram\Rag\Eval;

use Ikram\Rag\Contracts\Embedder;

/**
 * The settings an eval ran with, as one comparable array.
 *
 * A score is a function of the corpus, the model and these knobs. Recording only
 * the model lets a baseline silently differ in chunk size or k and still produce
 * deltas that subtract cleanly and describe nothing. This is read from config at
 * run time, so it lives apart from EvalReport, which stays framework-free.
 */
final class EvalConfig
{
    /** @return array<string, mixed> */
    public static function current(Embedder $embedder, int $k): array
    {
        return [
            'k' => $k,
            'chunk_size' => (int) config('rag.chunking.size', 1000),
            'chunk_overlap' => (int) config('rag.chunking.overlap', 200),
            'min_score' => (float) config('rag.retrieval.min_score', 0.0),
            'rerank' => [
                'enabled' => (bool) config('rag.retrieval.rerank.enabled', false),
                'provider' => self::nullableString(config('rag.retrieval.rerank.provider')),
                'model' => self::nullableString(config('rag.retrieval.rerank.model')),
                'candidates' => (int) config('rag.retrieval.rerank.candidates', 3),
            ],
            'embedding_model' => $embedder->modelId(),
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
