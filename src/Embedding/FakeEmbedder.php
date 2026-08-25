<?php

declare(strict_types=1);

namespace Ikram\Rag\Embedding;

use Ikram\Rag\Contracts\Embedder;

/**
 * Deterministic, network-free embedder.
 *
 * Hashes token bag-of-words into a fixed-width vector, so texts sharing vocabulary
 * land near each other. Good enough that retrieval tests assert real ordering
 * rather than mocked return values, and fast enough to run in CI on every push.
 *
 * Not suitable for production. modelId() says so explicitly, and the retriever
 * will refuse to mix these vectors with real ones.
 */
final class FakeEmbedder implements Embedder
{
    public function __construct(private readonly int $dimensions = 64) {}

    public function embed(array $texts): array
    {
        return array_map($this->vectorise(...), array_values($texts));
    }

    /** @return list<float> */
    private function vectorise(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);
        $tokens = preg_split('/\W+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $bucket = (int) (hexdec(substr(md5($token), 0, 8)) % $this->dimensions);
            $vector[$bucket] += 1.0;
        }

        $magnitude = sqrt(array_sum(array_map(static fn (float $v): float => $v ** 2, $vector)));

        if ($magnitude === 0.0) {
            return $vector;
        }

        return array_map(static fn (float $v): float => $v / $magnitude, $vector);
    }

    public function modelId(): string
    {
        return 'fake:deterministic-hash';
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function estimateTokens(array $texts): int
    {
        return array_sum(array_map(
            static fn (string $t): int => count(preg_split('/\W+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            $texts,
        ));
    }
}
