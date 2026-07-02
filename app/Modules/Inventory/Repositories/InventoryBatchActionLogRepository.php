<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\InventoryBatchActionLogRepositoryInterface;
use App\Modules\Inventory\Models\InventoryBatchActionLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryBatchActionLogRepository implements InventoryBatchActionLogRepositoryInterface
{
    public function create(array $data): InventoryBatchActionLog
    {
        return InventoryBatchActionLog::create($data);
    }

    public function forBatch(int $branchId, int $batchId, int $limit = 50): Collection
    {
        return InventoryBatchActionLog::query()
            ->with('actor')
            ->where('branch_id', $branchId)
            ->where('inventory_batch_id', $batchId)
            ->orderByDesc('acted_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function latestForBatch(int $branchId, int $batchId): ?InventoryBatchActionLog
    {
        return InventoryBatchActionLog::query()
            ->with('actor')
            ->where('branch_id', $branchId)
            ->where('inventory_batch_id', $batchId)
            ->orderByDesc('acted_at')
            ->orderByDesc('id')
            ->first();
    }

    public function latestForBatches(int $branchId, array $batchIds): Collection
    {
        if ($batchIds === []) {
            return collect();
        }

        $latestIds = DB::table('trx_inventory_batch_action_logs')
            ->selectRaw('MAX(id) as id')
            ->where('branch_id', $branchId)
            ->whereIn('inventory_batch_id', $batchIds)
            ->groupBy('inventory_batch_id')
            ->pluck('id');

        if ($latestIds->isEmpty()) {
            return collect();
        }

        return InventoryBatchActionLog::query()
            ->with('actor')
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('inventory_batch_id');
    }
}
