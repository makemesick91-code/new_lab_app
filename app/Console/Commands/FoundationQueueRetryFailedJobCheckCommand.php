<?php

namespace App\Console\Commands;

use App\Support\Queue\QueueRetryFailedJobReadinessService;
use Illuminate\Console\Command;

/**
 * ENT-5 — Read-only queue, retry & failed-job governance gate.
 *
 * Decision → exit code:
 *  - GO    → 0
 *  - WATCH → 0 (non-blocking warnings; non-zero under --strict/--fail-on-warning)
 *  - FAIL  → non-zero
 *
 * Never mutates data, never dispatches a job, never starts a worker.
 */
class FoundationQueueRetryFailedJobCheckCommand extends Command
{
    protected $signature = 'foundation:queue-retry-failed-job-check
        {--json : Output JSON report}
        {--strict : Treat warnings (WATCH) as failure}
        {--fail-on-warning : Alias of --strict}';

    protected $description = 'Read-only ENT-5 queue connection, retry standard, and failed-job storage governance check.';

    public function handle(QueueRetryFailedJobReadinessService $service): int
    {
        $report = $service->collect();
        $decision = (string) ($report['decision'] ?? 'FAIL');

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($report);
        }

        if ($decision === 'FAIL') {
            return self::FAILURE;
        }

        $strict = (bool) $this->option('strict') || (bool) $this->option('fail-on-warning');
        if ($decision === 'WATCH' && $strict) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $this->info('Foundation Queue Retry & Failed Job Governance Check (ENT-5)');
        $this->line('Generated: '.($report['generated_at'] ?? ''));
        $this->line('Environment: '.($report['environment'] ?? 'n/a'));
        $this->line('Queue connection: '.($report['queue_connection'] ?? 'n/a'));
        $this->line('Failed job driver: '.($report['failed_driver'] ?? 'n/a'));
        $this->line('Failed jobs table exists: '.(($report['failed_jobs_table_exists'] ?? false) ? 'yes' : 'no'));
        $this->line('ShouldQueue classes: '.($report['queued_classes_total'] ?? 0)
            .' (non-compliant: '.count($report['queued_classes_non_compliant'] ?? []).')');
        $this->newLine();

        $s = (array) ($report['summary'] ?? []);
        $this->line(sprintf(
            'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['checks'] ?? 0, $s['passed'] ?? 0, $s['warnings'] ?? 0, $s['errors'] ?? 0, $s['decision'] ?? 'FAIL',
        ));

        $nonPassing = array_filter((array) ($report['checks'] ?? []), fn (array $c) => ($c['status'] ?? '') !== 'passed');
        if ($nonPassing !== []) {
            $this->newLine();
            $this->line('Non-passing checks:');
            foreach ($nonPassing as $check) {
                $this->line(sprintf('  - [%s] %s: %s', $check['status'], $check['check_id'], $check['message']));
            }
        }
    }
}
