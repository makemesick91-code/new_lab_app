<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\SupplierRepositoryInterface;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryStockService
{
    public function __construct(
        private readonly InventoryMovementRepositoryInterface $movements,
        private readonly ProductRepositoryInterface $products,
        private readonly InventoryLocationRepositoryInterface $locations,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly BranchContext $branchContext,
    ) {}

    public function getCurrentStock(int $productId, ?int $locationId = null): float
    {
        $branchId = $this->branchContext->requireId();
        $this->assertProductInBranch($branchId, $productId);

        if ($locationId !== null) {
            $this->assertLocationInBranch($branchId, $locationId);
        }

        return $this->movements->currentStock($branchId, $productId, $locationId);
    }

    public function createOpeningStock(int $productId, int $locationId, float $qty, float $unitCost = 0, ?string $notes = null): InventoryMovement
    {
        return $this->createInboundMovement(InventoryMovement::TYPE_OPENING, $productId, $locationId, $qty, $unitCost, null, $notes);
    }

    public function receiveStock(int $productId, int $locationId, float $qty, float $unitCost = 0, ?int $supplierId = null, ?string $notes = null): InventoryMovement
    {
        return $this->createInboundMovement(InventoryMovement::TYPE_PURCHASE, $productId, $locationId, $qty, $unitCost, $supplierId, $notes);
    }

    public function adjustIn(int $productId, int $locationId, float $qty, ?string $notes = null): InventoryMovement
    {
        return $this->createInboundMovement(InventoryMovement::TYPE_ADJUSTMENT_IN, $productId, $locationId, $qty, 0, null, $notes);
    }

    public function adjustOut(int $productId, int $locationId, float $qty, ?string $notes = null): InventoryMovement
    {
        $this->assertPositiveQuantity($qty);

        return DB::transaction(function () use ($productId, $locationId, $qty, $notes) {
            $branchId = $this->branchContext->requireId();
            $this->lockAndAssertProductInBranch($branchId, $productId);
            $this->lockAndAssertLocationInBranch($branchId, $locationId);

            $currentStock = $this->movements->currentStock($branchId, $productId, $locationId);

            if ($currentStock < $qty) {
                throw ValidationException::withMessages([
                    'quantity' => 'Stock pada lokasi ini tidak mencukupi.',
                ]);
            }

            return $this->movements->create([
                'branch_id' => $branchId,
                'inventory_location_id' => $locationId,
                'product_id' => $productId,
                'supplier_id' => null,
                'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_OUT,
                'movement_date' => now()->toDateString(),
                'quantity_in' => 0,
                'quantity_out' => $qty,
                'unit_cost' => 0,
                'reference_type' => null,
                'reference_id' => null,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
        });
    }

    public function getStockCard(int $productId, ?int $locationId = null, array $filters = []): Collection
    {
        $branchId = $this->branchContext->requireId();
        $this->assertProductInBranch($branchId, $productId);

        if ($locationId !== null) {
            $this->assertLocationInBranch($branchId, $locationId);
        }

        return $this->movements->stockCard($branchId, $productId, $locationId, $filters);
    }

    public function getLowStockProducts(?int $locationId = null): Collection
    {
        $branchId = $this->branchContext->requireId();

        if ($locationId !== null) {
            $this->assertLocationInBranch($branchId, $locationId);
        }

        return $this->movements->lowStockProducts($branchId, $locationId);
    }

    public function getInventoryValue(?int $locationId = null): float
    {
        $branchId = $this->branchContext->requireId();

        if ($locationId !== null) {
            $this->assertLocationInBranch($branchId, $locationId);
        }

        return $this->movements->inventoryValue($branchId, $locationId);
    }

    public function getCurrentStockByLocation(int $locationId): Collection
    {
        $branchId = $this->branchContext->requireId();
        $this->assertLocationInBranch($branchId, $locationId);

        return $this->movements->currentStockByLocation($branchId, $locationId);
    }

    public function getCurrentStockByBranch(): Collection
    {
        return $this->movements->currentStockByBranch($this->branchContext->requireId());
    }

    public function getStockRows(?int $locationId = null): Collection
    {
        $branchId = $this->branchContext->requireId();

        if ($locationId !== null) {
            $this->assertLocationInBranch($branchId, $locationId);
        }

        return $this->movements->stockRows($branchId, $locationId);
    }

    public function getStockByLocationSummary(): Collection
    {
        return $this->movements->stockByLocationSummary($this->branchContext->requireId());
    }

    public function getRecentMovements(int $limit = 10): Collection
    {
        return $this->movements->recentMovements($this->branchContext->requireId(), $limit);
    }

    public function getBranchSummary(?int $locationId = null): array
    {
        $lowStockProducts = $this->getLowStockProducts($locationId);

        return [
            'inventory_value' => $this->getInventoryValue($locationId),
            'low_stock_count' => $lowStockProducts->count(),
            'out_of_stock_count' => $lowStockProducts
                ->filter(fn ($product) => (float) $product->current_stock <= 0)
                ->count(),
        ];
    }

    private function createInboundMovement(
        string $movementType,
        int $productId,
        int $locationId,
        float $qty,
        float $unitCost = 0,
        ?int $supplierId = null,
        ?string $notes = null,
    ): InventoryMovement {
        $this->assertPositiveQuantity($qty);

        return DB::transaction(function () use ($movementType, $productId, $locationId, $qty, $unitCost, $supplierId, $notes) {
            $branchId = $this->branchContext->requireId();
            $this->lockAndAssertProductInBranch($branchId, $productId);
            $this->lockAndAssertLocationInBranch($branchId, $locationId);

            if ($supplierId !== null) {
                $this->assertSupplierInBranch($branchId, $supplierId);
            }

            return $this->movements->create([
                'branch_id' => $branchId,
                'inventory_location_id' => $locationId,
                'product_id' => $productId,
                'supplier_id' => $supplierId,
                'movement_type' => $movementType,
                'movement_date' => now()->toDateString(),
                'quantity_in' => $qty,
                'quantity_out' => 0,
                'unit_cost' => max(0, $unitCost),
                'reference_type' => null,
                'reference_id' => null,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
        });
    }

    private function assertPositiveQuantity(float $qty): void
    {
        if ($qty <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity harus lebih dari 0.',
            ]);
        }
    }

    private function assertProductInBranch(int $branchId, int $productId): Product
    {
        $product = $this->products->findInBranch($branchId, $productId);

        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => 'Product tidak valid untuk branch aktif.',
            ]);
        }

        return $product;
    }

    private function assertLocationInBranch(int $branchId, int $locationId): InventoryLocation
    {
        $location = $this->locations->findInBranch($branchId, $locationId);

        if (! $location || ! $location->is_active) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Inventory location tidak valid untuk branch aktif.',
            ]);
        }

        return $location;
    }

    private function assertSupplierInBranch(int $branchId, int $supplierId): Supplier
    {
        $supplier = $this->suppliers->findInBranch($branchId, $supplierId);

        if (! $supplier || ! $supplier->is_active) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Supplier tidak valid untuk branch aktif.',
            ]);
        }

        return $supplier;
    }

    private function lockAndAssertProductInBranch(int $branchId, int $productId): Product
    {
        $product = Product::query()
            ->where('branch_id', $branchId)
            ->whereKey($productId)
            ->lockForUpdate()
            ->first();

        if (! $product || ! $product->is_active) {
            throw ValidationException::withMessages([
                'product_id' => 'Product tidak valid untuk branch aktif.',
            ]);
        }

        return $product;
    }

    private function lockAndAssertLocationInBranch(int $branchId, int $locationId): InventoryLocation
    {
        $location = InventoryLocation::query()
            ->where('branch_id', $branchId)
            ->whereKey($locationId)
            ->lockForUpdate()
            ->first();

        if (! $location || ! $location->is_active) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Inventory location tidak valid untuk branch aktif.',
            ]);
        }

        return $location;
    }
}
