<?php

namespace App\Console\Commands;

use App\Modules\Patient\Services\PatientDocumentStorageAuditService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sprint 61.3 — Patient Scan Document Storage Governance.
 *
 * Read-only audit of the private patient document store. NEVER deletes or
 * mutates anything; safe to run on production at any time.
 */
class PatientDocumentsAuditCommand extends Command
{
    protected $signature = 'patient-documents:audit
                            {--json : Output the audit summary as JSON}';

    protected $description = 'Audit private patient scan document storage (read-only, deletes nothing)';

    public function handle(PatientDocumentStorageAuditService $service): int
    {
        try {
            $result = $service->audit();
        } catch (Throwable $e) {
            if ($this->option('json')) {
                $this->line(json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $this->error('Patient document audit failed: '.$e->getMessage());
            }

            return Command::FAILURE;
        }

        $summary = $result['summary'];

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => true,
                'summary' => $summary,
                'config' => $result['config'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $this->info('Patient scan document storage audit (read-only)');
        $this->line('Disk: '.$result['config']['disk']
            .'  private_root: '.$result['config']['private_root']
            .'  temp_root: '.$result['config']['temp_root']);
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            collect($summary)->map(fn ($value, $key) => [$key, (string) $value])->values()->all(),
        );

        $anomalies = $summary['missing_files_count']
            + $summary['orphan_files_count']
            + $summary['checksum_mismatch_count']
            + $summary['mime_mismatch_count']
            + $summary['size_mismatch_count']
            + $summary['suspicious_path_count']
            + $summary['deleted_records_with_file_count']
            + $summary['oversized_files_count'];

        if ($anomalies > 0) {
            $this->warn("Detected {$anomalies} integrity/hygiene anomaly(ies). Review details above.");
        } else {
            $this->info('No integrity anomalies detected.');
        }

        if ($summary['stale_temp_files_count'] > 0) {
            $this->warn("Stale temp scans: {$summary['stale_temp_files_count']} file(s). "
                .'Run `php artisan patient-documents:prune-temp` to review/clean.');
        }

        return Command::SUCCESS;
    }
}
