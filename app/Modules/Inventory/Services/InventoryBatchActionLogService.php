<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\InventoryBatchActionLogRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryBatchRepositoryInterface;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryBatchActionLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class InventoryBatchActionLogService
{
    public function __construct(
        private readonly InventoryBatchActionLogRepositoryInterface $actionLogs,
        private readonly InventoryBatchRepositoryInterface $batches,
        private readonly BranchContext $branchContext,
    ) {}

    public function record(InventoryBatch $batch, string $actionType, ?string $note, ?User $actor = null): InventoryBatchActionLog
    {
        $branchId = $this->branchContext->requireId();
        $batch = $this->assertBatchAccessible($branchId, $batch->id);

        $actor ??= Auth::user();
        if (! $actor instanceof User) {
            throw new AuthorizationException('Pengguna tidak terautentikasi.');
        }

        $snapshot = $this->batches->totalStockForBatch($branchId, $batch->id);

        return $this->actionLogs->create([
            'branch_id' => $branchId,
            'inventory_batch_id' => $batch->id,
            'action_type' => $actionType,
            'note' => $note,
            'ledger_quantity_snapshot' => $snapshot,
            'acted_by' => $actor->id,
            'acted_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, InventoryBatchActionLog>
     */
    public function historyForBatch(InventoryBatch $batch, int $limit = 50): Collection
    {
        $branchId = $this->branchContext->requireId();
        $this->assertBatchAccessible($branchId, $batch->id);

        return $this->actionLogs->forBatch($branchId, $batch->id, $limit);
    }

    public function latestForBatch(InventoryBatch $batch): ?InventoryBatchActionLog
    {
        $branchId = $this->branchContext->requireId();
        $this->assertBatchAccessible($branchId, $batch->id);

        return $this->actionLogs->latestForBatch($branchId, $batch->id);
    }

    /**
     * @param  list<int>  $batchIds
     * @return Collection<int, InventoryBatchActionLog>
     */
    public function latestForBatches(array $batchIds): Collection
    {
        $branchId = $this->branchContext->requireId();

        return $this->actionLogs->latestForBatches($branchId, $batchIds);
    }

    private function assertBatchAccessible(int $branchId, int $batchId): InventoryBatch
    {
        $batch = $this->batches->findForBranch($branchId, $batchId);
        if ($batch === null) {
            throw new AuthorizationException('Batch tidak ditemukan di cabang aktif.');
        }

        return $batch;
    }
}
