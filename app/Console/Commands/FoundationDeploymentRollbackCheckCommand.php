<?php

namespace App\Console\Commands;

use App\Services\Foundation\DeploymentRollbackGovernanceService;
use Illuminate\Console\Command;

class FoundationDeploymentRollbackCheckCommand extends Command
{
    protected $signature = 'foundation:deployment-rollback-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as FAIL}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Read-only ENT-11 Deployment & Rollback Automation governance check.';

    public function handle(DeploymentRollbackGovernanceService $service): int
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
        $this->info('Foundation Deployment & Rollback Automation Governance Check (ENT-11)');
        $this->line('Decision: '.($report['decision'] ?? 'UNKNOWN'));
        $this->line('Readiness: '.($report['readiness_status'] ?? 'unknown'));
        $this->line('Deploy script: '.(($report['deploy_script_ok'] ?? false) ? 'safe' : 'UNSAFE'));
        $this->line('  - backup before migrate: '.(($report['deploy_backup_before_migrate'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - migrate --force only: '.(($report['deploy_migrate_force_present'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - no destructive command: '.(($report['deploy_no_destructive_command'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - ENT-8 cache-order preserved: '.(($report['deploy_cache_order_preserved'] ?? false) ? 'yes' : 'NO'));
        $this->line('Rollback script: '.(($report['rollback_script_ok'] ?? false) ? 'safe' : 'UNSAFE'));
        $this->line('  - present: '.(($report['rollback_present'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - fail-fast: '.(($report['rollback_fail_fast'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - records current ref: '.(($report['rollback_records_current_ref'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - backup before checkout: '.(($report['rollback_backup_before_checkout'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - no destructive command: '.(($report['rollback_no_destructive_command'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - re-verifies foundation gates: '.(($report['rollback_reverifies_foundation_gates'] ?? false) ? 'yes' : 'NO'));
        $this->line('Evidence profiles: '.(($report['evidence_profiles_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Release-safety (rollback checklist + gate): '.(($report['release_safety_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('ENT-10 CI/CD enterprise gate: '.($report['cicd_enterprise_gate_decision'] ?? 'UNKNOWN'));
        $this->line('ENT-9 security-compliance: '.($report['security_compliance_decision'] ?? 'UNKNOWN'));
        $this->line('ENT-8 health-check: '.($report['health_check_decision'] ?? 'UNKNOWN'));
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
