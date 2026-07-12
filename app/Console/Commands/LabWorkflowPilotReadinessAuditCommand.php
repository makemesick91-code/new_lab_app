<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\LabOrder\Services\LabWorkflowPilotReadinessAuditor;
use Illuminate\Console\Command;

/**
 * LAB-WORKFLOW-V2-PILOT-UAT-1 — read-only pilot operational readiness gate.
 *
 * Aggregates V2-flag, RME branches, external labs, eligible technicians, actor
 * availability, RBAC posture, stuck/invalid orders, orphan tasks, failed jobs
 * and storage into one GO/WATCH/NO-GO decision. No mutation. --strict exits 2
 * when any anomaly is present.
 */
final class LabWorkflowPilotReadinessAuditCommand extends Command
{
    protected $signature = 'lab-workflow:pilot-readiness-audit
        {--json}
        {--strict}
        {--branch= : Scope order-level checks to a branch id}
        {--order= : Scope order-level checks to a single order id}';

    protected $description = 'Read-only Lab Workflow V2 pilot operational readiness audit (GO/WATCH/NO-GO).';

    public function handle(LabWorkflowPilotReadinessAuditor $auditor): int
    {
        $branch = $this->option('branch');
        $order = $this->option('order');

        $report = $auditor->audit(
            $branch !== null && $branch !== '' ? (int) $branch : null,
            $order !== null && $order !== '' ? (int) $order : null,
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->printConsole($report);
        }

        $anomalies = (int) ($report['summary']['anomalies'] ?? 0);

        if ($this->option('strict') && $anomalies > 0) {
            return 2;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $decision = (string) ($report['summary']['decision'] ?? 'UNKNOWN');
        $this->line("Lab Workflow V2 pilot readiness: <options=bold>{$decision}</>");
        $this->line("  environment: {$report['environment']}   generated_at: {$report['generated_at']}");

        $rows = [];
        foreach ((array) ($report['checks'] ?? []) as $check) {
            $rows[] = [$check['key'], $check['status'], $check['detail']];
        }
        $this->table(['Check', 'Status', 'Detail'], $rows);

        $noGo = (array) ($report['summary']['no_go'] ?? []);
        if ($noGo !== []) {
            $this->error('  NO-GO: '.implode(', ', $noGo));
        }
        $watch = (array) ($report['summary']['watch'] ?? []);
        if ($watch !== []) {
            $this->warn('  WATCH: '.implode(', ', $watch));
        }
    }
}
