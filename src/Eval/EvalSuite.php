<?php

declare(strict_types=1);

namespace Ikram\Rag\Eval;

use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Retrieval\RetrievalResult;
use InvalidArgumentException;

/**
 * Runs a golden set of questions against the retriever and scores the results.
 *
 * The reason this ships in the package rather than being left to the application:
 * a RAG pipeline that degrades does not throw. Change the chunk size, swap a model,
 * add three hundred documents that dilute the index, and the system keeps returning
 * fluent, well-formed, confident answers built on the wrong context. Nothing pages
 * anyone. The only way to notice is to measure retrieval against known-good answers
 * on every change, which means it has to be as cheap to run as a unit test.
 */
final class EvalSuite
{
    /** @param list<EvalCase> $cases */
    public function __construct(
        public readonly string $name,
        private readonly array $cases,
        private readonly int $k = 5,
    ) {
        if ($cases === []) {
            throw new InvalidArgumentException("Eval suite '{$name}' contains no cases.");
        }
    }

    /**
     * Load from a JSON file shaped as { "case-id": { "query": "...", ... }, ... }
     */
    public static function fromJsonFile(string $path, int $k = 5): self
    {
        if (! is_readable($path)) {
            throw new InvalidArgumentException("Eval suite file not readable: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException(
                "Eval suite file is not valid JSON: {$path} (".json_last_error_msg().')'
            );
        }

        $cases = [];

        foreach ($decoded as $id => $data) {
            if (! is_array($data)) {
                throw new InvalidArgumentException("Eval case '{$id}' is not an object.");
            }

            $cases[] = EvalCase::fromArray((string) $id, $data);
        }

        return new self(basename($path, '.json'), $cases, $k);
    }

    public function run(Retriever $retriever): EvalReport
    {
        $results = [];

        foreach ($this->cases as $case) {
            $retrieval = $retriever->retrieve($case->query, $this->k);
            $results[] = new CaseResult($case, $retrieval, $this->check($case, $retrieval));
        }

        return new EvalReport(
            suite: $this->name,
            results: $results,
            metrics: $this->score($results),
            k: $this->k,
            // Provenance, for the same reason chunks carry it: a score is only
            // comparable to another score from the same embedding model.
            embeddingModel: $results[0]->retrieval->embeddingModel,
        );
    }

    /**
     * @return list<string>
     */
    private function check(EvalCase $case, RetrievalResult $retrieval): array
    {
        $failures = [];
        $sources = $retrieval->sources();
        $text = mb_strtolower(implode("\n", array_map(
            static fn ($c): string => $c->text,
            $retrieval->citations,
        )));

        if ($case->expectNoAnswer) {
            if (! $retrieval->isEmpty()) {
                $failures[] = sprintf(
                    'expected no answer, but retrieved %d chunks (top score %.3f)',
                    $retrieval->count(),
                    $retrieval->topScore(),
                );
            }

            return $failures;
        }

        foreach ($case->expectedSources as $expected) {
            if (! in_array($expected, $sources, true)) {
                $failures[] = "expected source '{$expected}' not in top-{$this->k} (got: "
                    .(($sources === []) ? 'nothing' : implode(', ', $sources)).')';
            }
        }

        foreach ($case->mustContain as $needle) {
            if (! str_contains($text, mb_strtolower($needle))) {
                $failures[] = "retrieved context missing required text: '{$needle}'";
            }
        }

        foreach ($case->mustNotContain as $needle) {
            if (str_contains($text, mb_strtolower($needle))) {
                $failures[] = "retrieved context contains forbidden text: '{$needle}'";
            }
        }

        return $failures;
    }

    /**
     * @param  list<CaseResult>  $results
     * @return array<string, float>
     */
    private function score(array $results): array
    {
        $answerable = array_values(array_filter(
            $results,
            static fn (CaseResult $r): bool => ! $r->case->expectNoAnswer,
        ));

        return [
            'pass_rate' => self::ratio(
                count(array_filter($results, static fn (CaseResult $r): bool => $r->passed())),
                count($results),
            ),
            'recall_at_k' => self::mean(array_map(self::recall(...), $answerable)),
            'mrr' => self::mean(array_map(self::reciprocalRank(...), $answerable)),
            'mean_top_score' => self::mean(array_map(
                static fn (CaseResult $r): float => $r->retrieval->topScore(),
                $answerable,
            )),
        ];
    }

    /**
     * Fraction of expected sources that appeared anywhere in the top k.
     */
    private static function recall(CaseResult $result): float
    {
        $expected = $result->case->expectedSources;

        if ($expected === []) {
            return 1.0;
        }

        $found = array_intersect($expected, $result->retrieval->sources());

        return count($found) / count($expected);
    }

    /**
     * Reciprocal of the rank of the first correct source. Rewards putting the right
     * chunk first rather than merely somewhere in the window, which matters because
     * most prompts only fit a handful of chunks.
     */
    private static function reciprocalRank(CaseResult $result): float
    {
        $expected = $result->case->expectedSources;

        if ($expected === []) {
            return 1.0;
        }

        foreach ($result->retrieval->citations as $rank => $citation) {
            if (in_array($citation->source, $expected, true)) {
                return 1 / ($rank + 1);
            }
        }

        return 0.0;
    }

    /** @param list<float> $values */
    private static function mean(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    private static function ratio(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : $numerator / $denominator;
    }
}
