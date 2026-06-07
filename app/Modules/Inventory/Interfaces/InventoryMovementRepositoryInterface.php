<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface InventoryMovementRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function create(array $data): InventoryMovement;

    public function transferMovements(int $branchId, StockTransfer $transfer): Collection;

    public function lockTransferMovementsForUpdate(int $branchId, StockTransfer $transfer): Collection;

    public function currentStock(int $branchId, int $productId, ?int $locationId = null): float;

    public function currentStockByBatch(int $branchId, int $productId, int $locationId, int $batchId): float;

    public function currentStockByLocation(int $branchId, int $locationId): Collection;

    public function currentStockByBranch(int $branchId): Collection;

    public function stockRows(int $branchId, ?int $locationId = null): Collection;

    public function stockByLocationSummary(int $branchId): Collection;

    public function stockCard(int $branchId, int $productId, ?int $locationId = null, array $filters = []): Collection;

    public function lowStockProducts(int $branchId, ?int $locationId = null): Collection;

    public function productsWithDerivedStock(int $branchId, ?int $locationId = null): Collection;

    public function inventoryValue(int $branchId, ?int $locationId = null): float;

    public function recentMovements(int $branchId, int $limit = 10): Collection;

    public function outboundByProductInPeriod(int $branchId, array $filters = []): Collection;

    public function lastOutboundDateByProduct(int $branchId, ?int $locationId = null): Collection;

    public function lastInboundDateByProduct(int $branchId, ?int $locationId = null): Collection;

    public function stockAtDate(int $branchId, string $asOfDate, ?int $locationId = null, ?int $productId = null): float|Collection;

    public function monthlyOutboundValue(int $branchId, array $filters = []): Collection;

    public function inventoryValueByCategory(int $branchId, ?int $locationId = null, ?int $categoryId = null): Collection;
}
