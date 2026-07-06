<?php

namespace App\Console\Commands;

use App\Services\Foundation\LoadTestBaselineGovernanceService;
use Illuminate\Console\Command;

class FoundationLoadTestBaselineCheckCommand extends Command
{
    protected $signature = 'foundation:load-test-baseline-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as FAIL}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Read-only ENT-13 Load Test 5 Cabang Baseline governance check.';

    public function handle(LoadTestBaselineGovernanceService $service): int
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
        $this->info('Foundation Load Test 5 Cabang Baseline Governance Check (ENT-13)');
        $this->line('Decision: '.($report['decision'] ?? 'UNKNOWN'));
        $this->line('Readiness: '.($report['readiness_status'] ?? 'unknown'));
        $this->line('Harness script: '.(($report['harness_script_ok'] ?? false) ? 'safe' : 'UNSAFE'));
        $this->line('  - fail-fast: '.(($report['harness_fail_fast'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - non-production guard: '.(($report['harness_non_production_guard'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - runs runner: '.(($report['harness_runs_runner'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - no destructive command: '.(($report['harness_no_destructive_command'] ?? false) ? 'yes' : 'NO'));
        $this->line('Baseline runner: '.(($report['runner_registered'] ?? false) ? 'registered' : 'MISSING'));
        $this->line('Dataset: '.(($report['dataset_ok'] ?? false) ? 'ok' : 'FAIL')
            .' ('.($report['target_patients_min'] ?? '?').'–'.($report['target_patients_max'] ?? '?').' patients, '.($report['branch_count'] ?? '?').' branches)');
        $this->line('Scenarios: '.(($report['scenarios_ok'] ?? false) ? 'ok' : 'FAIL').' ('.($report['scenario_count'] ?? 0).')');
        $this->line('Bottleneck taxonomy: '.(($report['bottlenecks_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Latency targets: '.(($report['objectives_ok'] ?? false) ? 'ok' : 'FAIL')
            .' (p50 '.($report['p50_target_ms'] ?? '?').'ms, p95 '.($report['p95_target_ms'] ?? '?').'ms, users '.($report['total_users'] ?? '?').')');
        $this->line('Evidence profiles: '.(($report['evidence_profiles_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Release-safety (pre-deploy gate): '.(($report['release_safety_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('ENT-12 backup/DR: '.($report['backup_dr_decision'] ?? 'UNKNOWN'));
        $this->line('ENT-11 deployment/rollback: '.($report['deployment_rollback_decision'] ?? 'UNKNOWN'));
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
