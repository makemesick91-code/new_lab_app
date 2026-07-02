<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\InventoryBatchActionLog;
use Illuminate\Support\Collection;

interface InventoryBatchActionLogRepositoryInterface
{
    public function create(array $data): InventoryBatchActionLog;

    /**
     * @return Collection<int, InventoryBatchActionLog>
     */
    public function forBatch(int $branchId, int $batchId, int $limit = 50): Collection;

    public function latestForBatch(int $branchId, int $batchId): ?InventoryBatchActionLog;

    /**
     * @param  list<int>  $batchIds
     * @return Collection<int, InventoryBatchActionLog> keyed by inventory_batch_id
     */
    public function latestForBatches(int $branchId, array $batchIds): Collection;
}
