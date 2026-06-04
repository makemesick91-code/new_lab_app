<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\StockOpname;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Sprint 13 — Stock Opname persistence contract.
 *
 * Every read is branch-scoped (the first $branchId argument). Lookups never
 * cross a branch boundary, keeping the repository branch-safe.
 */
interface StockOpnameRepositoryInterface
{
    /**
     * @param  array{search?: string|null, status?: string|null, inventory_location_id?: int|null, date_from?: string|null, date_to?: string|null}  $filters
     */
    public function paginate(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): StockOpname;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StockOpname $opname, array $data): StockOpname;

    public function findById(int $branchId, int $id): ?StockOpname;

    public function loadItems(StockOpname $opname): StockOpname;

    /**
     * Branch-scoped read of an opname with its location and item lines (with
     * each line's product) eager-loaded — the snapshot used right before
     * finalizing/posting the count's variance.
     */
    public function finalizeLookup(int $branchId, int $id): ?StockOpname;
}
