<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Enums\InventoryActivityAction;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\StockOpnameRepositoryInterface;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameItem;
use App\Modules\Inventory\Services\Concerns\LogsInventoryActivity;
use App\Modules\Inventory\Support\SourceDocumentBatchGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockOpnameService
{
    use LogsInventoryActivity;

    public function __construct(
        private readonly StockOpnameRepositoryInterface $opnames,
        private readonly InventoryMovementRepositoryInterface $movements,
        private readonly ProductRepositoryInterface $products,
        private readonly InventoryLocationRepositoryInterface $locations,
        private readonly BranchContext $branchContext,
        private readonly InventoryActivityLogService $activityLogger,
        private readonly BatchStockOptionService $batchStockOptions,
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
        $result = DB::transaction(function () use ($locationId, $productIds, $notes, $opnameDate) {
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

                if ($product->requires_batch_tracking) {
                    $batchItems = $this->createBatchSnapshotItems($opname, $product, $location->id);

                    if ($batchItems->isEmpty()) {
                        throw ValidationException::withMessages([
                            'product_ids' => 'Belum ada batch tersedia untuk produk ini di lokasi ini.',
                        ]);
                    }

                    continue;
                }

                $this->createSnapshotItem($opname, $product, $location->id);
            }

            return $this->opnames->loadItems($opname->refresh());
        });

        $this->logStockOpnameActivity(InventoryActivityAction::STOCK_OPNAME_CREATED, $result, null);

        return $result;
    }

    public function updateCountedQuantity(
        int $opnameId,
        int $productId,
        float $countedQuantity,
        ?string $notes = null,
        ?int $inventoryBatchId = null,
    ): StockOpnameItem {
        if ($countedQuantity < 0) {
            throw ValidationException::withMessages([
                'counted_quantity' => 'Jumlah terhitung tidak boleh negatif.',
            ]);
        }

        $branchId = $this->branchContext->requireId();
        $existing = StockOpname::query()->where('branch_id', $branchId)->find($opnameId);
        $statusFrom = $existing?->status;

        $item = DB::transaction(function () use ($opnameId, $productId, $countedQuantity, $notes, $inventoryBatchId) {
            $branchId = $this->branchContext->requireId();
            $opname = $this->lockOpnameInBranch($branchId, $opnameId);
            $this->assertEditable($opname);
            $this->assertActiveLocationInBranch($branchId, $opname->inventory_location_id);
            $product = $this->assertActiveProductInBranch($branchId, $productId);

            $itemQuery = StockOpnameItem::query()
                ->where('stock_opname_id', $opname->id)
                ->where('product_id', $product->id);

            if ($inventoryBatchId !== null) {
                $itemQuery->where('inventory_batch_id', $inventoryBatchId);
            } else {
                $itemQuery->whereNull('inventory_batch_id');
            }

            $item = $itemQuery->lockForUpdate()->first();

            if (! $item) {
                if ($product->requires_batch_tracking) {
                    $createdItems = $this->createBatchSnapshotItems($opname, $product, $opname->inventory_location_id);

                    if ($createdItems->isEmpty()) {
                        throw ValidationException::withMessages([
                            'product_id' => 'Belum ada batch tersedia untuk produk ini di lokasi ini.',
                        ]);
                    }

                    $item = $inventoryBatchId !== null
                        ? $createdItems->firstWhere('inventory_batch_id', $inventoryBatchId)
                        : $createdItems->first();

                    if (! $item) {
                        throw ValidationException::withMessages([
                            'inventory_batch_id' => 'Batch tidak valid untuk produk ini pada opname ini.',
                        ]);
                    }
                } else {
                    $item = $this->createSnapshotItem($opname, $product, $opname->inventory_location_id);
                }
            }

            $systemQuantity = (float) $item->system_quantity;

            $item->update([
                'counted_quantity' => $countedQuantity,
                'variance_quantity' => round($countedQuantity - $systemQuantity, 4),
                'notes' => $notes,
            ]);

            return $item->refresh();
        });

        if ($existing !== null) {
            $this->logStockOpnameActivity(
                InventoryActivityAction::STOCK_OPNAME_UPDATED,
                $existing->refresh(),
                $statusFrom,
            );
        }

        return $item;
    }

    public function reviewOpname(int $opnameId): StockOpname
    {
        $branchId = $this->branchContext->requireId();
        $existing = StockOpname::query()->where('branch_id', $branchId)->find($opnameId);
        $statusFrom = $existing?->status;

        $result = DB::transaction(function () use ($opnameId) {
            $branchId = $this->branchContext->requireId();
            $opname = $this->lockOpnameInBranch($branchId, $opnameId);

            if ($opname->status !== StockOpname::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Stok opname hanya bisa ditinjau dari status DRAFT.',
                ]);
            }

            if (! $opname->items()->exists()) {
                throw ValidationException::withMessages([
                    'items' => 'Stok opname harus memiliki minimal satu item sebelum ditinjau.',
                ]);
            }

            return $this->opnames->update($opname, [
                // Existing scaffold has no REVIEWED status. COUNTING is the
                // ready-to-finalize review state for this workflow.
                'status' => StockOpname::STATUS_COUNTING,
                'counted_by' => Auth::id(),
            ]);
        });

        $this->logStockOpnameActivity(InventoryActivityAction::STOCK_OPNAME_UPDATED, $result, $statusFrom);

        return $result;
    }

    public function finalizeOpname(int $opnameId): StockOpname
    {
        $branchId = $this->branchContext->requireId();
        $existing = StockOpname::query()->where('branch_id', $branchId)->find($opnameId);
        $statusFrom = $existing?->status;
        $createdMovements = [];

        $result = DB::transaction(function () use ($opnameId, &$createdMovements) {
            $branchId = $this->branchContext->requireId();
            $opname = $this->lockOpnameInBranch($branchId, $opnameId);

            if ($opname->status === StockOpname::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'status' => 'Stok opname sudah difinalisasi.',
                ]);
            }

            if ($opname->status === StockOpname::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => 'Stok opname yang dibatalkan tidak bisa difinalisasi.',
                ]);
            }

            if ($opname->status !== StockOpname::STATUS_COUNTING) {
                throw ValidationException::withMessages([
                    'status' => 'Stok opname harus ditinjau sebelum finalisasi.',
                ]);
            }

            $this->assertActiveLocationInBranch($branchId, $opname->inventory_location_id);

            $opname->load(['items.product']);

            if ($opname->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Stok opname harus memiliki minimal satu item sebelum finalisasi.',
                ]);
            }

            foreach ($opname->items as $item) {
                $product = $this->lockAndAssertActiveProductInBranch($branchId, (int) $item->product_id);
                $variance = round((float) $item->variance_quantity, 4);

                if ($variance !== 0.0) {
                    SourceDocumentBatchGuard::assertItem($product, $item->inventory_batch_id ? (int) $item->inventory_batch_id : null);
                    if ($item->inventory_batch_id) {
                        SourceDocumentBatchGuard::assertBatchMatchesProduct(
                            $branchId,
                            $product->id,
                            (int) $item->inventory_batch_id,
                        );
                    }
                }

                if ($variance < 0) {
                    $quantityOut = abs($variance);
                    $batchId = $item->inventory_batch_id ? (int) $item->inventory_batch_id : null;

                    if ($batchId !== null) {
                        $batchStock = $this->movements->currentStockByBatch(
                            $branchId,
                            $product->id,
                            $opname->inventory_location_id,
                            $batchId,
                        );

                        if ($batchStock < $quantityOut) {
                            throw ValidationException::withMessages([
                                'counted_quantity' => 'Selisih stok opname melebihi stok batch pada lokasi ini.',
                            ]);
                        }
                    }

                    $currentStock = $this->movements->currentStock($branchId, $product->id, $opname->inventory_location_id);

                    if ($currentStock < $quantityOut) {
                        throw ValidationException::withMessages([
                            'counted_quantity' => 'Selisih stok opname melebihi stok lokasi saat ini.',
                        ]);
                    }

                    $createdMovements[] = $this->createAdjustmentMovement(
                        $opname,
                        $product,
                        0,
                        $quantityOut,
                        (float) $item->unit_cost,
                        $batchId,
                    );

                    continue;
                }

                if ($variance > 0) {
                    $createdMovements[] = $this->createAdjustmentMovement(
                        $opname,
                        $product,
                        $variance,
                        0,
                        (float) $item->unit_cost,
                        $item->inventory_batch_id ? (int) $item->inventory_batch_id : null,
                    );
                }
            }

            return $this->opnames->update($opname, [
                'status' => StockOpname::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        });

        $movementIds = array_map(fn (InventoryMovement $movement) => $movement->id, $createdMovements);
        $this->logStockOpnameActivity(
            InventoryActivityAction::STOCK_OPNAME_COMPLETED,
            $result,
            $statusFrom,
            $movementIds,
        );

        foreach ($createdMovements as $movement) {
            $this->logInventoryMovement($movement);
        }

        return $result;
    }

    public function cancelOpname(int $opnameId, ?string $notes = null): StockOpname
    {
        $branchId = $this->branchContext->requireId();
        $existing = StockOpname::query()->where('branch_id', $branchId)->find($opnameId);
        $statusFrom = $existing?->status;

        $result = DB::transaction(function () use ($opnameId, $notes) {
            $branchId = $this->branchContext->requireId();
            $opname = $this->lockOpnameInBranch($branchId, $opnameId);

            if ($opname->status === StockOpname::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'status' => 'Stok opname yang sudah selesai tidak bisa dibatalkan.',
                ]);
            }

            if ($opname->status === StockOpname::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => 'Stok opname sudah dibatalkan.',
                ]);
            }

            return $this->opnames->update($opname, [
                'status' => StockOpname::STATUS_CANCELLED,
                'notes' => $notes ?? $opname->notes,
            ]);
        });

        $this->logStockOpnameActivity(InventoryActivityAction::STOCK_OPNAME_CANCELLED, $result, $statusFrom);

        return $result;
    }

    private function createSnapshotItem(StockOpname $opname, Product $product, int $locationId): StockOpnameItem
    {
        if ($product->requires_batch_tracking) {
            throw ValidationException::withMessages([
                'product_id' => 'Gunakan baris batch untuk produk dengan pelacakan batch.',
            ]);
        }

        $systemQuantity = $this->movements->currentStock($opname->branch_id, $product->id, $locationId);

        return StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'product_id' => $product->id,
            'inventory_batch_id' => null,
            'system_quantity' => $systemQuantity,
            'counted_quantity' => $systemQuantity,
            'variance_quantity' => 0,
            'unit_cost' => $product->average_cost,
            'notes' => null,
        ]);
    }

    /**
     * @return Collection<int, StockOpnameItem>
     */
    private function createBatchSnapshotItems(StockOpname $opname, Product $product, int $locationId): Collection
    {
        $options = $this->batchStockOptions->availableForProductLocation(
            $product->id,
            $opname->branch_id,
            $locationId,
        );

        if ($options->isEmpty()) {
            return collect();
        }

        return $options->map(function (array $option) use ($opname, $product) {
            return StockOpnameItem::create([
                'stock_opname_id' => $opname->id,
                'product_id' => $product->id,
                'inventory_batch_id' => $option['batch_id'],
                'system_quantity' => $option['available_qty'],
                'counted_quantity' => $option['available_qty'],
                'variance_quantity' => 0,
                'unit_cost' => $product->average_cost,
                'notes' => null,
            ]);
        });
    }

    private function createAdjustmentMovement(
        StockOpname $opname,
        Product $product,
        float $quantityIn,
        float $quantityOut,
        float $unitCost,
        ?int $inventoryBatchId = null,
    ): InventoryMovement {
        return $this->movements->create([
            'branch_id' => $opname->branch_id,
            'inventory_location_id' => $opname->inventory_location_id,
            'product_id' => $product->id,
            'supplier_id' => null,
            'inventory_batch_id' => $inventoryBatchId,
            'movement_type' => $quantityIn > 0 ? InventoryMovement::TYPE_ADJUSTMENT_IN : InventoryMovement::TYPE_ADJUSTMENT_OUT,
            'movement_date' => $opname->opname_date->toDateString(),
            'quantity_in' => $quantityIn,
            'quantity_out' => $quantityOut,
            'unit_cost' => max(0, $unitCost),
            'reference_type' => $opname->getTable(),
            'reference_id' => $opname->id,
            'notes' => 'Dihasilkan dari stok opname '.$opname->opname_number,
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
                'stock_opname_id' => 'Stok opname tidak valid untuk cabang aktif.',
            ]);
        }

        return $opname;
    }

    private function assertEditable(StockOpname $opname): void
    {
        if (in_array($opname->status, [StockOpname::STATUS_COMPLETED, StockOpname::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Stok opname yang sudah selesai atau dibatalkan tidak bisa diedit.',
            ]);
        }
    }

    private function assertActiveLocationInBranch(int $branchId, int $locationId): InventoryLocation
    {
        $location = $this->locations->findInBranch($branchId, $locationId);

        if (! $location || ! $location->is_active) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Lokasi persediaan tidak valid untuk cabang aktif.',
            ]);
        }

        return $location;
    }

    private function assertActiveProductInBranch(int $branchId, int $productId): Product
    {
        $product = $this->products->findInBranch($branchId, $productId);

        if (! $product || ! $product->is_active) {
            throw ValidationException::withMessages([
                'product_id' => 'Produk tidak valid untuk cabang aktif.',
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
                'product_id' => 'Produk tidak valid untuk cabang aktif.',
            ]);
        }

        return $product;
    }

    private function generateOpnameNumber(): string
    {
        return 'OPN-'.now()->format('Ym').'-'.Str::upper(Str::random(6));
    }

    /**
     * @param  array<int, int>  $movementIds
     */
    private function logStockOpnameActivity(
        string $action,
        StockOpname $opname,
        ?string $statusFrom,
        array $movementIds = [],
    ): void {
        $opname->loadMissing('items');

        $metadata = [
            'document_number' => $opname->opname_number,
            'branch_id' => $opname->branch_id,
            'status_to' => $opname->status,
            'inventory_location_id' => $opname->inventory_location_id,
            'item_count' => $opname->items->count(),
        ];

        if ($statusFrom !== null) {
            $metadata['status_from'] = $statusFrom;
        }

        if ($movementIds !== []) {
            $metadata['movement_ids'] = $movementIds;
        }

        $user = Auth::user();
        $this->logActivity($action, $opname, $metadata, null, $user instanceof User ? $user : null);
    }

    private function logInventoryMovement(InventoryMovement $movement): void
    {
        $quantity = max((float) $movement->quantity_in, (float) $movement->quantity_out);

        $user = Auth::user();
        $this->logActivity(
            InventoryActivityAction::INVENTORY_MOVEMENT_CREATED,
            $movement,
            [
                'branch_id' => $movement->branch_id,
                'product_id' => $movement->product_id,
                'inventory_location_id' => $movement->inventory_location_id,
                'quantity' => $quantity,
                'movement_type' => $movement->movement_type,
            ],
            null,
            $user instanceof User ? $user : null,
        );
    }
}
