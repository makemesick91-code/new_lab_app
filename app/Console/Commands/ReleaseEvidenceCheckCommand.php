<?php

namespace App\Console\Commands;

use App\Services\Foundation\ReleaseEvidenceService;
use Illuminate\Console\Command;

/**
 * NSF-10 — Read-only release evidence check.
 *
 * Validates that the required evidence artifacts for a profile exist, are
 * non-empty, safe, and recent enough. Also persists its own decision as
 * release-evidence-check.json in the profile's evidence directory so the
 * check result itself becomes part of the evidence trail.
 *
 * Decision → exit code:
 *  - GO    → 0
 *  - WATCH → 0 (optional artifacts missing)
 *  - FAIL  → non-zero (required artifact missing/empty/unsafe/stale)
 */
class ReleaseEvidenceCheckCommand extends Command
{
    protected $signature = 'release:evidence-check
        {--profile=local : Evidence profile: local|ci|vps}
        {--json : Output JSON report}';

    protected $description = 'Read-only validation that required release evidence artifacts exist, are safe, and are recent enough.';

    public function handle(ReleaseEvidenceService $service): int
    {
        $profile = (string) $this->option('profile');
        $report = $service->check($profile);

        $this->persistSelf($report);

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
    private function persistSelf(array $report): void
    {
        $directory = $report['directory'] ?? null;
        if (! is_string($directory) || $directory === '') {
            return;
        }

        $absolute = base_path($directory);
        if (! is_dir($absolute)) {
            return;
        }

        file_put_contents(
            $absolute.DIRECTORY_SEPARATOR.'release-evidence-check.json',
            (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $this->info('Release Evidence Check (NSF-10)');
        $this->line('Profile: '.($report['profile'] ?? ''));
        $this->line('Directory: '.($report['directory'] ?? 'n/a'));
        $this->newLine();

        foreach ($report['artifacts'] ?? [] as $artifact) {
            $status = $artifact['exists'] ? 'present' : 'missing';
            $this->line(sprintf('  [%s]%s %s', $status, $artifact['required'] ? ' (required)' : ' (optional)', $artifact['artifact']));
        }

        $this->newLine();
        $s = $report['summary'];
        $this->line(sprintf(
            'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['checks'] ?? 0, $s['passed'] ?? 0, $s['warnings'] ?? 0, $s['errors'] ?? 0, $s['decision'] ?? 'FAIL',
        ));

        $nonPassing = array_filter($report['checks'] ?? [], fn (array $c) => ($c['status'] ?? '') !== 'passed');
        foreach ($nonPassing as $check) {
            $this->line(sprintf('  - [%s] %s: %s', $check['status'], $check['check_id'], $check['message']));
        }
    }
}
