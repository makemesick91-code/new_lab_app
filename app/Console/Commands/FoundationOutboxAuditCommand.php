<?php

namespace App\Console\Commands;

use App\Services\Foundation\OutboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * QUEUE-1 — Read-only outbox audit.
 *
 * Reports counts by status/payload classification. Safe when
 * sys_outbox_events has zero rows or does not exist yet (GO with a note).
 * Never dispatches an event.
 */
class FoundationOutboxAuditCommand extends Command
{
    protected $signature = 'foundation:outbox-audit {--json : Output JSON report}';

    protected $description = 'Read-only QUEUE-1 outbox audit (counts by status/payload classification).';

    public function handle(OutboxService $service): int
    {
        if (! Schema::hasTable('sys_outbox_events')) {
            $report = [
                'generated_at' => now()->toIso8601String(),
                'sprint' => 'QUEUE-1',
                'total_records' => 0,
                'by_status' => [],
                'by_payload_classification' => [],
                'dispatch_enabled' => (bool) config('queue_governance.outbox.dispatch_enabled', false),
                'external_dispatch_enabled' => false,
                'checks' => [[
                    'check_id' => 'OUTBOX-TABLE-EXISTS',
                    'status' => 'warning',
                    'blocking' => false,
                    'message' => 'sys_outbox_events table not migrated in this environment.',
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
            $this->info('Foundation Outbox Audit (QUEUE-1)');
            $this->line('Generated: '.($report['generated_at'] ?? ''));
            $this->line('Total records: '.($report['total_records'] ?? 0));
            $this->line('By status: '.json_encode($report['by_status'] ?? []));
            $this->line('By payload classification: '.json_encode($report['by_payload_classification'] ?? []));
            $this->line('Dispatch enabled: '.(($report['dispatch_enabled'] ?? false) ? 'yes' : 'no'));
            $this->line('External dispatch enabled: '.(($report['external_dispatch_enabled'] ?? false) ? 'yes' : 'no'));
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
