<?php

namespace App\Console\Commands;

use App\Services\Foundation\ReleaseSafetyService;
use Illuminate\Console\Command;

/**
 * NSF-9 — Read-only release safety gate check.
 *
 * Decision → exit code:
 *  - GO    → 0
 *  - WATCH → 0 (optional local evidence not yet captured)
 *  - FAIL  → non-zero (missing required gate/config/unsafe flag state)
 */
class FoundationReleaseSafetyCheckCommand extends Command
{
    protected $signature = 'foundation:release-safety-check {--json : Output JSON report}';

    protected $description = 'Read-only release safety gate validation (pre-deploy gates, evidence, rollback, flags).';

    public function handle(ReleaseSafetyService $service): int
    {
        $report = $service->collect();
        $decision = (string) ($report['summary']['decision'] ?? 'FAIL');

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($report);
        }

        return $decision === 'FAIL' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $this->info('Foundation Release Safety Check (NSF-9)');
        $this->line('Generated: '.($report['generated_at'] ?? ''));
        $this->newLine();

        $this->line('Required pre-deploy gates:');
        foreach ($report['required_pre_deploy_gates'] ?? [] as $gate) {
            $this->line('  - '.$gate);
        }

        $this->newLine();
        $s = $report['summary'];
        $this->line(sprintf(
            'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['checks'] ?? 0, $s['passed'] ?? 0, $s['warnings'] ?? 0, $s['errors'] ?? 0, $s['decision'] ?? 'FAIL',
        ));

        $failing = array_filter($report['checks'] ?? [], fn (array $c) => ($c['status'] ?? '') !== 'passed');
        if ($failing !== []) {
            $this->newLine();
            $this->line('Non-passing checks:');
            foreach ($failing as $check) {
                $this->line(sprintf('  - [%s] %s: %s', $check['status'], $check['check_id'], $check['message']));
            }
        }
    }
}
