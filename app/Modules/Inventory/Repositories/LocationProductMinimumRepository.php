<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\LocationProductMinimumRepositoryInterface;
use App\Modules\Inventory\Models\LocationProductMinimum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Persistence + query composition only for per-room stock thresholds.
 *
 * Stores threshold CONFIGURATION only. Never reads, writes, or computes stock
 * balances — current stock stays ledger-derived from trx_inventory_movements.
 * Every read is branch-scoped to prevent cross-branch leakage; branch_id always
 * comes from the (service-supplied) first argument, never from request input.
 */
class LocationProductMinimumRepository implements LocationProductMinimumRepositoryInterface
{
    /**
     * @param  array{inventory_location_id?: int, product_id?: int, is_active?: bool, search?: string}  $filters
     */
    public function paginateForBranch(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->branchScopedQuery($branchId)
            ->with(['branch', 'inventoryLocation', 'product', 'createdBy'])
            ->tap(fn ($query) => $this->applyFilters($query, $filters))
            ->orderBy('inventory_location_id')
            ->orderBy('product_id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findInBranch(int $branchId, int $id): ?LocationProductMinimum
    {
        return $this->branchScopedQuery($branchId)
            ->with(['branch', 'inventoryLocation', 'product', 'createdBy'])
            ->find($id);
    }

    public function findByLocationAndProduct(int $branchId, int $inventoryLocationId, int $productId): ?LocationProductMinimum
    {
        return $this->branchScopedQuery($branchId)
            ->where('inventory_location_id', $inventoryLocationId)
            ->where('product_id', $productId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data  Must already contain branch_id (set by the service).
     */
    public function create(array $data): LocationProductMinimum
    {
        return LocationProductMinimum::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LocationProductMinimum $minimum, array $data): LocationProductMinimum
    {
        $minimum->update($data);

        return $minimum->refresh();
    }

    /**
     * Config-table convention (see ProductCategoryRepository): threshold rows are
     * deactivated, not hard-deleted, preserving history and the (branch, location,
     * product) audit trail while removing the row from active threshold reads.
     */
    public function delete(LocationProductMinimum $minimum): LocationProductMinimum
    {
        $minimum->update(['is_active' => false]);

        return $minimum->refresh();
    }

    public function getActiveForLocation(int $branchId, int $inventoryLocationId): Collection
    {
        return $this->branchScopedQuery($branchId)
            ->where('inventory_location_id', $inventoryLocationId)
            ->where('is_active', true)
            ->with(['product'])
            ->orderBy('product_id')
            ->get();
    }

    /**
     * Active threshold configs for later room_stock report integration.
     * Returns base configuration rows with product + location relations only —
     * no current stock is calculated or attached here (ledger invariant).
     *
     * @param  array{inventory_location_id?: int, product_id?: int}  $filters
     */
    public function getActiveThresholdMatrix(int $branchId, array $filters = []): Collection
    {
        return $this->branchScopedQuery($branchId)
            ->where('is_active', true)
            ->when($filters['inventory_location_id'] ?? null, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->with(['inventoryLocation', 'product'])
            ->orderBy('inventory_location_id')
            ->orderBy('product_id')
            ->get();
    }

    private function branchScopedQuery(int $branchId): Builder
    {
        return LocationProductMinimum::query()->where('branch_id', $branchId);
    }

    /**
     * @param  array{inventory_location_id?: int, product_id?: int, is_active?: bool, search?: string}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['inventory_location_id'] ?? null, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $q->whereHas('product', function ($pq) use ($term) {
                    $pq->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                });
            });
    }
}
