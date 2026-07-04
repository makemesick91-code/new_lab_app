<?php

namespace App\Console\Commands;

use App\Services\Foundation\AutomatedSmokeService;
use Illuminate\Console\Command;

/**
 * NSF-9 — Read-only automated post-deploy smoke suite.
 *
 * Never mutates data. Never requires a logged-in user. Optionally probes a
 * base URL for a healthy HTTP status (200/redirect/401/403 acceptable,
 * 500/502/503/504 fail).
 *
 * Decision → exit code:
 *  - GO    → 0
 *  - WATCH → 0 (e.g. base URL unreachable locally)
 *  - FAIL  → non-zero
 */
class ReleaseAutomatedSmokeCommand extends Command
{
    protected $signature = 'release:automated-smoke {--base-url= : Optional base URL to HTTP-probe} {--json : Output JSON report}';

    protected $description = 'Read-only automated smoke suite: app boot, routes, storage, governance commands, optional HTTP health.';

    public function handle(AutomatedSmokeService $service): int
    {
        $baseUrl = $this->option('base-url');
        $report = $service->run(is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null);
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
        $this->info('Automated Smoke (NSF-9)');
        $this->line('Generated: '.($report['generated_at'] ?? ''));
        $this->line('Mode: '.($report['mode'] ?? ''));
        if ($report['base_url'] ?? null) {
            $this->line('Base URL: '.$report['base_url']);
        }
        $this->newLine();

        foreach ($report['checks'] ?? [] as $check) {
            $this->line(sprintf('  [%s] %s: %s', $check['status'], $check['check_id'], $check['message']));
        }

        $this->newLine();
        $s = $report['summary'];
        $this->line(sprintf(
            'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['checks'] ?? 0, $s['passed'] ?? 0, $s['warnings'] ?? 0, $s['errors'] ?? 0, $s['decision'] ?? 'FAIL',
        ));
    }
}
