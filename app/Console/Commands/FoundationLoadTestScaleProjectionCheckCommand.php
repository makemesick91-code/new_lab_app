<?php

namespace App\Console\Commands;

use App\Services\Foundation\LoadTestScaleProjectionGovernanceService;
use Illuminate\Console\Command;

class FoundationLoadTestScaleProjectionCheckCommand extends Command
{
    protected $signature = 'foundation:load-test-scale-projection-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as FAIL}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Read-only ENT-14 Load Test Scale Projection governance check.';

    public function handle(LoadTestScaleProjectionGovernanceService $service): int
    {
        $report = $service->collect();
        $decision = (string) ($report['decision'] ?? 'FAIL');

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($report);
        }

        if ($decision === 'FAIL') {
            return self::FAILURE;
        }

        if ($decision === 'WATCH' && ($this->option('strict') || $this->option('fail-on-warning'))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $this->info('Foundation Load Test Scale Projection Governance Check (ENT-14)');
        $this->line('Decision: '.($report['decision'] ?? 'UNKNOWN'));
        $this->line('Readiness: '.($report['readiness_status'] ?? 'unknown'));
        $this->line('Harness script: '.(($report['harness_script_ok'] ?? false) ? 'safe' : 'UNSAFE'));
        $this->line('  - fail-fast: '.(($report['harness_fail_fast'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - non-production guard: '.(($report['harness_non_production_guard'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - runs runner: '.(($report['harness_runs_runner'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - no destructive command: '.(($report['harness_no_destructive_command'] ?? false) ? 'yes' : 'NO'));
        $this->line('Projection runner: '.(($report['runner_registered'] ?? false) ? 'registered' : 'MISSING'));
        $this->line('Tiers: '.(($report['tiers_ok'] ?? false) ? 'ok' : 'FAIL')
            .' ('.($report['tier_count'] ?? 0).' tiers, branches '.implode('/', (array) ($report['tier_branch_counts'] ?? [])).')');
        $this->line('Baseline linkage: '.(($report['baseline_linkage_ok'] ?? false) ? 'ok' : 'FAIL')
            .' (baseline '.($report['baseline_branch_count'] ?? '?').' branches)');
        $this->line('Bottleneck taxonomy: '.(($report['bottlenecks_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Foundation linkage (LB-1/REPLICA-1): '.(($report['foundation_linkage_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Evidence profiles: '.(($report['evidence_profiles_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Release-safety (pre-deploy gate): '.(($report['release_safety_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('ENT-13 load-test baseline: '.($report['load_test_baseline_decision'] ?? 'UNKNOWN'));
        $this->line('ENT-12 backup/DR: '.($report['backup_dr_decision'] ?? 'UNKNOWN'));
        $this->newLine();

        $s = $report['summary'];
        $this->line(sprintf(
            'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['checks'] ?? 0,
            $s['passed'] ?? 0,
            $s['warnings'] ?? 0,
            $s['errors'] ?? 0,
            $s['decision'] ?? 'FAIL',
        ));

        $nonPassing = array_filter($report['checks'] ?? [], fn (array $c) => ($c['status'] ?? '') !== 'passed');
        if ($nonPassing !== []) {
            $this->newLine();
            $this->line('Non-passing checks:');
            foreach ($nonPassing as $check) {
                $this->line(sprintf('  - [%s] %s: %s', $check['status'], $check['check_id'], $check['message']));
            }
        }
    }
}
