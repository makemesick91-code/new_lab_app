<?php

namespace App\Console\Commands;

use App\Services\Foundation\ReleaseEvidenceService;
use Illuminate\Console\Command;

/**
 * NSF-10 — Read-only release evidence capture.
 *
 * Re-runs existing governance/smoke/backup commands and writes their safe
 * JSON output as evidence artifacts under storage/ci-evidence (ci profile)
 * or storage/release-evidence/latest (vps profile). Never mutates
 * application/business data.
 */
class ReleaseEvidenceCaptureCommand extends Command
{
    protected $signature = 'release:evidence-capture
        {--profile=local : Evidence profile: local|ci|vps}
        {--base-url= : Optional base URL for HTTP smoke evidence}
        {--backup-path= : Path to the deploy backup file (required for vps profile)}
        {--json : Output JSON report}';

    protected $description = 'Read-only capture of safe release evidence artifacts for the given profile.';

    public function handle(ReleaseEvidenceService $service): int
    {
        $report = $service->capture(
            (string) $this->option('profile'),
            $this->option('base-url'),
            $this->option('backup-path'),
        );

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
        $this->info('Release Evidence Capture (NSF-10)');
        $this->line('Profile: '.($report['profile'] ?? ''));
        $this->line('Directory: '.($report['directory'] ?? ''));
        $this->newLine();

        foreach ($report['captured'] ?? [] as $item) {
            $this->line('  [written] '.$item['artifact'].' ('.$item['bytes'].' bytes)');
        }

        foreach ($report['skipped_unsafe'] ?? [] as $item) {
            $this->line('  [skipped-unsafe] '.$item['artifact']);
        }

        $this->newLine();
        $s = $report['summary'];
        $this->line(sprintf(
            'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['checks'] ?? 0, $s['passed'] ?? 0, $s['warnings'] ?? 0, $s['errors'] ?? 0, $s['decision'] ?? 'FAIL',
        ));

        $failing = array_filter($report['checks'] ?? [], fn (array $c) => ($c['status'] ?? '') === 'failed');
        foreach ($failing as $check) {
            $this->line('  - [failed] '.$check['check_id'].': '.$check['message']);
        }
    }
}
