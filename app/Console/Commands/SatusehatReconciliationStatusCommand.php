<?php

namespace App\Console\Commands;

use App\Modules\Satusehat\Models\SatusehatSubmissionBatch;
use App\Modules\Satusehat\Models\SatusehatSubmissionItem;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4A — submission/reconciliation posture. Read-only. While the
 * integration is disabled all counts stay zero — reported honestly.
 */
class SatusehatReconciliationStatusCommand extends Command
{
    protected $signature = 'satusehat:reconciliation-status {--json} {--strict : Exit non-zero when items require reconciliation}';

    protected $description = 'SATUSEHAT submission batch/item + reconciliation posture — read-only';

    public function handle(): int
    {
        $items = SatusehatSubmissionItem::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')->pluck('total', 'status')->map(fn ($v) => (int) $v)->all();

        $batches = SatusehatSubmissionBatch::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')->pluck('total', 'status')->map(fn ($v) => (int) $v)->all();

        $needsReconciliation = ($items[SatusehatSubmissionItem::STATUS_UNKNOWN_OUTCOME] ?? 0)
            + ($items[SatusehatSubmissionItem::STATUS_RECONCILIATION_REQUIRED] ?? 0);

        $report = [
            'items_by_status' => $items,
            'batches_by_status' => $batches,
            'needs_reconciliation' => $needsReconciliation,
            'external_submission_enabled' => (bool) config('satusehat.send_enabled'),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('SATUSEHAT reconciliation status: '.json_encode($report));
        }

        return ($this->option('strict') && $needsReconciliation > 0) ? self::FAILURE : self::SUCCESS;
    }
}
