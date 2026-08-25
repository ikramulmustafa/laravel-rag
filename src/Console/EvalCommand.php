<?php

declare(strict_types=1);

namespace Ikram\Rag\Console;

use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Eval\CommitSha;
use Ikram\Rag\Eval\EvalReport;
use Ikram\Rag\Eval\EvalRunRecorder;
use Ikram\Rag\Eval\EvalSuite;
use Illuminate\Console\Command;
use Throwable;

final class EvalCommand extends Command
{
    protected $signature = 'rag:eval
        {--suite= : Path to the eval suite JSON (defaults to config rag.eval.suite_path)}
        {--k= : How many chunks to retrieve per case}
        {--json : Emit the report as JSON on stdout and nothing else}
        {--ci : Exit non-zero if a threshold is breached or a metric regressed}
        {--baseline= : Path to a previous --json report to compare against}
        {--save= : Write this run to a file, for use as a future baseline}
        {--no-record : Do not store this run in rag_eval_runs}';

    protected $description = 'Run the retrieval eval suite and report recall, MRR and pass rate';

    public function handle(Retriever $retriever, EvalRunRecorder $recorder): int
    {
        $path = (string) ($this->option('suite') ?: config('rag.eval.suite_path'));

        if (! is_readable($path)) {
            $this->components->error("No eval suite at {$path}");
            $this->line('');
            $this->line('  Create one as JSON:');
            $this->line('  {');
            $this->line('    "refund-window": {');
            $this->line('      "query": "How long do customers have to request a refund?",');
            $this->line('      "expected_sources": ["policies/refunds.md"]');
            $this->line('    }');
            $this->line('  }');

            return self::FAILURE;
        }

        $suite = EvalSuite::fromJsonFile(
            $path,
            (int) ($this->option('k') ?: config('rag.eval.k', 5)),
        );

        $report = $suite->run($retriever);
        $baseline = $this->baseline();

        $regressions = $baseline === null
            ? []
            : $report->regressionsAgainst($baseline, $this->tolerances());

        if ($save = $this->option('save')) {
            file_put_contents(
                (string) $save,
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
        }

        $this->record($recorder, $report);

        // --json promises stdout is parseable, so nothing human-readable goes there.
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode($report, $regressions);
        }

        $this->render($report);

        if ($baseline !== null) {
            $this->renderBaseline($report, $baseline, $regressions);
        }

        return $this->exitCode($report, $regressions);
    }

    private function render(EvalReport $report): void
    {
        $this->newLine();
        $this->components->info(sprintf(
            "Suite '%s' — %d cases at k=%d",
            $report->suite,
            count($report->results),
            $report->k,
        ));

        $thresholds = (array) config('rag.eval.thresholds', []);
        $rows = [];

        foreach ($report->metrics as $name => $value) {
            $floor = $thresholds[$name] ?? null;

            $rows[] = [
                $name,
                sprintf('%.3f', $value),
                $floor === null ? '—' : sprintf('%.3f', $floor),
                $floor === null ? '' : ($value >= $floor ? 'pass' : 'BELOW'),
            ];
        }

        $this->table(['metric', 'score', 'floor', ''], $rows);

        $failures = $report->failures();

        if ($failures === []) {
            $this->components->info('All cases passed.');

            return;
        }

        $this->newLine();
        $this->components->warn(count($failures).' case(s) failed:');

        foreach ($failures as $failure) {
            $this->newLine();
            $this->line("  <fg=red>✗</> {$failure->case->id}");
            $this->line("    query: {$failure->case->query}");

            foreach ($failure->failures as $reason) {
                $this->line("    <fg=gray>→</> {$reason}");
            }
        }

        $this->newLine();
    }

    /**
     * Load the baseline named by --baseline, or null when there is nothing to compare.
     *
     * An unreadable or malformed baseline warns rather than failing. A missing file on
     * a first run is the normal case, and failing the build over it would train people
     * to stop passing the flag.
     */
    private function baseline(): ?EvalReport
    {
        $path = (string) ($this->option('baseline') ?: '');

        if ($path === '') {
            return null;
        }

        if (! is_readable($path)) {
            $this->components->warn("Baseline not readable at {$path}, skipping comparison.");

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['metrics'])) {
            $this->components->warn('Baseline file is not a valid eval report, skipping.');

            return null;
        }

        return EvalReport::baselineFromArray($decoded);
    }

    /**
     * How far each metric may drop before it counts as a regression.
     *
     * @return array<string, float>
     */
    private function tolerances(): array
    {
        $tolerances = [];

        foreach ((array) config('rag.eval.tolerances', []) as $metric => $allowed) {
            if (is_numeric($allowed)) {
                $tolerances[(string) $metric] = (float) $allowed;
            }
        }

        return $tolerances;
    }

    /**
     * @param  list<string>  $regressions
     */
    private function renderBaseline(EvalReport $report, EvalReport $baseline, array $regressions): void
    {
        $this->newLine();
        $this->components->info('Against baseline:');

        foreach ($report->diff($baseline) as $name => $change) {
            $colour = match (true) {
                $change['delta'] < -0.001 => 'red',
                $change['delta'] > 0.001 => 'green',
                default => 'gray',
            };

            $this->line(sprintf(
                '  %-16s %.3f → %.3f  <fg=%s>%+.3f</>',
                $name,
                $change['before'],
                $change['after'],
                $colour,
                $change['delta'],
            ));
        }

        $this->newLine();

        // The same mistake as searching across models, one level up: the deltas
        // subtract cleanly and describe nothing.
        if ($baseline->embeddingModel !== '' && $baseline->embeddingModel !== $report->embeddingModel) {
            $this->components->warn(sprintf(
                'Baseline was recorded with a different embedding model (%s, now %s). '
                .'Scores from different models are not comparable; re-save the baseline.',
                $baseline->embeddingModel,
                $report->embeddingModel,
            ));
        }

        if ($regressions === []) {
            return;
        }

        $this->components->warn(count($regressions).' metric(s) regressed:');

        foreach ($regressions as $regression) {
            $this->line("  <fg=red>↓</> {$regression}");
        }

        if (! $this->option('ci')) {
            $this->line('  <fg=gray>Pass --ci to fail the build on this.</>');
        }

        $this->newLine();
    }

    /**
     * Recording history is a convenience, not the gate. A read-only database user in
     * CI should not turn a passing eval into a failing build, so this warns on stderr
     * and carries on — stderr so that --json output stays parseable.
     */
    private function record(EvalRunRecorder $recorder, EvalReport $report): void
    {
        if ($this->option('no-record')) {
            return;
        }

        try {
            $recorder->record($report, CommitSha::detect(base_path()));
        } catch (Throwable $e) {
            $this->getOutput()->getErrorStyle()->writeln(
                '<comment>Could not record this run in rag_eval_runs: '.$e->getMessage().'</comment>'
            );
        }
    }

    /**
     * @param  list<string>  $regressions
     */
    private function exitCode(EvalReport $report, array $regressions): int
    {
        if (! $this->option('ci')) {
            return self::SUCCESS;
        }

        $breached = $regressions !== [];

        foreach ((array) config('rag.eval.thresholds', []) as $metric => $minimum) {
            if ($report->metric((string) $metric) < (float) $minimum) {
                $breached = true;
            }
        }

        return $breached ? self::FAILURE : self::SUCCESS;
    }
}
