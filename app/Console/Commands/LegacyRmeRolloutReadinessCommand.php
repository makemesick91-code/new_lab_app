<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\LegacyRme\Services\LegacyRmeRolloutReadinessService;
use Illuminate\Console\Command;

/**
 * LEGACY-RME-PDF-ROLL-2 — the operator-facing rollout gate.
 *
 * Read-only. It reports whether the legacy RME archive may be switched ON and
 * whether it can be switched back OFF. It never enables the feature, never
 * writes a clinical row and never repairs what it finds.
 *
 * Operational use across the controlled rollout:
 *
 *   --expect=off   before enabling, and again after rolling back
 *   --expect=on    immediately after enabling, to prove the switch took effect
 *
 * `--strict` additionally treats WATCH as blocking, which is what a release
 * gate wants; the default only blocks on a real NO-GO.
 */
class LegacyRmeRolloutReadinessCommand extends Command
{
    protected $signature = 'legacy-rme:rollout-readiness
        {--json : Emit the full machine-readable report}
        {--strict : Treat WATCH as blocking as well as NO_GO}
        {--expect= : Assert the effective flag state, "off" or "on"}';

    protected $description = 'Report whether this deployment may enable, and roll back, the legacy RME archive';

    public function handle(LegacyRmeRolloutReadinessService $readiness): int
    {
        $expect = $this->option('expect');

        if ($expect !== null && ! in_array($expect, ['off', 'on'], true)) {
            $this->components->error('The --expect option accepts only "off" or "on".');

            return self::FAILURE;
        }

        $report = $readiness->report($expect === null ? null : (string) $expect);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderTable($report);
        }

        $decision = (string) $report['decision'];

        if ($decision === LegacyRmeRolloutReadinessService::DECISION_NO_GO) {
            return self::FAILURE;
        }

        if ($decision === LegacyRmeRolloutReadinessService::DECISION_WATCH && $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderTable(array $report): void
    {
        $this->newLine();
        $this->line(sprintf(
            '<options=bold>%s</> — legacy RME rollout readiness (%s)',
            (string) $report['sprint'],
            (string) $report['environment'],
        ));
        $this->newLine();

        $rows = [];

        foreach ((array) $report['checks'] as $check) {
            $rows[] = [
                $this->statusLabel((string) $check['status']),
                (string) $check['id'],
                (string) $check['summary'],
            ];
        }

        $this->table(['Status', 'Check', 'Finding'], $rows);

        foreach ((array) $report['checks'] as $check) {
            if (in_array($check['status'], ['FAIL', 'WATCH', 'UNKNOWN'], true) && $check['remediation'] !== null) {
                $this->line(sprintf('  <fg=yellow>→</> %s: %s', (string) $check['id'], (string) $check['remediation']));
            }
        }

        $this->newLine();
        $this->line(sprintf('Decision: %s', $this->decisionLabel((string) $report['decision'])));
        $this->newLine();
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
