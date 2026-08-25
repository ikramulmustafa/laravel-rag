<?php

declare(strict_types=1);

namespace Ikram\Rag\Retrieval;

use Ikram\Rag\Contracts\Embedder;
use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Exceptions\MixedEmbeddingModels;
use Illuminate\Database\Connection;

final class VectorRetriever implements Retriever
{
    public function __construct(
        private readonly Embedder $embedder,
        private readonly Connection $connection,
        private readonly float $minScore = 0.0,
    ) {}

    public function retrieve(string $query, int $limit = 5, array $filters = []): RetrievalResult
    {
        $this->guardAgainstMixedModels();

        [$vector] = $this->embedder->embed([$query]);

        $rows = $this->connection->getDriverName() === 'pgsql'
            ? $this->searchWithPgvector($vector, $limit, $filters)
            : $this->searchInPhp($vector, $limit, $filters);

        $citations = [];

        foreach ($rows as $row) {
            if ($row->score < $this->minScore) {
                continue;
            }

            $citations[] = new Citation(
                chunkId: (int) $row->id,
                documentId: (int) $row->document_id,
                source: (string) $row->source,
                text: (string) $row->text,
                position: (int) $row->position,
                score: (float) $row->score,
            );
        }

        return new RetrievalResult(
            query: $query,
            citations: $citations,
            embeddingModel: $this->embedder->modelId(),
        );
    }

    /**
     * A corpus embedded with more than one model is not searchable. Cosine distance
     * between vectors from different models is a real number with no meaning, so the
     * query returns results, they rank plausibly, and they are wrong.
     *
     * This is the failure this package exists to prevent, so it throws rather than warns.
     */
    private function guardAgainstMixedModels(): void
    {
        $models = $this->connection->table('rag_chunks')
            ->distinct()
            ->pluck('embedding_model')
            ->all();

        if ($models === []) {
            return;
        }

        $current = $this->embedder->modelId();

        if (count($models) > 1) {
            throw new MixedEmbeddingModels(sprintf(
                'Corpus contains vectors from %d different embedding models (%s). '
                .'Distances between them are meaningless. Re-run `php artisan rag:reindex` '
                .'to rebuild the corpus with a single model.',
                count($models),
                implode(', ', $models),
            ));
        }

        if ($models[0] !== $current) {
            throw new MixedEmbeddingModels(sprintf(
                'Corpus was embedded with "%s" but the configured embedder is "%s". '
                .'Searching across models returns confident nonsense. Either restore the '
                .'previous model in config/rag.php, or run `php artisan rag:reindex`.',
                $models[0],
                $current,
            ));
        }
    }

    /**
     * @param  list<float>  $vector
     * @param  array<string, mixed>  $filters
     * @return list<object>
     */
    private function searchWithPgvector(array $vector, int $limit, array $filters): array
    {
        $literal = Vector::toLiteral($vector);

        $query = $this->connection->table('rag_chunks as c')
            ->join('rag_documents as d', 'd.id', '=', 'c.document_id')
            ->selectRaw(
                'c.id, c.document_id, c.text, c.position, d.source, '
                .'1 - (c.embedding <=> ?::vector) as score',
                [$literal],
            )
            ->orderByRaw('c.embedding <=> ?::vector', [$literal])
            ->limit($limit);

        $this->applyFilters($query, $filters);

        return $query->get()->all();
    }

    /**
     * Portable fallback for SQLite and MySQL. Loads candidates into memory, so it is
     * only appropriate for test suites and small corpora. Production wants Postgres
     * with pgvector; the README says so and this comment is the second warning.
     *
     * @param  list<float>  $vector
     * @param  array<string, mixed>  $filters
     * @return list<object>
     */
    private function searchInPhp(array $vector, int $limit, array $filters): array
    {
        $query = $this->connection->table('rag_chunks as c')
            ->join('rag_documents as d', 'd.id', '=', 'c.document_id')
            ->select('c.id', 'c.document_id', 'c.text', 'c.position', 'c.embedding', 'd.source');

        $this->applyFilters($query, $filters);

        $scored = [];

        foreach ($query->get() as $row) {
            $stored = json_decode((string) $row->embedding, true);

            if (! is_array($stored)) {
                continue;
            }

            $row->score = self::cosine($vector, array_map(floatval(...), $stored));
            unset($row->embedding);
            $scored[] = $row;
        }

        usort($scored, static fn (object $a, object $b): int => $b->score <=> $a->score);

        return array_slice($scored, 0, $limit);
    }

    /**
     * `collection` lives on the document; anything else is looked up in the chunk's
     * metadata JSON.
     *
     * The metadata path is written as `c.metadata->key` and left to the query grammar
     * to translate — `->>` spelled out by hand only works on Postgres, and on SQLite
     * it is parsed as part of the column name, matching nothing and silently returning
     * an empty result rather than erroring.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(mixed $query, array $filters): void
    {
        foreach ($filters as $column => $value) {
            $target = $column === 'collection' ? 'd.collection' : "c.metadata->{$column}";

            is_array($value)
                ? $query->whereIn($target, $value)
                : $query->where($target, $value);
        }
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        foreach ($a as $i => $value) {
            $other = $b[$i] ?? 0.0;
            $dot += $value * $other;
            $magA += $value ** 2;
            $magB += $other ** 2;
        }

        $denominator = sqrt($magA) * sqrt($magB);

        return $denominator === 0.0 ? 0.0 : $dot / $denominator;
    }
}
