<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\InventoryBatch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface InventoryBatchRepositoryInterface
{
    public function paginateForBranch(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function findForBranch(int $branchId, int $id): ?InventoryBatch;

    public function totalStockForBatch(int $branchId, int $batchId): float;

    public function stockByLocation(int $branchId, int $batchId): Collection;

    public function movementsForBatch(int $branchId, int $batchId): Collection;

    public function transferReferencesForBatch(int $branchId, int $batchId): Collection;

    public function batchesWithDerivedStockForAlerts(int $branchId, ?int $locationId = null): Collection;

    public function batchStockWithAge(int $branchId, ?int $locationId = null): Collection;
}
