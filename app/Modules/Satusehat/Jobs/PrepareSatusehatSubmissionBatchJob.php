<?php

namespace App\Modules\Satusehat\Jobs;

use App\Modules\Satusehat\Models\SatusehatSubmissionBatch;
use App\Modules\Satusehat\Models\SatusehatSubmissionItem;
use App\Modules\Satusehat\Services\SatusehatSubmissionStateMachine;
use App\Support\Queue\EnterpriseQueueJob;
use Illuminate\Support\Facades\DB;

/**
 * Entry job for an outbound batch: moves the batch to PROCESSING and dispatches
 * one {@see SubmitSatusehatItemJob} per Encounter item (dependency root). The
 * dependent Condition/Procedure items are dispatched by the Encounter item job
 * once the Encounter succeeds. Dispatched only after the queue() commit.
 */
class PrepareSatusehatSubmissionBatchJob extends EnterpriseQueueJob
{
    public function __construct(
        public readonly int $batchId,
        public readonly string $correlationId,
    ) {
        parent::__construct();
        $this->onQueue((string) config('satusehat.queue', 'satusehat'));
    }

    public function handle(): void
    {
        $encounterItemIds = DB::transaction(function () {
            /** @var SatusehatSubmissionBatch|null $batch */
            $batch = SatusehatSubmissionBatch::query()->lockForUpdate()->find($this->batchId);
            if ($batch === null) {
                return [];
            }

            if (SatusehatSubmissionStateMachine::batchCanTransition($batch->status, SatusehatSubmissionBatch::STATUS_PROCESSING)) {
                $batch->update(['status' => SatusehatSubmissionBatch::STATUS_PROCESSING, 'started_at' => now()]);
            }

            // Move sendable items to queued; return the Encounter roots.
            $batch->items()
                ->whereIn('status', [SatusehatSubmissionItem::STATUS_PREPARED])
                ->update(['status' => SatusehatSubmissionItem::STATUS_QUEUED]);

            return $batch->items()
                ->where('resource_type', SatusehatSubmissionItem::RESOURCE_ENCOUNTER)
                ->whereIn('status', [SatusehatSubmissionItem::STATUS_QUEUED, SatusehatSubmissionItem::STATUS_RETRYABLE_FAILED])
                ->pluck('id')
                ->all();
        });

        foreach ($encounterItemIds as $itemId) {
            SubmitSatusehatItemJob::dispatch((int) $itemId, $this->correlationId);
        }
    }
}
