<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\StockOpnameRepositoryInterface;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockOpnameService
{
    public function __construct(
        private readonly StockOpnameRepositoryInterface $opnames,
        private readonly InventoryMovementRepositoryInterface $movements,
        private readonly ProductRepositoryInterface $products,
        private readonly InventoryLocationRepositoryInterface $locations,
        private readonly BranchContext $branchContext,
    ) {}

    /**
     * @param  array<int, int>  $productIds
     */
    public function createDraftOpname(
        int $locationId,
        array $productIds = [],
        ?string $notes = null,
        ?string $opnameDate = null,
    ): StockOpname {
        return DB::transaction(function () use ($locationId, $productIds, $notes, $opnameDate) {
            $branchId = $this->branchContext->requireId();
            $location = $this->assertActiveLocationInBranch($branchId, $locationId);

            $opname = $this->opnames->create([
                'branch_id' => $branchId,
                'inventory_location_id' => $location->id,
                'opname_number' => $this->generateOpnameNumber(),
                'opname_date' => $opnameDate ?? now()->toDateString(),
                'status' => StockOpname::STATUS_DRAFT,
                'notes' => $notes,
                'counted_by' => null,
                'created_by' => Auth::id(),
            ]);

            foreach (array_unique($productIds) as $productId) {
                $product = $this->assertActiveProductInBranch($branchId, (int) $productId);
                $this->createSnapshotItem($opname, $product, $location->id);
            }

            return $this->opnames->loadItems($opname->refresh());
        });
    }

    public function updateCountedQuantity(int $opnameId, int $productId, float $countedQuantity, ?string $notes = null): StockOpnameItem
    {
        if ($countedQuantity < 0) {
            throw ValidationException::withMessages([
                'counted_quantity' => 'Counted quantity tidak boleh negatif.',
            ]);
        }

        return DB::transaction(function () use ($opnameId, $productId, $countedQuantity, $notes) {
            $branchId = $this->branchContext->requireId();
            $opname = $this->lockOpnameInBranch($branchId, $opnameId);
            $this->assertEditable($opname);
            $this->assertActiveLocationInBranch($branchId, $opname->inventory_location_id);
            $product = $this->assertActiveProductInBranch($branchId, $productId);

            $item = StockOpnameItem::query()
                ->where('stock_opname_id', $opname->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if (! $item) {
                $item = $this->createSnapshotItem($opname, $product, $opname->inventory_location_id);
            }

            $systemQuantity = (float) $item->system_quantity;

            $item->update([
                'counted_quantity' => $countedQuantity,
                'variance_quantity' => round($countedQuantity - $systemQuantity, 2),
                'notes' => $notes,
            ]);

            return $item->refresh();
        });
    }

    public function reviewOpname(int $opnameId): StockOpname
    {
        return DB::transaction(function () use ($opnameId) {
            $branchId = $this->branchContext->requireId();
            $opname = $this->lockOpnameInBranch($branchId, $opnameId);

            if ($opname->status !== StockOpname::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Stock opname hanya bisa direview dari status DRAFT.',
                ]);
            }

            if (! $opname->items()->exists()) {
                throw ValidationException::withMessages([
                    'items' => 'Stock opname harus memiliki minimal satu item sebelum review.',
                ]);
            }

            return $this->opnames->update($opname, [
                // Existing scaffold has no REVIEWED status. COUNTING is the
                // ready-to-finalize review state for this workflow.
                'status' => StockOpname::STATUS_COUNTING,
                'counted_by' => Auth::id(),
            ]);
        });
    }

    public function finalizeOpname(int $opnameId): StockOpname
    {
        return DB::transaction(function () use ($opnameId) {
            $branchId = $this->branchContext->requireId();
            $opname = $this->lockOpnameInBranch($branchId, $opnameId);

            if ($opname->status === StockOpname::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'status' => 'Stock opname sudah difinalisasi.',
                ]);
            }

            if ($opname->status === StockOpname::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => 'Stock opname yang dibatalkan tidak bisa difinalisasi.',
                ]);
            }

            if ($opname->status !== StockOpname::STATUS_COUNTING) {
                throw ValidationException::withMessages([
                    'status' => 'Stock opname harus direview sebelum finalisasi.',
                ]);
            }

            $this->assertActiveLocationInBranch($branchId, $opname->inventory_location_id);

            $opname->load(['items.product']);

            if ($opname->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Stock opname harus memiliki minimal satu item sebelum finalisasi.',
                ]);
            }

            foreach ($opname->items as $item) {
                $product = $this->lockAndAssertActiveProductInBranch($branchId, (int) $item->product_id);
                $variance = round((float) $item->variance_quantity, 2);

                if ($variance > 0) {
                    $this->createAdjustmentMovement($opname, $product, $variance, 0, (float) $item->unit_cost);
                }

                if ($variance < 0) {
                    $quantityOut = abs($variance);
                    $currentStock = $this->movements->currentStock($branchId, $product->id, $opname->inventory_location_id);

                    if ($currentStock < $quantityOut) {
                        throw ValidationException::withMessages([
                            'counted_quantity' => 'Variance stock opname melebihi stock lokasi saat ini.',
                        ]);
                    }

                    $this->createAdjustmentMovement($opname, $product, 0, $quantityOut, (float) $item->unit_cost);
                }
            }

            return $this->opnames->update($opname, [
                'status' => StockOpname::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        });
    }

    public function cancelOpname(int $opnameId, ?string $notes = null): StockOpname
    {
        return DB::transaction(function () use ($opnameId, $notes) {
            $branchId = $this->branchContext->requireId();
            $opname = $this->lockOpnameInBranch($branchId, $opnameId);

            if ($opname->status === StockOpname::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'status' => 'Stock opname yang sudah selesai tidak bisa dibatalkan.',
                ]);
            }

            if ($opname->status === StockOpname::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => 'Stock opname sudah dibatalkan.',
                ]);
            }

            return $this->opnames->update($opname, [
                'status' => StockOpname::STATUS_CANCELLED,
                'notes' => $notes ?? $opname->notes,
            ]);
        });
    }

    private function createSnapshotItem(StockOpname $opname, Product $product, int $locationId): StockOpnameItem
    {
        $systemQuantity = $this->movements->currentStock($opname->branch_id, $product->id, $locationId);

        return StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'product_id' => $product->id,
            'system_quantity' => $systemQuantity,
            'counted_quantity' => $systemQuantity,
            'variance_quantity' => 0,
            'unit_cost' => $product->average_cost,
            'notes' => null,
        ]);
    }

    private function createAdjustmentMovement(StockOpname $opname, Product $product, float $quantityIn, float $quantityOut, float $unitCost): InventoryMovement
    {
        return $this->movements->create([
            'branch_id' => $opname->branch_id,
            'inventory_location_id' => $opname->inventory_location_id,
            'product_id' => $product->id,
            'supplier_id' => null,
            'movement_type' => $quantityIn > 0 ? InventoryMovement::TYPE_ADJUSTMENT_IN : InventoryMovement::TYPE_ADJUSTMENT_OUT,
            'movement_date' => $opname->opname_date->toDateString(),
            'quantity_in' => $quantityIn,
            'quantity_out' => $quantityOut,
            'unit_cost' => max(0, $unitCost),
            'reference_type' => $opname->getTable(),
            'reference_id' => $opname->id,
            'notes' => 'Generated from stock opname '.$opname->opname_number,
            'created_by' => Auth::id(),
        ]);
    }

    private function lockOpnameInBranch(int $branchId, int $opnameId): StockOpname
    {
        $opname = StockOpname::query()
            ->where('branch_id', $branchId)
            ->whereKey($opnameId)
            ->lockForUpdate()
            ->first();

        if (! $opname) {
            throw ValidationException::withMessages([
                'stock_opname_id' => 'Stock opname tidak valid untuk branch aktif.',
            ]);
        }

        return $opname;
    }

    private function assertEditable(StockOpname $opname): void
    {
        if (in_array($opname->status, [StockOpname::STATUS_COMPLETED, StockOpname::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Stock opname yang sudah selesai atau dibatalkan tidak bisa diedit.',
            ]);
        }
    }

    private function assertActiveLocationInBranch(int $branchId, int $locationId): InventoryLocation
    {
        $location = $this->locations->findInBranch($branchId, $locationId);

        if (! $location || ! $location->is_active) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Inventory location tidak valid untuk branch aktif.',
            ]);
        }

        return $location;
    }

    private function assertActiveProductInBranch(int $branchId, int $productId): Product
    {
        $product = $this->products->findInBranch($branchId, $productId);

        if (! $product || ! $product->is_active) {
            throw ValidationException::withMessages([
                'product_id' => 'Product tidak valid untuk branch aktif.',
            ]);
        }

        return $product;
    }

    private function lockAndAssertActiveProductInBranch(int $branchId, int $productId): Product
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

    private function generateOpnameNumber(): string
    {
        return 'OPN-'.now()->format('Ym').'-'.Str::upper(Str::random(6));
    }
}
