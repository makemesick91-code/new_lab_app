<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\LabCapacity\Services\LabTechnicianCapacityAuditService;
use Illuminate\Console\Command;

/**
 * LAB-PROD-3 — read-only Technician Capacity Planning integrity audit.
 *
 * Validates capacity/workload/capability/availability configuration, effective
 * windows, orphan relations, unknown workflow status, permission/route
 * readiness and the LAB-PROD-2 dependency. PII-free. --strict exits 2 on NO_GO,
 * 1 on WATCH.
 */
final class LabTechnicianCapacityAuditCommand extends Command
{
    protected $signature = 'lab-workflow:technician-capacity-audit {--json} {--strict}';

    protected $description = 'Read-only Lab Workflow V2 technician capacity planning integrity audit (GO/WATCH/NO_GO).';

    public function handle(LabTechnicianCapacityAuditService $auditor): int
    {
        $report = $auditor->audit();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $decision = (string) $report['decision'];
            $this->line("Lab Technician Capacity audit: <options=bold>{$decision}</>");
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
