<?php

declare(strict_types=1);

namespace Ikram\Rag\Eval;

use Illuminate\Database\Connection;

/**
 * Stores each eval run in `rag_eval_runs`.
 *
 * A single run tells you whether retrieval is good enough today. A history tells you
 * when it stopped being good enough, which is the question you actually have when a
 * regression surfaces weeks after the commit that caused it. `--baseline` compares two
 * points; this keeps all of them.
 *
 * The embedding model is stored on every row for the same reason it is stored on every
 * chunk: scores from two different models are not comparable, so a history that does
 * not record the model is a history you can misread.
 */
final class EvalRunRecorder
{
    public function __construct(private readonly Connection $connection) {}

    public function record(EvalReport $report, ?string $commitSha = null): void
    {
        $failures = array_map(static fn (CaseResult $r): array => [
            'id' => $r->case->id,
            'query' => $r->case->query,
            'reasons' => $r->failures,
            'retrieved' => $r->retrieval->sources(),
        ], $report->failures());

        $now = now();

        $this->connection->table('rag_eval_runs')->insert([
            'suite' => $report->suite,
            'embedding_model' => $report->embeddingModel,
            'total_cases' => count($report->results),
            'metrics' => json_encode(array_map(
                static fn (float $v): float => round($v, 4),
                $report->metrics,
            )),
            'failures' => $failures === [] ? null : json_encode($failures),
            'git_sha' => $commitSha,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
