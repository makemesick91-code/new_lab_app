<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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
        return InventoryMovement::create($data);
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
}
