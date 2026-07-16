<?php

namespace App\Modules\Satusehat\Jobs;

use App\Modules\Satusehat\Models\SatusehatSubmissionItem;
use App\Modules\Satusehat\Services\SatusehatSubmissionProcessingService;
use App\Support\Queue\EnterpriseQueueJob;

/**
 * Submits ONE resource item to the sandbox, then advances the pipeline:
 *   - On a succeeded Encounter, dispatches its dependent Condition/Procedure items.
 *   - On a retryable failure, releases for a bounded retry (backoff from config).
 *   - On an unknown outcome, dispatches reconciliation (NEVER a blind re-POST).
 * Finally rolls up the batch status. Idempotent: a settled item is a no-op.
 */
class SubmitSatusehatItemJob extends EnterpriseQueueJob
{
    public function __construct(
        public readonly int $itemId,
        public readonly string $correlationId,
    ) {
        parent::__construct();
        $this->onQueue((string) config('satusehat.queue', 'satusehat'));
    }

    public function handle(SatusehatSubmissionProcessingService $processing): void
    {
        $item = $processing->processItem($this->itemId, $this->correlationId);

        if ($item === null) {
            return;
        }

        match ($item->status) {
            SatusehatSubmissionItem::STATUS_SUCCEEDED,
            SatusehatSubmissionItem::STATUS_RECONCILED => $this->dispatchDependents($item),
            SatusehatSubmissionItem::STATUS_RETRYABLE_FAILED => $this->retry($item),
            SatusehatSubmissionItem::STATUS_UNKNOWN_OUTCOME => ReconcileSatusehatItemJob::dispatch($item->id, $this->correlationId)
                ->delay(now()->addSeconds(max(1, (int) config('satusehat.retry.base_delay_seconds', 5)))),
            default => null,
        };

        $processing->rollupBatch($item->submission_batch_id);
    }

    private function dispatchDependents(SatusehatSubmissionItem $encounter): void
    {
        if ($encounter->resource_type !== SatusehatSubmissionItem::RESOURCE_ENCOUNTER) {
            return;
        }

        SatusehatSubmissionItem::query()
            ->where('submission_batch_id', $encounter->submission_batch_id)
            ->where('satusehat_candidate_id', $encounter->satusehat_candidate_id)
            ->where('resource_type', '!=', SatusehatSubmissionItem::RESOURCE_ENCOUNTER)
            ->whereIn('status', [SatusehatSubmissionItem::STATUS_QUEUED, SatusehatSubmissionItem::STATUS_RETRYABLE_FAILED])
            ->orderBy('dependency_order')
            ->pluck('id')
            ->each(fn ($id) => SubmitSatusehatItemJob::dispatch((int) $id, $this->correlationId));
    }

    private function retry(SatusehatSubmissionItem $item): void
    {
        // Bounded by the job's enterprise tries; a scheduled sweep can pick up
        // whatever remains retryable_failed after this job's attempts are spent.
        if ($this->attempts() < ($this->tries ?? 3)) {
            $delay = $item->next_attempt_at?->isFuture()
                ? now()->diffInSeconds($item->next_attempt_at)
                : (int) config('satusehat.retry.base_delay_seconds', 5);

            $this->release(max(1, $delay));
        }
    }
}
