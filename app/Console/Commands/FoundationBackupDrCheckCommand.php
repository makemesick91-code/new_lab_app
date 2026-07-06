<?php

namespace App\Console\Commands;

use App\Services\Foundation\BackupDrGovernanceService;
use Illuminate\Console\Command;

class FoundationBackupDrCheckCommand extends Command
{
    protected $signature = 'foundation:backup-dr-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as FAIL}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Read-only ENT-12 Backup & Disaster Recovery Automation governance check.';

    public function handle(BackupDrGovernanceService $service): int
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
        $this->info('Foundation Backup & Disaster Recovery Automation Governance Check (ENT-12)');
        $this->line('Decision: '.($report['decision'] ?? 'UNKNOWN'));
        $this->line('Readiness: '.($report['readiness_status'] ?? 'unknown'));
        $this->line('Backup script: '.(($report['backup_script_ok'] ?? false) ? 'safe' : 'UNSAFE'));
        $this->line('  - fail-fast: '.(($report['backup_fail_fast'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - verified: '.(($report['backup_verify_present'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - retention prune: '.(($report['backup_retention_present'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - no destructive command: '.(($report['backup_no_destructive_command'] ?? false) ? 'yes' : 'NO'));
        $this->line('Restore rehearsal: '.(($report['restore_rehearsal_ok'] ?? false) ? 'safe' : 'UNSAFE'));
        $this->line('  - present: '.(($report['restore_rehearsal_present'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - fail-fast: '.(($report['restore_rehearsal_fail_fast'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - non-production scratch guard: '.(($report['restore_rehearsal_non_production_guard'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - records evidence: '.(($report['restore_rehearsal_records_evidence'] ?? false) ? 'yes' : 'NO'));
        $this->line('  - never restores over production: '.(($report['restore_rehearsal_no_production_restore'] ?? false) ? 'yes' : 'NO'));
        $this->line('Objectives: '.(($report['objectives_ok'] ?? false) ? 'ok' : 'FAIL')
            .' (RTO '.($report['rto_minutes'] ?? '?').'m, RPO '.($report['rpo_minutes'] ?? '?').'m, retention '.($report['retention_days'] ?? '?').'d)');
        $this->line('Evidence profiles: '.(($report['evidence_profiles_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Release-safety (pre-deploy gate): '.(($report['release_safety_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('ENT-11 deployment/rollback: '.($report['deployment_rollback_decision'] ?? 'UNKNOWN'));
        $this->line('ENT-10 CI/CD enterprise gate: '.($report['cicd_enterprise_gate_decision'] ?? 'UNKNOWN'));
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
