<?php

namespace App\Console\Commands;

use App\Services\Foundation\IdempotencyOutboxGovernanceService;
use Illuminate\Console\Command;

class FoundationIdempotencyOutboxCheckCommand extends Command
{
    protected $signature = 'foundation:idempotency-outbox-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as FAIL}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Read-only ENT-6 idempotency and outbox foundation governance check.';

    public function handle(IdempotencyOutboxGovernanceService $service): int
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

        if ($decision === 'WATCH' && ($this->option('strict') || $this->option('fail-on-warning'))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $this->info('Foundation Idempotency & Outbox Governance Check (ENT-6)');
        $this->line('Decision: '.($report['decision'] ?? 'UNKNOWN'));
        $this->line('Readiness: '.($report['readiness_status'] ?? 'unknown'));
        $this->line('Idempotency table exists: '.(($report['idempotency_table_exists'] ?? false) ? 'yes' : 'no'));
        $this->line('Outbox table exists: '.(($report['outbox_table_exists'] ?? false) ? 'yes' : 'no'));
        $this->line('External dispatch enabled: '.(($report['external_dispatch_enabled'] ?? false) ? 'yes' : 'no'));
        $this->line('QUEUE-1 governance: '.($report['queue_governance_decision'] ?? 'UNKNOWN'));
        $this->line('ENT-5 queue retry governance: '.($report['queue_retry_decision'] ?? 'UNKNOWN'));
        $this->newLine();

        $s = $report['summary'];
        $this->line(sprintf(
            'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['checks'] ?? 0,
            $s['passed'] ?? 0,
            $s['warnings'] ?? 0,
            $s['errors'] ?? 0,
            $s['decision'] ?? 'FAIL',
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
