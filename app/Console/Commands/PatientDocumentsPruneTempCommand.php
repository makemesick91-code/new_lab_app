<?php

namespace App\Console\Commands;

use App\Modules\Patient\Services\PatientDocumentTempPruneService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sprint 61.3 — Patient Scan Document Storage Governance.
 *
 * Prunes STALE TEMPORARY KTP scan uploads (pre-attach) only. Dry-run by
 * default; deletion requires --force. Final attached patient documents are
 * never touched.
 */
class PatientDocumentsPruneTempCommand extends Command
{
    protected $signature = 'patient-documents:prune-temp
                            {--older-than-hours= : TTL override in hours (default: config patient_documents.temp_ttl_hours)}
                            {--force : Actually delete stale temp scans (otherwise dry-run)}
                            {--json : Output the result as JSON}';

    protected $description = 'Prune stale temporary KTP scan uploads (dry-run by default; never deletes final documents)';

    public function handle(PatientDocumentTempPruneService $service): int
    {
        $force = (bool) $this->option('force');

        try {
            $ttlHours = $this->resolveTtlHours();
            $result = $service->prune($ttlHours, $force);
        } catch (Throwable $e) {
            if ($this->option('json')) {
                $this->line(json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $this->error('Patient document temp prune failed: '.$e->getMessage());
            }

            return Command::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['ok' => true] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $this->info('Patient temp scan prune ('.($result['dry_run'] ? 'DRY-RUN' : 'FORCE').')');
        $this->line("Temp root: {$result['temp_root']}  TTL: {$result['older_than_hours']}h");

        if ($result['dry_run']) {
            $this->warn("Would delete {$result['would_delete_count']} stale temp file(s) "
                .'('.$this->humanBytes($result['would_delete_bytes']).'). '
                .'Re-run with --force to delete.');
        } else {
            $this->info("Deleted {$result['deleted_count']} stale temp file(s) "
                .'('.$this->humanBytes($result['deleted_bytes']).').');
        }

        return Command::SUCCESS;
    }

    private function resolveTtlHours(): int
    {
        $option = $this->option('older-than-hours');

        if ($option !== null && $option !== '') {
            if (! is_numeric($option)) {
                throw new \InvalidArgumentException('Invalid --older-than-hours. Must be a numeric value.');
            }

            return max(1, (int) $option);
        }

        return max(1, (int) config('patient_documents.temp_ttl_hours', 24));
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 2).' MB';
    }
}
