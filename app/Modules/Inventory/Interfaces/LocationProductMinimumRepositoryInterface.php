<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\LocationProductMinimum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Sprint 17.8 — per-room minimum/maximum stock threshold CONFIGURATION.
 *
 * Persistence contract only. Implementations must never read, write, or
 * calculate stock balances — current stock stays ledger-derived from
 * trx_inventory_movements (SUM(in) - SUM(out)). Every read is branch-scoped;
 * branch_id is always the first argument and is never sourced from request input.
 */
interface LocationProductMinimumRepositoryInterface
{
    /**
     * @param  array{inventory_location_id?: int, product_id?: int, is_active?: bool, search?: string}  $filters
     */
    public function paginateForBranch(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function findInBranch(int $branchId, int $id): ?LocationProductMinimum;

    public function findByLocationAndProduct(int $branchId, int $inventoryLocationId, int $productId): ?LocationProductMinimum;

    /**
     * @param  array<string, mixed>  $data  Must already contain branch_id (set by the service).
     */
    public function create(array $data): LocationProductMinimum;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LocationProductMinimum $minimum, array $data): LocationProductMinimum;

    public function delete(LocationProductMinimum $minimum): LocationProductMinimum;

    public function getActiveForLocation(int $branchId, int $inventoryLocationId): Collection;

    /**
     * @param  array{inventory_location_id?: int, product_id?: int}  $filters
     */
    public function getActiveThresholdMatrix(int $branchId, array $filters = []): Collection;
}
