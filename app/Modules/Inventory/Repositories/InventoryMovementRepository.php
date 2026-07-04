<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Support\InventoryMovementBatchGuard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryMovementRepository implements InventoryMovementRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return InventoryMovement::query()
            ->with(['inventoryLocation', 'product.unit', 'supplier', 'createdBy'])
            ->where('branch_id', $branchId)
            ->when($filters['inventory_location_id'] ?? null, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($filters['movement_type'] ?? null, fn ($q, $v) => $q->where('movement_type', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('movement_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('movement_date', '<=', $v))
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(array $data): InventoryMovement
    {
        InventoryMovementBatchGuard::assert($data);

        return InventoryMovement::create($data);
    }

    public function transferMovements(int $branchId, StockTransfer $transfer): Collection
    {
        return InventoryMovement::query()
            ->with(['product', 'inventoryLocation', 'inventoryBatch'])
            ->where('branch_id', $branchId)
            ->where('reference_type', $transfer->getTable())
            ->where('reference_id', $transfer->id)
            ->whereIn('movement_type', [
                InventoryMovement::TYPE_TRANSFER_OUT,
                InventoryMovement::TYPE_TRANSFER_IN,
            ])
            ->orderBy('id')
            ->get();
    }

    public function lockTransferMovementsForUpdate(int $branchId, StockTransfer $transfer): Collection
    {
        return InventoryMovement::query()
            ->where('branch_id', $branchId)
            ->where('reference_type', $transfer->getTable())
            ->where('reference_id', $transfer->id)
            ->whereIn('movement_type', [
                InventoryMovement::TYPE_TRANSFER_OUT,
                InventoryMovement::TYPE_TRANSFER_IN,
            ])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function currentStock(int $branchId, int $productId, ?int $locationId = null): float
    {
        $value = InventoryMovement::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->when($locationId, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as current_stock')
            ->value('current_stock');

        return (float) $value;
    }

    public function currentStockByBatch(int $branchId, int $productId, int $locationId, int $batchId): float
    {
        $value = InventoryMovement::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('inventory_location_id', $locationId)
            ->where('inventory_batch_id', $batchId)
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as current_stock')
            ->value('current_stock');

        return (float) $value;
    }

    public function currentStockByLocation(int $branchId, int $locationId): Collection
    {
        return InventoryMovement::query()
            ->select('product_id')
            ->selectRaw('SUM(quantity_in) - SUM(quantity_out) as current_stock')
            ->where('branch_id', $branchId)
            ->where('inventory_location_id', $locationId)
            ->groupBy('product_id')
            ->get();
    }

    public function currentStockByBranch(int $branchId): Collection
    {
        return InventoryMovement::query()
            ->select('product_id')
            ->selectRaw('SUM(quantity_in) - SUM(quantity_out) as current_stock')
            ->where('branch_id', $branchId)
            ->groupBy('product_id')
            ->get();
    }

    public function stockRows(int $branchId, ?int $locationId = null): Collection
    {
        return InventoryMovement::query()
            ->with(['product.category', 'product.unit', 'inventoryLocation'])
            ->select('product_id', 'inventory_location_id')
            ->selectRaw('SUM(quantity_in) - SUM(quantity_out) as current_stock')
            ->where('branch_id', $branchId)
            ->when($locationId, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->groupBy('product_id', 'inventory_location_id')
            ->orderBy('inventory_location_id')
            ->orderBy('product_id')
            ->get();
    }

    public function getCurrentStockReport(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $stockExpression = 'COALESCE(SUM(trx_inventory_movements.quantity_in) - SUM(trx_inventory_movements.quantity_out), 0)';

        $query = InventoryMovement::query()
            ->join('mst_branches', 'mst_branches.id', '=', 'trx_inventory_movements.branch_id')
            ->join('inv_products', 'inv_products.id', '=', 'trx_inventory_movements.product_id')
            ->join('inv_product_categories', 'inv_product_categories.id', '=', 'inv_products.product_category_id')
            ->join('inv_product_units', 'inv_product_units.id', '=', 'inv_products.product_unit_id')
            ->join('inv_inventory_locations', 'inv_inventory_locations.id', '=', 'trx_inventory_movements.inventory_location_id')
            ->where('trx_inventory_movements.branch_id', $branchId)
            ->where('inv_products.branch_id', $branchId)
            ->where('inv_product_categories.branch_id', $branchId)
            ->where('inv_inventory_locations.branch_id', $branchId)
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('trx_inventory_movements.product_id', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('inv_products.product_category_id', $v))
            ->when($filters['inventory_location_id'] ?? null, fn ($q, $v) => $q->where('trx_inventory_movements.inventory_location_id', $v))
            ->select([
                'trx_inventory_movements.branch_id',
                'mst_branches.name as branch_name',
                'trx_inventory_movements.product_id',
                'inv_products.code as product_code',
                'inv_products.name as product_name',
                'inv_product_categories.name as category_name',
                'inv_product_units.name as unit_name',
                'inv_product_units.symbol as unit_symbol',
                'trx_inventory_movements.inventory_location_id',
                'inv_inventory_locations.name as inventory_location_name',
                'inv_products.minimum_stock',
            ])
            ->selectRaw("{$stockExpression} as current_stock")
            ->selectRaw("CASE
                WHEN {$stockExpression} <= 0 THEN 'empty'
                WHEN {$stockExpression} > 0 AND {$stockExpression} < inv_products.minimum_stock THEN 'low'
                ELSE 'normal'
            END as stock_status")
            ->selectRaw('MAX(trx_inventory_movements.movement_date) as last_movement_date')
            ->groupBy([
                'trx_inventory_movements.branch_id',
                'mst_branches.name',
                'trx_inventory_movements.product_id',
                'inv_products.code',
                'inv_products.name',
                'inv_product_categories.name',
                'inv_product_units.name',
                'inv_product_units.symbol',
                'trx_inventory_movements.inventory_location_id',
                'inv_inventory_locations.name',
                'inv_products.minimum_stock',
            ]);

        match ($filters['stock_status'] ?? null) {
            'empty' => $query->havingRaw("{$stockExpression} <= 0"),
            'low' => $query
                ->havingRaw("{$stockExpression} > 0")
                ->havingRaw("{$stockExpression} < inv_products.minimum_stock"),
            'normal' => $query->havingRaw("{$stockExpression} >= inv_products.minimum_stock"),
            'overstock' => $query->havingRaw('1 = 0'),
            default => null,
        };

        return $query
            ->orderBy('inv_products.name')
            ->orderByDesc('last_movement_date')
            ->paginate($perPage, ['*'], 'current_stock_page')
            ->withQueryString();
    }

    public function getLowStockReport(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        if (in_array($filters['stock_status'] ?? null, ['normal', 'overstock'], true)) {
            return InventoryMovement::query()
                ->whereRaw('1 = 0')
                ->paginate($perPage, ['*'], 'low_stock_page')
                ->withQueryString();
        }

        $stockExpression = 'COALESCE(SUM(trx_inventory_movements.quantity_in) - SUM(trx_inventory_movements.quantity_out), 0)';
        $shortageExpression = "CASE
            WHEN inv_products.minimum_stock - {$stockExpression} < 0 THEN 0
            ELSE inv_products.minimum_stock - {$stockExpression}
        END";

        $query = InventoryMovement::query()
            ->join('mst_branches', 'mst_branches.id', '=', 'trx_inventory_movements.branch_id')
            ->join('inv_products', 'inv_products.id', '=', 'trx_inventory_movements.product_id')
            ->join('inv_product_categories', 'inv_product_categories.id', '=', 'inv_products.product_category_id')
            ->join('inv_product_units', 'inv_product_units.id', '=', 'inv_products.product_unit_id')
            ->join('inv_inventory_locations', 'inv_inventory_locations.id', '=', 'trx_inventory_movements.inventory_location_id')
            ->where('trx_inventory_movements.branch_id', $branchId)
            ->where('inv_products.branch_id', $branchId)
            ->where('inv_products.is_active', true)
            ->where('inv_product_categories.branch_id', $branchId)
            ->where('inv_inventory_locations.branch_id', $branchId)
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('trx_inventory_movements.product_id', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('inv_products.product_category_id', $v))
            ->when($filters['inventory_location_id'] ?? null, fn ($q, $v) => $q->where('trx_inventory_movements.inventory_location_id', $v))
            ->select([
                'trx_inventory_movements.branch_id',
                'mst_branches.name as branch_name',
                'trx_inventory_movements.product_id',
                'inv_products.code as product_code',
                'inv_products.name as product_name',
                'inv_product_categories.name as category_name',
                'inv_product_units.name as unit_name',
                'inv_product_units.symbol as unit_symbol',
                'trx_inventory_movements.inventory_location_id',
                'inv_inventory_locations.name as inventory_location_name',
                'inv_products.minimum_stock',
            ])
            ->selectRaw("{$stockExpression} as current_stock")
            ->selectRaw("{$shortageExpression} as shortage_qty")
            ->selectRaw("CASE
                WHEN {$stockExpression} <= 0 THEN 'empty'
                WHEN {$stockExpression} > 0 AND {$stockExpression} < inv_products.minimum_stock THEN 'low'
                ELSE 'normal'
            END as stock_status")
            ->selectRaw('MAX(trx_inventory_movements.movement_date) as last_movement_date')
            ->groupBy([
                'trx_inventory_movements.branch_id',
                'mst_branches.name',
                'trx_inventory_movements.product_id',
                'inv_products.code',
                'inv_products.name',
                'inv_product_categories.name',
                'inv_product_units.name',
                'inv_product_units.symbol',
                'trx_inventory_movements.inventory_location_id',
                'inv_inventory_locations.name',
                'inv_products.minimum_stock',
            ]);

        match ($filters['stock_status'] ?? null) {
            'empty' => $query->havingRaw("{$stockExpression} <= 0"),
            'low' => $query
                ->havingRaw("{$stockExpression} > 0")
                ->havingRaw("{$stockExpression} < inv_products.minimum_stock"),
            default => $query->havingRaw("({$stockExpression} <= 0 OR ({$stockExpression} > 0 AND {$stockExpression} < inv_products.minimum_stock))"),
        };

        return $query
            ->orderByRaw("CASE WHEN {$stockExpression} <= 0 THEN 0 ELSE 1 END")
            ->orderByDesc('shortage_qty')
            ->orderBy('inv_products.name')
            ->paginate($perPage, ['*'], 'low_stock_page')
            ->withQueryString();
    }

    public function getStockMutationReport(int $branchId, array $filters, string $dateFrom, string $dateTo, int $perPage = 15): LengthAwarePaginator
    {
        $period = InventoryMovement::query()
            ->select([
                'trx_inventory_movements.branch_id',
                'trx_inventory_movements.product_id',
                'trx_inventory_movements.inventory_location_id',
            ])
            ->selectRaw('COALESCE(SUM(trx_inventory_movements.quantity_in), 0) as total_in')
            ->selectRaw('COALESCE(SUM(trx_inventory_movements.quantity_out), 0) as total_out')
            ->where('trx_inventory_movements.branch_id', $branchId)
            ->whereDate('trx_inventory_movements.movement_date', '>=', $dateFrom)
            ->whereDate('trx_inventory_movements.movement_date', '<=', $dateTo)
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('trx_inventory_movements.product_id', $v))
            ->when($filters['inventory_location_id'] ?? null, fn ($q, $v) => $q->where('trx_inventory_movements.inventory_location_id', $v))
            ->when($filters['movement_type'] ?? null, fn ($q, $v) => $q->where('trx_inventory_movements.movement_type', $v))
            ->groupBy([
                'trx_inventory_movements.branch_id',
                'trx_inventory_movements.product_id',
                'trx_inventory_movements.inventory_location_id',
            ]);

        // Opening balance is the true stock position before the period; movement_type only filters period totals.
        $opening = InventoryMovement::query()
            ->select([
                'trx_inventory_movements.branch_id',
                'trx_inventory_movements.product_id',
                'trx_inventory_movements.inventory_location_id',
            ])
            ->selectRaw('COALESCE(SUM(trx_inventory_movements.quantity_in) - SUM(trx_inventory_movements.quantity_out), 0) as opening_balance')
            ->where('trx_inventory_movements.branch_id', $branchId)
            ->whereDate('trx_inventory_movements.movement_date', '<', $dateFrom)
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('trx_inventory_movements.product_id', $v))
            ->when($filters['inventory_location_id'] ?? null, fn ($q, $v) => $q->where('trx_inventory_movements.inventory_location_id', $v))
            ->groupBy([
                'trx_inventory_movements.branch_id',
                'trx_inventory_movements.product_id',
                'trx_inventory_movements.inventory_location_id',
            ]);

        return DB::query()
            ->fromSub($period, 'period_movements')
            ->leftJoinSub($opening, 'opening_movements', function ($join) {
                $join->on('opening_movements.branch_id', '=', 'period_movements.branch_id')
                    ->on('opening_movements.product_id', '=', 'period_movements.product_id')
                    ->on('opening_movements.inventory_location_id', '=', 'period_movements.inventory_location_id');
            })
            ->join('mst_branches', 'mst_branches.id', '=', 'period_movements.branch_id')
            ->join('inv_products', 'inv_products.id', '=', 'period_movements.product_id')
            ->join('inv_product_categories', 'inv_product_categories.id', '=', 'inv_products.product_category_id')
            ->join('inv_product_units', 'inv_product_units.id', '=', 'inv_products.product_unit_id')
            ->join('inv_inventory_locations', 'inv_inventory_locations.id', '=', 'period_movements.inventory_location_id')
            ->where('period_movements.branch_id', $branchId)
            ->where('inv_products.branch_id', $branchId)
            ->where('inv_product_categories.branch_id', $branchId)
            ->where('inv_inventory_locations.branch_id', $branchId)
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('inv_products.product_category_id', $v))
            ->select([
                'period_movements.branch_id',
                'mst_branches.name as branch_name',
                'period_movements.product_id',
                'inv_products.code as product_code',
                'inv_products.name as product_name',
                'inv_product_categories.name as category_name',
                'inv_product_units.name as unit_name',
                'inv_product_units.symbol as unit_symbol',
                'period_movements.inventory_location_id',
                'inv_inventory_locations.name as inventory_location_name',
            ])
            ->selectRaw('COALESCE(opening_movements.opening_balance, 0) as opening_balance')
            ->selectRaw('period_movements.total_in as total_in')
            ->selectRaw('period_movements.total_out as total_out')
            ->selectRaw('(COALESCE(opening_movements.opening_balance, 0) + period_movements.total_in - period_movements.total_out) as ending_balance')
            ->orderBy('inv_products.name')
            ->orderBy('inv_inventory_locations.name')
            ->paginate($perPage, ['*'], 'mutation_page')
            ->withQueryString();
    }

    public function getInventoryValuationReport(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $stockExpression = 'COALESCE(SUM(trx_inventory_movements.quantity_in) - SUM(trx_inventory_movements.quantity_out), 0)';
        $unitCostExpression = 'COALESCE(inv_products.average_cost, 0)';

        return InventoryMovement::query()
            ->join('mst_branches', 'mst_branches.id', '=', 'trx_inventory_movements.branch_id')
            ->join('inv_products', 'inv_products.id', '=', 'trx_inventory_movements.product_id')
            ->join('inv_product_categories', 'inv_product_categories.id', '=', 'inv_products.product_category_id')
            ->join('inv_product_units', 'inv_product_units.id', '=', 'inv_products.product_unit_id')
            ->join('inv_inventory_locations', 'inv_inventory_locations.id', '=', 'trx_inventory_movements.inventory_location_id')
            ->where('trx_inventory_movements.branch_id', $branchId)
            ->where('inv_products.branch_id', $branchId)
            ->where('inv_product_categories.branch_id', $branchId)
            ->where('inv_inventory_locations.branch_id', $branchId)
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('trx_inventory_movements.product_id', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('inv_products.product_category_id', $v))
            ->when($filters['inventory_location_id'] ?? null, fn ($q, $v) => $q->where('trx_inventory_movements.inventory_location_id', $v))
            ->select([
                'trx_inventory_movements.branch_id',
                'mst_branches.name as branch_name',
                'trx_inventory_movements.product_id',
                'inv_products.code as product_code',
                'inv_products.name as product_name',
                'inv_product_categories.name as category_name',
                'inv_product_units.name as unit_name',
                'inv_product_units.symbol as unit_symbol',
                'trx_inventory_movements.inventory_location_id',
                'inv_inventory_locations.name as inventory_location_name',
            ])
            ->selectRaw("{$stockExpression} as current_stock")
            // Sprint 17.7 operational valuation: product average_cost only, not FIFO/LIFO/WAC.
            ->selectRaw("{$unitCostExpression} as unit_cost")
            ->selectRaw("({$stockExpression} * {$unitCostExpression}) as total_value")
            ->selectRaw("CASE
                WHEN {$unitCostExpression} > 0 THEN 'average_cost produk'
                ELSE 'Cost produk tidak tersedia'
            END as valuation_source")
            ->selectRaw('MAX(trx_inventory_movements.movement_date) as last_movement_date')
            ->groupBy([
                'trx_inventory_movements.branch_id',
                'mst_branches.name',
                'trx_inventory_movements.product_id',
                'inv_products.code',
                'inv_products.name',
                'inv_product_categories.name',
                'inv_product_units.name',
                'inv_product_units.symbol',
                'trx_inventory_movements.inventory_location_id',
                'inv_inventory_locations.name',
                'inv_products.average_cost',
            ])
            ->orderBy('inv_products.name')
            ->orderBy('inv_inventory_locations.name')
            ->paginate($perPage, ['*'], 'valuation_page')
            ->withQueryString();
    }

    /**
     * Sprint 17.8 — room stock report with per-room thresholds (inv_location_product_minimums).
     *
     * The (location, product) key set is the UNION of:
     *   A. pairs that have ledger movements, and
     *   B. pairs that have an active configured per-room threshold.
     * UNION (not UNION ALL) de-duplicates overlaps, so a configured room still
     * appears with current_stock = 0 even when it has no movement rows yet.
     *
     * Stock stays ledger-derived (SUM(in) - SUM(out)); thresholds are configuration
     * only. effective_minimum_stock prefers the per-room minimum, then falls back to
     * the product-level minimum, then 0.
     */
    public function getRoomStockReport(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $locationFilter = $filters['inventory_location_id'] ?? null;
        $productFilter = $filters['product_id'] ?? null;

        $movementKeys = DB::table('trx_inventory_movements')
            ->select('inventory_location_id', 'product_id')
            ->where('branch_id', $branchId)
            ->when($locationFilter, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->when($productFilter, fn ($q, $v) => $q->where('product_id', $v))
            ->groupBy('inventory_location_id', 'product_id');

        $thresholdKeys = DB::table('inv_location_product_minimums')
            ->select('inventory_location_id', 'product_id')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->when($locationFilter, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->when($productFilter, fn ($q, $v) => $q->where('product_id', $v));

        $keys = $movementKeys->union($thresholdKeys);

        $movementAgg = DB::table('trx_inventory_movements')
            ->select('inventory_location_id', 'product_id')
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as current_stock')
            ->selectRaw('MAX(movement_date) as last_movement_date')
            ->where('branch_id', $branchId)
            ->groupBy('inventory_location_id', 'product_id');

        // Query aliases only — never persisted. Stock remains ledger-derived.
        $stockExpression = 'COALESCE(room_movements.current_stock, 0)';
        $minimumExpression = 'COALESCE(room_minimums.minimum_stock, inv_products.minimum_stock, 0)';
        $maximumExpression = 'room_minimums.maximum_stock';

        $query = DB::query()
            ->fromSub($keys, 'room_keys')
            ->join('inv_inventory_locations', function ($join) use ($branchId) {
                $join->on('inv_inventory_locations.id', '=', 'room_keys.inventory_location_id')
                    ->where('inv_inventory_locations.branch_id', $branchId);
            })
            ->join('inv_products', function ($join) use ($branchId) {
                $join->on('inv_products.id', '=', 'room_keys.product_id')
                    ->where('inv_products.branch_id', $branchId);
            })
            ->join('inv_product_categories', function ($join) use ($branchId) {
                $join->on('inv_product_categories.id', '=', 'inv_products.product_category_id')
                    ->where('inv_product_categories.branch_id', $branchId);
            })
            ->join('inv_product_units', 'inv_product_units.id', '=', 'inv_products.product_unit_id')
            ->join('mst_branches', 'mst_branches.id', '=', 'inv_inventory_locations.branch_id')
            ->leftJoinSub($movementAgg, 'room_movements', function ($join) {
                $join->on('room_movements.inventory_location_id', '=', 'room_keys.inventory_location_id')
                    ->on('room_movements.product_id', '=', 'room_keys.product_id');
            })
            ->leftJoin('inv_location_product_minimums as room_minimums', function ($join) use ($branchId) {
                $join->on('room_minimums.inventory_location_id', '=', 'room_keys.inventory_location_id')
                    ->on('room_minimums.product_id', '=', 'room_keys.product_id')
                    ->where('room_minimums.branch_id', $branchId)
                    ->where('room_minimums.is_active', true);
            })
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('inv_products.product_category_id', $v))
            ->select([
                'inv_inventory_locations.branch_id',
                'mst_branches.name as branch_name',
                'room_keys.inventory_location_id',
                'inv_inventory_locations.name as inventory_location_name',
                'room_keys.product_id',
                'inv_products.code as product_code',
                'inv_products.name as product_name',
                'inv_product_categories.name as category_name',
                'inv_product_units.name as unit_name',
                'inv_product_units.symbol as unit_symbol',
            ])
            ->selectRaw("{$stockExpression} as current_stock")
            ->selectRaw("{$minimumExpression} as minimum_stock")
            ->selectRaw("{$minimumExpression} as effective_minimum_stock")
            ->selectRaw("{$maximumExpression} as maximum_stock")
            ->selectRaw("CASE
                WHEN {$stockExpression} <= 0 THEN 'empty'
                WHEN {$maximumExpression} IS NOT NULL AND {$stockExpression} > {$maximumExpression} THEN 'overstock'
                WHEN {$stockExpression} < {$minimumExpression} THEN 'low'
                ELSE 'normal'
            END as stock_status")
            ->selectRaw("CASE
                WHEN {$stockExpression} >= {$minimumExpression} THEN 0
                WHEN {$maximumExpression} IS NOT NULL THEN
                    CASE WHEN {$maximumExpression} - {$stockExpression} < 0 THEN 0 ELSE {$maximumExpression} - {$stockExpression} END
                ELSE
                    CASE WHEN {$minimumExpression} - {$stockExpression} < 0 THEN 0 ELSE {$minimumExpression} - {$stockExpression} END
            END as suggested_refill_qty")
            ->selectRaw('room_movements.last_movement_date as last_movement_date');

        match ($filters['stock_status'] ?? null) {
            'empty' => $query->whereRaw("{$stockExpression} <= 0"),
            'low' => $query
                ->whereRaw("{$stockExpression} > 0")
                ->whereRaw("({$maximumExpression} IS NULL OR {$stockExpression} <= {$maximumExpression})")
                ->whereRaw("{$stockExpression} < {$minimumExpression}"),
            'normal' => $query
                ->whereRaw("{$stockExpression} > 0")
                ->whereRaw("({$maximumExpression} IS NULL OR {$stockExpression} <= {$maximumExpression})")
                ->whereRaw("{$stockExpression} >= {$minimumExpression}"),
            'overstock' => $query
                ->whereRaw("{$maximumExpression} IS NOT NULL")
                ->whereRaw("{$stockExpression} > {$maximumExpression}"),
            default => null,
        };

        return $query
            ->orderBy('inv_inventory_locations.name')
            ->orderBy('inv_products.name')
            ->paginate($perPage, ['*'], 'room_stock_page')
            ->withQueryString();
    }

    public function getStockAvailabilityForProducts(int $branchId, array $productIds): Collection
    {
        $productIds = collect($productIds)
            ->filter()
            ->map(fn ($productId) => (int) $productId)
            ->unique()
            ->values()
            ->all();

        if ($productIds === []) {
            return collect();
        }

        return InventoryMovement::query()
            ->join('inv_products', 'inv_products.id', '=', 'trx_inventory_movements.product_id')
            ->join('inv_inventory_locations', 'inv_inventory_locations.id', '=', 'trx_inventory_movements.inventory_location_id')
            ->where('trx_inventory_movements.branch_id', $branchId)
            ->where('inv_products.branch_id', $branchId)
            ->where('inv_inventory_locations.branch_id', $branchId)
            ->whereIn('trx_inventory_movements.product_id', $productIds)
            ->select([
                'trx_inventory_movements.product_id',
                'trx_inventory_movements.inventory_location_id',
                'inv_inventory_locations.name as inventory_location_name',
                'inv_products.minimum_stock',
            ])
            ->selectRaw('COALESCE(SUM(trx_inventory_movements.quantity_in) - SUM(trx_inventory_movements.quantity_out), 0) as current_stock')
            ->groupBy([
                'trx_inventory_movements.product_id',
                'trx_inventory_movements.inventory_location_id',
                'inv_inventory_locations.name',
                'inv_products.minimum_stock',
            ])
            ->havingRaw('COALESCE(SUM(trx_inventory_movements.quantity_in) - SUM(trx_inventory_movements.quantity_out), 0) > 0')
            ->get();
    }

    public function getStockCardReport(int $branchId, array $filters, string $dateFrom, string $dateTo, int $perPage = 15): LengthAwarePaginator
    {
        return $this->stockCardReportQuery($branchId, $filters, $dateFrom, $dateTo)
            ->paginate($perPage, ['*'], 'stock_card_page')
            ->withQueryString();
    }

    public function getStockCardOpeningBalance(int $branchId, int $productId, ?int $locationId, string $dateFrom, ?int $batchId = null): float
    {
        $value = InventoryMovement::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->when($locationId, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->when($batchId, fn ($q, $v) => $q->where('inventory_batch_id', $v))
            ->whereDate('movement_date', '<', $dateFrom)
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as opening_balance')
            ->value('opening_balance');

        return (float) $value;
    }

    public function getStockCardPeriodBalanceBeforePage(int $branchId, array $filters, string $dateFrom, string $dateTo, int $perPage, int $page): float
    {
        $offset = max(0, ($page - 1) * $perPage);

        if ($offset === 0) {
            return 0.0;
        }

        $pagePrefix = $this->stockCardReportQuery($branchId, $filters, $dateFrom, $dateTo)
            ->select([
                'trx_inventory_movements.id',
                'trx_inventory_movements.quantity_in',
                'trx_inventory_movements.quantity_out',
            ])
            ->limit($offset);

        $value = InventoryMovement::query()
            ->fromSub($pagePrefix, 'page_prefix')
            ->selectRaw('COALESCE(SUM(page_prefix.quantity_in) - SUM(page_prefix.quantity_out), 0) as period_balance')
            ->value('period_balance');

        return (float) $value;
    }

    public function stockByLocationSummary(int $branchId): Collection
    {
        $stock = InventoryMovement::query()
            ->select('inventory_location_id', 'product_id')
            ->selectRaw('SUM(quantity_in) - SUM(quantity_out) as current_stock')
            ->where('branch_id', $branchId)
            ->groupBy('inventory_location_id', 'product_id');

        return InventoryMovement::query()
            ->fromSub($stock, 'stock')
            ->join('inv_inventory_locations', 'inv_inventory_locations.id', '=', 'stock.inventory_location_id')
            ->join('inv_products', 'inv_products.id', '=', 'stock.product_id')
            ->select('inv_inventory_locations.id', 'inv_inventory_locations.name', 'inv_inventory_locations.code')
            ->selectRaw('SUM(stock.current_stock) as total_stock')
            ->selectRaw('SUM(stock.current_stock * inv_products.average_cost) as inventory_value')
            ->groupBy('inv_inventory_locations.id', 'inv_inventory_locations.name', 'inv_inventory_locations.code')
            ->orderBy('inv_inventory_locations.name')
            ->get();
    }

    public function stockCard(int $branchId, int $productId, ?int $locationId = null, array $filters = []): Collection
    {
        return InventoryMovement::query()
            ->with(['inventoryLocation', 'product.unit', 'supplier', 'createdBy'])
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->when($locationId, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->when($filters['movement_type'] ?? null, fn ($q, $v) => $q->where('movement_type', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('movement_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('movement_date', '<=', $v))
            ->orderBy('movement_date')
            ->orderBy('id')
            ->get();
    }

    public function lowStockProducts(int $branchId, ?int $locationId = null): Collection
    {
        $stock = InventoryMovement::query()
            ->select('product_id')
            ->selectRaw('SUM(quantity_in) - SUM(quantity_out) as current_stock')
            ->where('branch_id', $branchId)
            ->when($locationId, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->groupBy('product_id');

        return Product::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->leftJoinSub($stock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'inv_products.id'))
            ->select('inv_products.*')
            ->selectRaw('COALESCE(stock.current_stock, 0) as current_stock')
            ->whereRaw('COALESCE(stock.current_stock, 0) <= inv_products.minimum_stock')
            ->orderBy('inv_products.name')
            ->get();
    }

    public function productsWithDerivedStock(int $branchId, ?int $locationId = null): Collection
    {
        $stock = InventoryMovement::query()
            ->select('product_id')
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as current_stock')
            ->where('branch_id', $branchId)
            ->when($locationId, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->groupBy('product_id');

        return Product::query()
            ->with('unit')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->leftJoinSub($stock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'inv_products.id'))
            ->select('inv_products.*')
            ->selectRaw('COALESCE(stock.current_stock, 0) as current_stock')
            ->orderBy('inv_products.name')
            ->get();
    }

    public function inventoryValue(int $branchId, ?int $locationId = null): float
    {
        $stock = InventoryMovement::query()
            ->select('product_id')
            ->selectRaw('SUM(quantity_in) - SUM(quantity_out) as current_stock')
            ->where('branch_id', $branchId)
            ->when($locationId, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->groupBy('product_id');

        $value = Product::query()
            ->where('branch_id', $branchId)
            ->leftJoinSub($stock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'inv_products.id'))
            ->selectRaw('COALESCE(SUM(COALESCE(stock.current_stock, 0) * inv_products.average_cost), 0) as inventory_value')
            ->value('inventory_value');

        return (float) $value;
    }

    public function recentMovements(int $branchId, int $limit = 10): Collection
    {
        return InventoryMovement::query()
            ->with(['inventoryLocation', 'product.unit', 'supplier', 'createdBy'])
            ->where('branch_id', $branchId)
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function outboundByProductInPeriod(int $branchId, array $filters = []): Collection
    {
        return InventoryMovement::query()
            ->select('product_id')
            ->selectRaw('COALESCE(SUM(quantity_out), 0) as outbound_qty')
            ->where('branch_id', $branchId)
            ->where('quantity_out', '>', 0)
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('movement_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('movement_date', '<=', $v))
            ->when($filters['inventory_location_id'] ?? null, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->groupBy('product_id')
            ->get();
    }

    public function lastOutboundDateByProduct(int $branchId, ?int $locationId = null): Collection
    {
        return InventoryMovement::query()
            ->select('product_id')
            ->selectRaw('MAX(movement_date) as last_out_date')
            ->where('branch_id', $branchId)
            ->where('quantity_out', '>', 0)
            ->when($locationId, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->groupBy('product_id')
            ->get();
    }

    public function lastInboundDateByProduct(int $branchId, ?int $locationId = null): Collection
    {
        return InventoryMovement::query()
            ->select('product_id')
            ->selectRaw('MAX(movement_date) as last_in_date')
            ->where('branch_id', $branchId)
            ->where('quantity_in', '>', 0)
            ->when($locationId, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->groupBy('product_id')
            ->get();
    }

    public function stockAtDate(int $branchId, string $asOfDate, ?int $locationId = null, ?int $productId = null): float|Collection
    {
        $query = InventoryMovement::query()
            ->select('product_id')
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as stock_at_date')
            ->where('branch_id', $branchId)
            ->whereDate('movement_date', '<=', $asOfDate)
            ->when($locationId, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->when($productId, fn ($q, $v) => $q->where('product_id', $v))
            ->groupBy('product_id');

        if ($productId !== null) {
            return (float) ($query->clone()->value('stock_at_date') ?? 0);
        }

        return $query->get();
    }

    public function monthlyOutboundValue(int $branchId, array $filters = []): Collection
    {
        $monthExpression = $this->monthTruncExpression('trx_inventory_movements.movement_date');

        return InventoryMovement::query()
            ->join('inv_products', 'inv_products.id', '=', 'trx_inventory_movements.product_id')
            ->selectRaw("{$monthExpression} as month")
            ->selectRaw('COALESCE(SUM(trx_inventory_movements.quantity_out * inv_products.average_cost), 0) as outbound_value')
            ->where('trx_inventory_movements.branch_id', $branchId)
            ->where('trx_inventory_movements.quantity_out', '>', 0)
            ->where('inv_products.branch_id', $branchId)
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('trx_inventory_movements.movement_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('trx_inventory_movements.movement_date', '<=', $v))
            ->when($filters['inventory_location_id'] ?? null, fn ($q, $v) => $q->where('trx_inventory_movements.inventory_location_id', $v))
            ->when($filters['product_category_id'] ?? null, fn ($q, $v) => $q->where('inv_products.product_category_id', $v))
            ->groupByRaw($monthExpression)
            ->orderBy('month')
            ->get();
    }

    public function inventoryValueByCategory(int $branchId, ?int $locationId = null, ?int $categoryId = null): Collection
    {
        $stock = InventoryMovement::query()
            ->select('product_id')
            ->selectRaw('SUM(quantity_in) - SUM(quantity_out) as current_stock')
            ->where('branch_id', $branchId)
            ->when($locationId, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->groupBy('product_id');

        return Product::query()
            ->join('inv_product_categories', 'inv_product_categories.id', '=', 'inv_products.product_category_id')
            ->leftJoinSub($stock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'inv_products.id'))
            ->where('inv_products.branch_id', $branchId)
            ->where('inv_products.is_active', true)
            ->where('inv_product_categories.branch_id', $branchId)
            ->when($categoryId, fn ($q, $v) => $q->where('inv_products.product_category_id', $v))
            ->select(
                'inv_product_categories.id as category_id',
                'inv_product_categories.name as category_name',
                'inv_product_categories.code as category_code',
            )
            ->selectRaw('COALESCE(SUM(COALESCE(stock.current_stock, 0)), 0) as total_stock')
            ->selectRaw('COALESCE(SUM(COALESCE(stock.current_stock, 0) * inv_products.average_cost), 0) as inventory_value')
            ->groupBy('inv_product_categories.id', 'inv_product_categories.name', 'inv_product_categories.code')
            ->orderBy('inv_product_categories.name')
            ->get();
    }

    private function monthTruncExpression(string $column): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return "DATE_TRUNC('month', {$column})";
        }

        return "strftime('%Y-%m-01', {$column})";
    }

    private function stockCardReportQuery(int $branchId, array $filters, string $dateFrom, string $dateTo)
    {
        return InventoryMovement::query()
            ->with(['branch', 'inventoryLocation', 'product', 'createdBy'])
            ->where('branch_id', $branchId)
            ->where('product_id', $filters['product_id'])
            ->when($filters['inventory_location_id'] ?? null, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->when($filters['inventory_batch_id'] ?? null, fn ($q, $v) => $q->where('inventory_batch_id', $v))
            ->when($filters['movement_type'] ?? null, fn ($q, $v) => $q->where('movement_type', $v))
            ->whereDate('movement_date', '>=', $dateFrom)
            ->whereDate('movement_date', '<=', $dateTo)
            ->orderBy('movement_date')
            ->orderBy('id');
    }
}
