<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\LabOrder\Services\LabOperationalKpiAuditService;
use Illuminate\Console\Command;

/**
 * LAB-PROD-2 — read-only Operational Analytics KPI integrity audit.
 *
 * Validates the KPI registry + canonical data sources (columns present, no
 * unknown workflow status, no impossible/negative durations, branch/timestamp
 * coverage, permission registration). PII-free. --strict exits 2 on NO_GO.
 */
final class LabOperationalKpiAuditCommand extends Command
{
    protected $signature = 'lab-workflow:operational-kpi-audit {--json} {--strict}';

    protected $description = 'Read-only Lab Workflow V2 operational KPI integrity audit (GO/WATCH/NO_GO).';

    public function handle(LabOperationalKpiAuditService $auditor): int
    {
        $report = $auditor->audit();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $decision = (string) $report['decision'];
            $this->line("Lab Operational KPI audit: <options=bold>{$decision}</>");
            $rows = [];
            foreach ($report['checks'] as $check) {
                $rows[] = [$check['key'], $check['status'], $check['detail']];
            }
            $this->table(['Check', 'Status', 'Detail'], $rows);
        }

        if ($report['decision'] === 'NO_GO') {
            return 2;
        }

        if ($report['decision'] === 'WATCH' && $this->option('strict')) {
            return 1;
        }

        return self::SUCCESS;
    }
}
