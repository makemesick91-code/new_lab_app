<?php

namespace App\Console\Commands;

use App\Services\Inventory\ProcurementWorkflowAuditService;
use Illuminate\Console\Command;

/**
 * SPRINT-68.45 Scope D — read-only procurement workflow consistency audit.
 *
 * Protects the branch PR workflow, GR default batch, and vendor provenance
 * foundations. `--strict` exits non-zero (2) only on UNSAFE (FAIL) anomalies
 * (e.g. Kepala Cabang holding a PO-creation permission); data-quality notes are
 * WARN and never fail --strict. Never mutates data, never renders KTP/NIK.
 */
class InventoryProcurementWorkflowAuditCommand extends Command
{
    protected $signature = 'inventory:procurement-workflow-audit
        {--json : Output the report as JSON}
        {--strict : Exit non-zero (2) when any UNSAFE anomaly is found}';

    protected $description = 'Read-only audit of procurement workflow consistency (PR workflow, GR batch, vendor provenance). Privacy-safe.';

    public function handle(ProcurementWorkflowAuditService $service): int
    {
        $report = $service->audit();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->printConsole($report);
        }

        $errors = (int) ($report['summary']['errors'] ?? 0);

        if ($this->option('strict') && $errors > 0) {
            return 2;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $summary = $report['summary'];

        $this->info('Inventory Procurement Workflow — Consistency Audit (SPRINT-68.45)');
        $this->line('Generated: '.$report['generated_at'].' | Env: '.$report['environment']);
        $this->line(sprintf(
            'Checks: %d | PASS: %d | WARN: %d | FAIL: %d | Decision: %s',
            $summary['checks'],
            $summary['passed'],
            $summary['warnings'],
            $summary['errors'],
            $summary['decision'],
        ));
        $this->newLine();

        $rows = [];
        foreach ($report['checks'] as $check) {
            $rows[] = [
                $check['check_id'],
                $check['category'],
                $check['status'],
                $check['count'],
                $check['message'],
            ];
        }

        $this->table(['Check ID', 'Category', 'Status', 'Count', 'Message'], $rows);

        if ($summary['errors'] > 0) {
            $this->error('UNSAFE anomalies found — resolve before deploy (see FAIL rows).');
        } elseif ($summary['warnings'] > 0) {
            $this->warn('Data-quality notes found (WARN). Review, but they do not block --strict.');
        } else {
            $this->info('No anomalies detected. Procurement workflow is consistent.');
        }
    }
}
