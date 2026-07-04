<?php

namespace App\Console\Commands;

use App\Services\Foundation\BackupVerificationService;
use Illuminate\Console\Command;

/**
 * NSF-10 — Read-only backup verification gate.
 *
 * Decision → exit code:
 *  - GO    → 0
 *  - WATCH → 0 (optional metadata unavailable, e.g. stale or unrecognized header)
 *  - FAIL  → non-zero (missing/zero-byte/outside-allowed-directory backup)
 */
class FoundationBackupVerifyCommand extends Command
{
    protected $signature = 'foundation:backup-verify {--path= : Path to the backup file to verify} {--json : Output JSON report}';

    protected $description = 'Read-only verification that a database backup file exists, is safely located, and is non-empty.';

    public function handle(BackupVerificationService $service): int
    {
        $report = $service->verify($this->option('path'));
        $decision = (string) ($report['summary']['decision'] ?? 'FAIL');

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($report);
        }

        return $decision === 'FAIL' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $this->info('Foundation Backup Verify (NSF-10)');
        $this->line('Generated: '.($report['generated_at'] ?? ''));
        $this->line('Path: '.($report['path'] ?? 'n/a'));
        $this->line('Size: '.($report['size_bytes'] ?? 'n/a').' bytes');
        $this->newLine();

        $s = $report['summary'];
        $this->line(sprintf(
            'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['checks'] ?? 0, $s['passed'] ?? 0, $s['warnings'] ?? 0, $s['errors'] ?? 0, $s['decision'] ?? 'FAIL',
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
