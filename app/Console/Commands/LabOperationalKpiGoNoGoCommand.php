<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\LabOrder\Services\LabOperationalKpiAuditService;
use Illuminate\Console\Command;

/**
 * LAB-PROD-2 — Operational Analytics KPI GO/NO-GO gate.
 *
 * Thin decision wrapper over LabOperationalKpiAuditService: prints and returns
 * one GO/WATCH/NO_GO. Never prints a fake GO. --strict makes WATCH fail too
 * (exit 0 ONLY when decision === GO).
 */
final class LabOperationalKpiGoNoGoCommand extends Command
{
    protected $signature = 'lab-workflow:operational-kpi-go-no-go {--json} {--strict}';

    protected $description = 'Lab Workflow V2 operational KPI GO/NO-GO gate (exit 0 only on GO under --strict).';

    public function handle(LabOperationalKpiAuditService $auditor): int
    {
        $report = $auditor->audit();
        $decision = (string) $report['decision'];

        $failures = array_values(array_filter($report['checks'], fn ($c) => $c['status'] === 'FAIL'));
        $warnings = array_values(array_filter($report['checks'], fn ($c) => $c['status'] === 'WARN'));

        $payload = [
            'decision' => $decision,
            'fail' => array_map(fn ($c) => $c['key'], $failures),
            'watch' => array_map(fn ($c) => $c['key'], $warnings),
            'generated_at' => $report['generated_at'],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line("Lab Operational KPI GO/NO-GO: <options=bold>{$decision}</>");
            if ($failures !== []) {
                $this->error('  NO_GO: '.implode(', ', $payload['fail']));
            }
            if ($warnings !== []) {
                $this->warn('  WATCH: '.implode(', ', $payload['watch']));
            }
        }

        if ($decision === 'NO_GO') {
            return 1;
        }

        if ($decision === 'WATCH' && $this->option('strict')) {
            return 1;
        }

        return self::SUCCESS;
    }
}
