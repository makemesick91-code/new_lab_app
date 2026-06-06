<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\InventoryBatchRepositoryInterface;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\StockTransferItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class InventoryBatchRepository implements InventoryBatchRepositoryInterface
{
    public function paginateForBranch(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $today = now()->toDateString();
        $expiringThreshold = now()->addDays(30)->toDateString();
        $search = $filters['search'] ?? null;

        $stockSub = InventoryMovement::query()
            ->select('inventory_batch_id')
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as derived_stock')
            ->where('branch_id', $branchId)
            ->whereNotNull('inventory_batch_id')
            ->groupBy('inventory_batch_id');

        return InventoryBatch::query()
            ->with(['product.unit', 'supplier'])
            ->select('inv_inventory_batches.*')
            ->selectRaw('COALESCE(batch_stock.derived_stock, 0) as derived_stock')
            ->leftJoinSub($stockSub, 'batch_stock', function ($join) {
                $join->on('inv_inventory_batches.id', '=', 'batch_stock.inventory_batch_id');
            })
            ->where('inv_inventory_batches.branch_id', $branchId)
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('inv_inventory_batches.product_id', $v))
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('inv_inventory_batches.supplier_id', $v))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('inv_inventory_batches.is_active', (bool) $filters['is_active']))
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(inv_inventory_batches.batch_number) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(inv_inventory_batches.lot_number) LIKE ?', [$term]);
                });
            })
            ->when($filters['expiry_status'] ?? null, function ($query, $status) use ($today, $expiringThreshold) {
                match ($status) {
                    'expired' => $query
                        ->whereNotNull('inv_inventory_batches.expiry_date')
                        ->whereDate('inv_inventory_batches.expiry_date', '<', $today),
                    'expiring_soon' => $query
                        ->whereNotNull('inv_inventory_batches.expiry_date')
                        ->whereDate('inv_inventory_batches.expiry_date', '>=', $today)
                        ->whereDate('inv_inventory_batches.expiry_date', '<=', $expiringThreshold),
                    'valid' => $query->where(function ($q) use ($expiringThreshold) {
                        $q->whereNull('inv_inventory_batches.expiry_date')
                            ->orWhereDate('inv_inventory_batches.expiry_date', '>', $expiringThreshold);
                    }),
                    default => $query,
                };
            })
            ->orderByDesc('inv_inventory_batches.received_date')
            ->orderByDesc('inv_inventory_batches.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForBranch(int $branchId, int $id): ?InventoryBatch
    {
        return InventoryBatch::query()
            ->with(['product.unit', 'supplier', 'branch', 'createdBy'])
            ->where('branch_id', $branchId)
            ->find($id);
    }

    public function totalStockForBatch(int $branchId, int $batchId): float
    {
        $value = InventoryMovement::query()
            ->where('branch_id', $branchId)
            ->where('inventory_batch_id', $batchId)
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as derived_stock')
            ->value('derived_stock');

        return (float) $value;
    }

    public function stockByLocation(int $branchId, int $batchId): Collection
    {
        return InventoryMovement::query()
            ->with('inventoryLocation')
            ->select('inventory_location_id')
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as derived_stock')
            ->where('branch_id', $branchId)
            ->where('inventory_batch_id', $batchId)
            ->groupBy('inventory_location_id')
            ->orderBy('inventory_location_id')
            ->get();
    }

    public function movementsForBatch(int $branchId, int $batchId): Collection
    {
        return InventoryMovement::query()
            ->with(['inventoryLocation', 'product.unit', 'supplier', 'createdBy'])
            ->where('branch_id', $branchId)
            ->where('inventory_batch_id', $batchId)
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->get();
    }

    public function transferReferencesForBatch(int $branchId, int $batchId): Collection
    {
        return StockTransferItem::query()
            ->with([
                'stockTransfer.sourceInventoryLocation',
                'stockTransfer.destinationInventoryLocation',
            ])
            ->where('inventory_batch_id', $batchId)
            ->whereHas('stockTransfer', fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('id')
            ->get();
    }

    public function batchesWithDerivedStockForAlerts(int $branchId, ?int $locationId = null): Collection
    {
        $stockSub = InventoryMovement::query()
            ->select('inventory_batch_id')
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as derived_stock')
            ->where('branch_id', $branchId)
            ->whereNotNull('inventory_batch_id')
            ->when($locationId, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->groupBy('inventory_batch_id');

        return InventoryBatch::query()
            ->with(['product.unit'])
            ->select('inv_inventory_batches.*')
            ->selectRaw('COALESCE(batch_stock.derived_stock, 0) as derived_stock')
            ->leftJoinSub($stockSub, 'batch_stock', function ($join) {
                $join->on('inv_inventory_batches.id', '=', 'batch_stock.inventory_batch_id');
            })
            ->where('inv_inventory_batches.branch_id', $branchId)
            ->where('inv_inventory_batches.is_active', true)
            ->whereNotNull('inv_inventory_batches.expiry_date')
            ->whereRaw('COALESCE(batch_stock.derived_stock, 0) > 0')
            ->orderBy('inv_inventory_batches.expiry_date')
            ->get();
    }
}
