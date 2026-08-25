<?php

declare(strict_types=1);

namespace Ikram\Rag\Eval;

use Ikram\Rag\Exceptions\EvalThresholdNotMet;
use JsonSerializable;

final class EvalReport implements JsonSerializable
{
    /**
     * Decimal places a score is written and compared at.
     *
     * A saved baseline is rounded on the way to disk, so comparing it at full float
     * precision measures the rounding, not the retrieval: 0.766666… reloaded as 0.7667
     * is a -0.0000333 "drop", and with a zero tolerance that fails the build on every
     * run forever. Both sides of a diff are rounded here so a comparison can only
     * report a difference the file format can actually hold.
     */
    private const PRECISION = 4;

    /**
     * @param  list<CaseResult>  $results
     * @param  array<string, float>  $metrics
     */
    public function __construct(
        public readonly string $suite,
        public readonly array $results,
        public readonly array $metrics,
        public readonly int $k,
        public readonly string $embeddingModel = '',
    ) {}

    /**
     * Rebuild a report from a saved `--json` run, for use as the right-hand side of
     * a diff.
     *
     * A saved run records the scores, not the retrievals that produced them, so the
     * result carries no case results and `passed()` means nothing on it. That is why
     * this is a separate named constructor rather than an overload of the normal one:
     * a baseline exists to be compared against, and for nothing else.
     *
     * The embedding model is read back too. Comparing scores across two different
     * embedding models is the same category of mistake as searching across them —
     * the numbers subtract cleanly and mean nothing.
     *
     * @param  array<string, mixed>  $decoded
     */
    public static function baselineFromArray(array $decoded): self
    {
        $metrics = [];

        foreach ((array) ($decoded['metrics'] ?? []) as $name => $value) {
            if (is_numeric($value)) {
                $metrics[(string) $name] = (float) $value;
            }
        }

        return new self(
            suite: (string) ($decoded['suite'] ?? 'baseline'),
            results: [],
            metrics: $metrics,
            k: (int) ($decoded['k'] ?? 0),
            embeddingModel: (string) ($decoded['embedding_model'] ?? ''),
        );
    }

    public function metric(string $name): float
    {
        return $this->metrics[$name] ?? 0.0;
    }

    /** @return list<CaseResult> */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (CaseResult $r): bool => ! $r->passed(),
        ));
    }

    public function passed(): bool
    {
        return $this->failures() === [];
    }

    /**
     * Assert minimum scores, throwing with a message that names what regressed.
     *
     * @param  array<string, float>  $thresholds
     */
    public function assertThresholds(array $thresholds): void
    {
        $breaches = [];

        foreach ($thresholds as $metric => $minimum) {
            $actual = $this->metric($metric);

            if ($actual < $minimum) {
                $breaches[] = sprintf('%s = %.3f (minimum %.3f)', $metric, $actual, $minimum);
            }
        }

        if ($breaches !== []) {
            throw new EvalThresholdNotMet(
                "Eval suite '{$this->suite}' below threshold: ".implode('; ', $breaches)
            );
        }
    }

    /**
     * Compare against a previous run. Absolute thresholds catch "this is bad";
     * deltas catch "this got worse", which is the more common and more useful signal
     * once a corpus is large enough that no absolute number is obviously right.
     *
     * @return array<string, array{before: float, after: float, delta: float}>
     */
    public function diff(self $baseline): array
    {
        $diff = [];

        foreach ($this->metrics as $name => $after) {
            $before = round($baseline->metric($name), self::PRECISION);
            $after = round($after, self::PRECISION);

            $diff[$name] = [
                'before' => $before,
                'after' => $after,
                'delta' => round($after - $before, self::PRECISION),
            ];
        }

        return $diff;
    }

    /**
     * @param  array<string, float>  $tolerances  Metric => how far it may drop
     * @return list<string>
     */
    public function regressionsAgainst(self $baseline, array $tolerances = []): array
    {
        $regressions = [];

        foreach ($this->diff($baseline) as $name => $change) {
            $allowed = -abs($tolerances[$name] ?? 0.0);

            if ($change['delta'] < $allowed) {
                $regressions[] = sprintf(
                    '%s dropped %.4f (%.4f -> %.4f)',
                    $name,
                    abs($change['delta']),
                    $change['before'],
                    $change['after'],
                );
            }
        }

        return $regressions;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'suite' => $this->suite,
            'k' => $this->k,
            'embedding_model' => $this->embeddingModel,
            'total_cases' => count($this->results),
            'passed' => count($this->results) - count($this->failures()),
            'metrics' => array_map(
                static fn (float $v): float => round($v, self::PRECISION),
                $this->metrics,
            ),
            'failures' => array_map(static fn (CaseResult $r): array => [
                'id' => $r->case->id,
                'query' => $r->case->query,
                'reasons' => $r->failures,
                'retrieved' => $r->retrieval->sources(),
            ], $this->failures()),
        ];
    }
}
