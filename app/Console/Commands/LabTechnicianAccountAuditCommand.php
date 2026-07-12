<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Technician\Services\TechnicianAccountAuditor;
use Illuminate\Console\Command;

/**
 * LAB-WORKFLOW-V2-PILOT-UAT-1 — read-only technician account audit.
 *
 * Surfaces assignment eligibility (the documented pilot blocker) + orphan/
 * mis-roled/inactive/duplicate technician links. No mutation. --strict exits 2
 * on any anomaly.
 */
final class LabTechnicianAccountAuditCommand extends Command
{
    protected $signature = 'lab:technician-account-audit {--json} {--strict}';

    protected $description = 'Read-only audit of technician accounts + Lab V2 assignment eligibility. Privacy-safe.';

    public function handle(TechnicianAccountAuditor $auditor): int
    {
        $report = $auditor->audit();

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
        $this->line("Technician account audit: <options=bold>{$decision}</>");
        $this->line("  environment: {$report['environment']}   generated_at: {$report['generated_at']}");
        $this->line(sprintf(
            '  technicians: %d total, %d active, %d linked, <options=bold>%d ELIGIBLE</>',
            $report['technician_count'],
            $report['active_technician_count'],
            $report['linked_technician_count'],
            $report['eligible_technician_count'],
        ));

        $rows = [];
        foreach ((array) ($report['technicians'] ?? []) as $t) {
            $rows[] = [
                $t['id'],
                $t['code'],
                $t['name'],
                $t['user_id'] ?? '—',
                $t['eligible'] ? 'yes' : 'no',
                $t['issues'] === [] ? '—' : implode(', ', $t['issues']),
            ];
        }
        if ($rows !== []) {
            $this->table(['ID', 'Code', 'Name', 'User', 'Eligible', 'Issues'], $rows);
        }

        $codes = (array) ($report['summary']['anomaly_codes'] ?? []);
        if ($codes !== []) {
            $this->warn('  anomalies: '.implode(', ', $codes));
        }
        $critical = (array) ($report['summary']['critical_codes'] ?? []);
        if ($critical !== []) {
            $this->error('  CRITICAL: '.implode(', ', $critical));
        }
    }
}
