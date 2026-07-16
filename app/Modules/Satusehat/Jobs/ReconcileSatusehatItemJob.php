<?php

namespace App\Modules\Satusehat\Jobs;

use App\Modules\Satusehat\Services\SatusehatSubmissionProcessingService;
use App\Support\Queue\EnterpriseQueueJob;

/**
 * Reconciles an unknown-outcome item by GETting its remote resource (if a remote
 * id was captured) and confirming existence — it NEVER re-POSTs. An unresolved
 * reconciliation stays reconciliation_required for operator review.
 */
class ReconcileSatusehatItemJob extends EnterpriseQueueJob
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
        $item = $processing->reconcileItem($this->itemId, $this->correlationId);

        if ($item !== null) {
            $processing->rollupBatch($item->submission_batch_id);
        }
    }
}
