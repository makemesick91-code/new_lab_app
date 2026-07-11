<?php

namespace App\Console\Commands;

use App\Modules\LabOrder\Services\LabWorkflowNotificationRepairService;
use Illuminate\Console\Command;

/**
 * HOTFIX-LAB-V2-NOTIFICATION-DESTINATION-ROUTING — dry-run-first, idempotent
 * repair of Lab Workflow notifications whose stored `data.url` points at a
 * destination the recipient cannot open. Only Lab Workflow notifications are
 * scanned; only `data.url` is rewritten; read_at / created_at / notifiable /
 * title / message / lab_order_id are preserved.
 */
class LabWorkflowRepairNotificationDestinationsCommand extends Command
{
    protected $signature = 'lab-workflow:repair-notification-destinations
        {--apply : Persist the repairs (default is a safe dry-run preview)}
        {--limit= : Cap the number of notifications examined}
        {--notification-id= : Repair only this notification UUID}
        {--lab-order-id= : Repair only notifications for this lab order id}
        {--user-id= : Repair only notifications for this recipient user id}
        {--json : Output the machine-readable JSON summary}
        {--strict : Exit non-zero (2) when repairable notifications or anomalies remain}';

    protected $description = 'Repair broken Lab Workflow V2 notification destination URLs (dry-run by default).';

    public function handle(LabWorkflowNotificationRepairService $service): int
    {
        $summary = $service->run([
            'apply' => (bool) $this->option('apply'),
            'limit' => $this->option('limit') !== null ? (int) $this->option('limit') : null,
            'notification_id' => $this->option('notification-id') ?: null,
            'lab_order_id' => $this->option('lab-order-id') !== null ? (int) $this->option('lab-order-id') : null,
            'user_id' => $this->option('user-id') !== null ? (int) $this->option('user-id') : null,
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($summary);
        }

        // Strict: a dry-run with pending repairs, or any anomaly, is a signal.
        if ($this->option('strict')) {
            $pending = (int) $summary['repairable'] - (int) $summary['applied'];
            if ($pending > 0 || (int) $summary['anomalies'] > 0) {
                return 2;
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function printConsole(array $summary): void
    {
        $this->info('Lab Workflow Notification Destination Repair — '.strtoupper((string) $summary['mode']));

        $this->table(['Metric', 'Count'], [
            ['Scanned (LabWorkflowEvent)', $summary['scanned']],
            ['Repairable', $summary['repairable']],
            ['Applied', $summary['applied']],
            ['Already correct', $summary['already_correct']],
            ['Skipped — unknown event', $summary['skipped_unknown_event']],
            ['Skipped — non-V2 order', $summary['skipped_non_v2']],
            ['Skipped — missing order', $summary['skipped_missing_order']],
            ['Skipped — missing recipient', $summary['skipped_missing_recipient']],
            ['Anomalies', $summary['anomalies']],
        ]);

        if (! empty($summary['samples'])) {
            $this->line('');
            $this->line('Before → after (recipient-aware):');
            foreach ($summary['samples'] as $sample) {
                $this->line(sprintf(
                    ' - [%s] "%s" user#%s order#%s: %s -> %s',
                    $sample['id'],
                    $sample['title'],
                    $sample['notifiable_id'],
                    $sample['lab_order_id'],
                    $sample['before'] ?? '(none)',
                    $sample['after'] ?? '(link removed)',
                ));
            }
        }

        if ($summary['mode'] === 'dry-run' && (int) $summary['repairable'] > 0) {
            $this->warn('Dry-run only — re-run with --apply to persist these repairs.');
        }
    }
}
