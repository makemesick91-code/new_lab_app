<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\LegacyRme\Services\LegacyRmeSteadyStateOpsService;
use Illuminate\Console\Command;

/**
 * LEGACY-RME-STEADY-STATE-OPS-1 — the one command an operator runs before
 * opening a routine migration batch.
 *
 * WHY IT EXISTS. Answering "may I open a batch right now?" previously meant
 * running `legacy-rme:rollout-readiness`, `legacy-rme:migration-status`,
 * `legacy-rme:wave-status` and a backup check, then correlating four reports by
 * eye. This composes them into one decision and names what is missing.
 *
 * It does NOT replace those commands, and deliberately does not reprint them:
 * each remains the authority on its own domain and is where an operator goes for
 * detail. This is the pre-flight, not the encyclopedia.
 *
 * READ-ONLY. It opens nothing, writes nothing, admits nothing and repairs
 * nothing. Running it is always safe, including mid-batch and in production.
 *
 * EXIT CODES, because a monitoring hook needs them to mean something:
 *
 *   0   GO, or WATCH without --strict
 *   1   NO_GO — always. A FAIL or an UNEVALUATED check never exits success.
 *   1   WATCH, when --strict or --fail-on-warning is given
 *
 * The shared MON-1 signals are collected BY DEFAULT, because they are what
 * establishes BACKUP FRESHNESS — and a pre-flight that skips the one thing that
 * cannot be walked back is not a pre-flight. `--skip-monitoring` opts out for a
 * fast check; backup freshness then reports UNKNOWN and the decision is NO_GO by
 * design, because a batch must never be opened on the assumption that a restore
 * point exists.
 */
class LegacyRmeOpsReadinessCommand extends Command
{
    protected $signature = 'legacy-rme:ops-readiness
        {--json : Emit the full machine-readable report}
        {--strict : Treat WATCH as blocking as well as NO_GO}
        {--fail-on-warning : Alias of --strict, for parity with the foundation monitoring commands}
        {--skip-monitoring : Do not collect the shared monitoring signals (backup freshness then cannot be established)}
        {--branch= : Limit the branch readiness matrix to one branch code}';

    protected $description = 'Report whether a routine legacy RME migration batch may be opened right now (read-only)';

    public function handle(LegacyRmeSteadyStateOpsService $ops): int
    {
        $report = $ops->readiness([
            'include_monitoring' => ! (bool) $this->option('skip-monitoring'),
            'branch' => $this->option('branch'),
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($report);
        }

        $decision = (string) ($report['decision'] ?? LegacyRmeSteadyStateOpsService::DECISION_NO_GO);
        $strict = (bool) $this->option('strict') || (bool) $this->option('fail-on-warning');

        if ($decision === LegacyRmeSteadyStateOpsService::DECISION_NO_GO) {
            return self::FAILURE;
        }

        if ($decision === LegacyRmeSteadyStateOpsService::DECISION_WATCH && $strict) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): void
    {
        $this->newLine();
        $this->line(sprintf(
            '<options=bold>%s</> — legacy RME steady-state operations readiness (%s)',
            (string) ($report['sprint'] ?? ''),
            (string) ($report['environment'] ?? ''),
        ));
        $this->line(sprintf('Clinical date: %s', (string) ($report['clinical_today'] ?? 'unknown')));
        $this->newLine();

        $rows = [];
        foreach ((array) ($report['checks'] ?? []) as $check) {
            $rows[] = [
                $this->statusLabel((string) ($check['status'] ?? 'UNKNOWN')),
                (string) ($check['severity'] ?? ''),
                (string) ($check['id'] ?? ''),
                (string) ($check['summary'] ?? ''),
            ];
        }
        $this->table(['Status', 'Severity', 'Check', 'Finding'], $rows);

        foreach ((array) ($report['checks'] ?? []) as $check) {
            $status = (string) ($check['status'] ?? '');
            $remediation = $check['remediation'] ?? null;
            if (in_array($status, ['FAIL', 'WATCH', 'UNKNOWN'], true) && is_string($remediation)) {
                $this->line(sprintf('  <fg=yellow>→</> %s: %s', (string) ($check['id'] ?? ''), $remediation));
            }
        }

        $this->renderRestingState($report);
        $this->renderBranchMatrix($report);
        $this->renderStopTheLine($report);

        $this->newLine();
        $this->line(sprintf('Decision: %s', $this->decisionLabel((string) ($report['decision'] ?? 'NO_GO'))));
        $this->line(sprintf(
            'Ready for a routine batch: %s',
            ($report['ready_for_routine_batch'] ?? false) === true
                ? '<fg=green;options=bold>YES</>'
                : '<fg=red;options=bold>NO</>',
        ));

        if (($report['monitoring'] ?? null) === null) {
            $this->line('<fg=yellow>Monitoring signals were skipped — backup freshness could not be established.</>');
        }

        $this->newLine();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderRestingState(array $report): void
    {
        $resting = (array) ($report['resting_state'] ?? []);
        if ($resting === []) {
            return;
        }

        $this->newLine();
        $this->line(sprintf(
            'Resting state: <options=bold>%s</>',
            (string) ($resting['interpretation'] ?? 'UNKNOWN'),
        ));

        $deviations = (array) ($resting['deviations'] ?? []);
        if ($deviations !== []) {
            $this->line(sprintf('  Not holding: %s', implode(', ', array_map('strval', $deviations))));
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderBranchMatrix(array $report): void
    {
        $matrix = (array) ($report['branch_matrix'] ?? []);
        if ($matrix === []) {
            return;
        }

        $this->newLine();
        $this->line('<options=bold>Branch readiness</>');

        $rows = [];
        foreach ($matrix as $row) {
            $blockers = (array) ($row['blockers'] ?? []);
            $rows[] = [
                (string) ($row['branch_code'] ?? ''),
                ($row['readiness'] ?? '') === 'READY' ? '<fg=green>READY</>' : '<fg=yellow>NOT_READY</>',
                ($row['approved'] ?? false) ? 'yes' : 'no',
                ($row['admitted'] ?? false) ? 'yes' : 'no',
                (string) ($row['batch_branch_status'] ?? '—'),
                (string) ($row['daily_quota'] ?? '—'),
                $blockers === [] ? '—' : implode(', ', array_map('strval', $blockers)),
            ];
        }

        $this->table(['Branch', 'Readiness', 'Approved', 'Admitted', 'Batch status', 'Quota', 'Blockers'], $rows);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderStopTheLine(array $report): void
    {
        $codes = (array) ($report['stop_the_line'] ?? []);
        if ($codes === []) {
            return;
        }

        $this->newLine();
        $this->line('<fg=red;options=bold>STOP THE LINE</>');
        foreach ($codes as $code) {
            $this->line(sprintf('  <fg=red>■</> %s', (string) $code));
        }
        $this->line('  Pause admission and preserve evidence before any further upload.');
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'GO' => '<fg=green>GO</>',
            'WATCH' => '<fg=yellow>WATCH</>',
            'FAIL' => '<fg=red>FAIL</>',
            default => '<fg=red>UNKNOWN</>',
        };
    }

    private function decisionLabel(string $decision): string
    {
        return match ($decision) {
            'GO' => '<fg=green;options=bold>GO</>',
            'WATCH' => '<fg=yellow;options=bold>WATCH</>',
            default => '<fg=red;options=bold>NO_GO</>',
        };
    }
}
