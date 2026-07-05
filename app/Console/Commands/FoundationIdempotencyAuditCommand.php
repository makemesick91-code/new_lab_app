<?php

namespace App\Console\Commands;

use App\Services\Foundation\IdempotencyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * QUEUE-1 — Read-only idempotency audit.
 *
 * Reports counts by status/scope without ever printing raw keys. Safe when
 * sys_idempotency_keys has zero rows or does not exist yet (GO with a note).
 */
class FoundationIdempotencyAuditCommand extends Command
{
    protected $signature = 'foundation:idempotency-audit {--json : Output JSON report}';

    protected $description = 'Read-only QUEUE-1 idempotency audit (counts by status/scope, no raw keys).';

    public function handle(IdempotencyService $service): int
    {
        if (! Schema::hasTable('sys_idempotency_keys')) {
            $report = [
                'generated_at' => now()->toIso8601String(),
                'sprint' => 'QUEUE-1',
                'total_records' => 0,
                'by_status' => [],
                'by_scope' => [],
                'checks' => [[
                    'check_id' => 'IDEMPOTENCY-TABLE-EXISTS',
                    'status' => 'warning',
                    'blocking' => false,
                    'message' => 'sys_idempotency_keys table not migrated in this environment.',
                ]],
                'summary' => ['decision' => 'WATCH', 'checks' => 1, 'passed' => 0, 'warnings' => 1, 'errors' => 0],
                'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
            ];
        } else {
            $report = $service->audit();
        }

        $decision = (string) ($report['summary']['decision'] ?? 'FAIL');

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Foundation Idempotency Audit (QUEUE-1)');
            $this->line('Generated: '.($report['generated_at'] ?? ''));
            $this->line('Total records: '.($report['total_records'] ?? 0));
            $this->line('By status: '.json_encode($report['by_status'] ?? []));
            $this->line('By scope: '.json_encode($report['by_scope'] ?? []));
            $this->newLine();
            $s = $report['summary'];
            $this->line(sprintf(
                'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
                $s['checks'] ?? 0, $s['passed'] ?? 0, $s['warnings'] ?? 0, $s['errors'] ?? 0, $s['decision'] ?? 'FAIL',
            ));
        }

        return $decision === 'FAIL' ? self::FAILURE : self::SUCCESS;
    }
}
