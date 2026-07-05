<?php

namespace App\Console\Commands;

use App\Services\Foundation\QueueGovernanceService;
use Illuminate\Console\Command;

/**
 * QUEUE-1 — Read-only queue/idempotency/outbox governance gate.
 *
 * Decision → exit code:
 *  - GO    → 0
 *  - WATCH → 0 (persistence tables/worker not yet present, non-blocking)
 *  - FAIL  → non-zero
 */
class FoundationQueueGovernanceCheckCommand extends Command
{
    protected $signature = 'foundation:queue-governance-check
        {--json : Output JSON report}
        {--include-worker-probe : Run optional queue connection readiness probe (never starts a long-running worker)}';

    protected $description = 'Read-only QUEUE-1 queue, idempotency, and outbox governance check.';

    public function handle(QueueGovernanceService $service): int
    {
        $report = $service->collect((bool) $this->option('include-worker-probe'));
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
        $this->info('Foundation Queue Governance Check (QUEUE-1)');
        $this->line('Generated: '.($report['generated_at'] ?? ''));
        $this->line('Queue connection: '.($report['queue_connection'] ?? 'n/a'));
        $this->line('Long-running worker enabled: '.(($report['long_running_worker_enabled'] ?? false) ? 'yes' : 'no'));
        $this->line('External dispatch flag enabled: '.(($report['external_dispatch_enabled_flag'] ?? false) ? 'yes' : 'no'));
        $this->line('Idempotency table exists: '.(($report['idempotency_table_exists'] ?? false) ? 'yes' : 'no'));
        $this->line('Outbox table exists: '.(($report['outbox_table_exists'] ?? false) ? 'yes' : 'no'));
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
