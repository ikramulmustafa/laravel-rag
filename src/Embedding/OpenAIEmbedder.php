<?php

declare(strict_types=1);

namespace Ikram\Rag\Embedding;

use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Exceptions\EmbeddingFailed;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

final class OpenAIEmbedder implements Embedder
{
    private const DIMENSIONS = [
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
    ];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly string $model = 'text-embedding-3-small',
        private readonly string $baseUrl = 'https://api.openai.com/v1',
        private readonly int $batchSize = 96,
        private readonly int $timeout = 30,
    ) {}

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $vectors = [];

        foreach (array_chunk($texts, $this->batchSize) as $batch) {
            foreach ($this->embedBatch(array_values($batch)) as $vector) {
                $vectors[] = $vector;
            }
        }

        if (count($vectors) !== count($texts)) {
            throw new EmbeddingFailed(sprintf(
                'Embedding count mismatch: sent %d texts, received %d vectors. '
                .'Refusing to continue, since misaligned vectors would corrupt the index silently.',
                count($texts),
                count($vectors),
            ));
        }

        return $vectors;
    }

    /**
     * @param  list<string>  $batch
     * @return list<list<float>>
     */
    private function embedBatch(array $batch): array
    {
        $response = $this->request()->post('/embeddings', [
            'model' => $this->model,
            'input' => $batch,
        ]);

        if ($response->failed()) {
            throw new EmbeddingFailed(
                "OpenAI embeddings request failed with HTTP {$response->status()}: {$response->body()}"
            );
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw new EmbeddingFailed('OpenAI response contained no data array.');
        }

        // The API documents order preservation but does not guarantee it under
        // retries, so sort by the returned index rather than trusting position.
        usort($data, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        return array_map(
            static fn (array $item): array => array_map(floatval(...), $item['embedding']),
            $data,
        );
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->timeout($this->timeout)
            ->retry(3, 1000, throw: false);
    }

    public function modelId(): string
    {
        return "openai:{$this->model}";
    }

    public function dimensions(): int
    {
        return self::DIMENSIONS[$this->model] ?? 1536;
    }

    public function estimateTokens(array $texts): int
    {
        // Deliberately rough. ~4 characters per token holds well enough for English
        // prose to catch "you are about to spend real money" before it happens.
        // It is not a substitute for the provider's own accounting.
        $characters = array_sum(array_map(mb_strlen(...), $texts));

        return (int) ceil($characters / 4);
    }
}
