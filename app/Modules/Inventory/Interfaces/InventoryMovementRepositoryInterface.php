<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface InventoryMovementRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function create(array $data): InventoryMovement;

    public function currentStock(int $branchId, int $productId, ?int $locationId = null): float;

    public function currentStockByLocation(int $branchId, int $locationId): Collection;

    public function currentStockByBranch(int $branchId): Collection;

    public function stockRows(int $branchId, ?int $locationId = null): Collection;

    public function stockByLocationSummary(int $branchId): Collection;

    public function stockCard(int $branchId, int $productId, ?int $locationId = null, array $filters = []): Collection;

    public function lowStockProducts(int $branchId, ?int $locationId = null): Collection;

    public function inventoryValue(int $branchId, ?int $locationId = null): float;

    public function recentMovements(int $branchId, int $limit = 10): Collection;
}
