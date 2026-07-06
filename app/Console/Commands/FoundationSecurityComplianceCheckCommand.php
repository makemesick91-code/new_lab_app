<?php

namespace App\Console\Commands;

use App\Services\Foundation\SecurityComplianceGovernanceService;
use Illuminate\Console\Command;

class FoundationSecurityComplianceCheckCommand extends Command
{
    protected $signature = 'foundation:security-compliance-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as FAIL}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Read-only ENT-9 Security & PII Compliance Hardening governance check.';

    public function handle(SecurityComplianceGovernanceService $service): int
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
        $this->info('Foundation Security & PII Compliance Hardening Governance Check (ENT-9)');
        $this->line('Decision: '.($report['decision'] ?? 'UNKNOWN'));
        $this->line('Readiness: '.($report['readiness_status'] ?? 'unknown'));
        $this->line('Masking helpers: '.(($report['masking_helpers_ok'] ?? false) ? 'ok' : 'MISSING'));
        $this->line('Developer-console masking: '.(($report['developer_console_masking_enabled'] ?? false) ? 'enabled' : 'DISABLED'));
        $this->line('View scan: '.(($report['view_scan_ok'] ?? false) ? 'clean' : 'LEAK').' ('.($report['views_scanned'] ?? 0).' views)');
        $this->line('Export gating: '.(($report['export_gating_ok'] ?? false) ? 'all gated' : 'UNGATED').' ('.($report['export_routes_total'] ?? 0).' export routes)');
        $this->line('Audit table: '.(($report['audit_table_exists'] ?? false) ? 'present' : 'MISSING'));
        $this->line('Branch context: '.(($report['branch_context_exists'] ?? false) ? 'present' : 'MISSING'));
        $this->line('ENT-5 queue retry governance: '.($report['queue_retry_decision'] ?? 'UNKNOWN'));
        $this->line('ENT-6 idempotency/outbox governance: '.($report['idempotency_outbox_decision'] ?? 'UNKNOWN'));
        $this->line('ENT-7 developer-console governance: '.($report['developer_console_decision'] ?? 'UNKNOWN'));
        $this->line('ENT-8 health-check governance: '.($report['health_check_decision'] ?? 'UNKNOWN'));
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
