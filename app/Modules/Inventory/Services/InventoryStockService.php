<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Enums\InventoryActivityAction;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\SupplierRepositoryInterface;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\Concerns\LogsInventoryActivity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryStockService
{
    use LogsInventoryActivity;

    public function __construct(
        private readonly InventoryMovementRepositoryInterface $movements,
        private readonly ProductRepositoryInterface $products,
        private readonly InventoryLocationRepositoryInterface $locations,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly BranchContext $branchContext,
        private readonly InventoryActivityLogService $activityLogger,
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

    public function getCurrentStockByBatch(int $productId, int $locationId, int $batchId): float
    {
        $branchId = $this->branchContext->requireId();
        $this->assertProductInBranch($branchId, $productId);
        $this->assertLocationInBranch($branchId, $locationId);
        $this->assertBatchInBranch($branchId, $batchId, $productId);

        return $this->movements->currentStockByBatch($branchId, $productId, $locationId, $batchId);
    }

    public function listActiveBatchesForProduct(int $productId): Collection
    {
        $branchId = $this->branchContext->requireId();
        $this->assertProductInBranch($branchId, $productId);

        return InventoryBatch::query()
            ->forBranch($branchId)
            ->forProduct($productId)
            ->where('is_active', true)
            ->orderByDesc('received_date')
            ->orderBy('batch_number')
            ->get();
    }

    /**
     * @return Collection<int, array{id: int, batch_number: string, lot_number: string|null, expiry_date: string|null, stock: float}>
     */
    public function listBatchesWithStockAtLocation(int $productId, int $locationId): Collection
    {
        $branchId = $this->branchContext->requireId();

        return app(BatchStockOptionService::class)
            ->availableForProductLocation($productId, $branchId, $locationId)
            ->map(fn (array $option) => [
                'id' => $option['batch_id'],
                'batch_number' => $option['batch_number'],
                'lot_number' => $option['lot_number'],
                'expiry_date' => $option['expiry_date'],
                'expiry_label' => $option['expiry_label'],
                'stock' => $option['available_qty'],
                'label' => $option['label'],
            ]);
    }

    /**
     * @return array<int, array<int, array<int, array{id: int, batch_number: string, lot_number: string|null, expiry_date: string|null, expiry_label: string|null, stock: float, label: string}>>>
     */
    public function batchOptionsMatrixForTransfer(): array
    {
        return app(BatchStockOptionService::class)
            ->batchOptionsMatrixForTransfer($this->branchContext->requireId());
    }

    public function createOpeningStock(
        int $productId,
        int $locationId,
        float $qty,
        float $unitCost = 0,
        ?string $notes = null,
        ?array $batchData = null,
    ): InventoryMovement {
        $movement = $this->createInboundMovement(
            InventoryMovement::TYPE_OPENING,
            $productId,
            $locationId,
            $qty,
            $unitCost,
            null,
            $notes,
            $batchData,
        );

        $this->logInventoryMovement($movement);

        return $movement;
    }

    public function receiveStock(
        int $productId,
        int $locationId,
        float $qty,
        float $unitCost = 0,
        ?int $supplierId = null,
        ?string $notes = null,
        ?array $batchData = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $movementDate = null,
    ): InventoryMovement {
        $movement = $this->createInboundMovement(
            InventoryMovement::TYPE_PURCHASE,
            $productId,
            $locationId,
            $qty,
            $unitCost,
            $supplierId,
            $notes,
            $batchData,
            $referenceType,
            $referenceId,
            $movementDate,
        );

        $this->logInventoryMovement($movement);

        return $movement;
    }

    public function adjustIn(
        int $productId,
        int $locationId,
        float $qty,
        ?string $notes = null,
        ?array $batchData = null,
    ): InventoryMovement {
        $movement = $this->createInboundMovement(
            InventoryMovement::TYPE_ADJUSTMENT_IN,
            $productId,
            $locationId,
            $qty,
            0,
            null,
            $notes,
            $batchData,
        );

        $this->logInventoryMovement($movement);

        return $movement;
    }

    public function reversePurchaseMovement(
        InventoryMovement $original,
        ?string $notes = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $movementDate = null,
    ): InventoryMovement {
        if ($original->movement_type !== InventoryMovement::TYPE_PURCHASE || (float) $original->quantity_in <= 0) {
            throw ValidationException::withMessages([
                'movement' => 'Hanya pergerakan pembelian masuk yang dapat dibalik.',
            ]);
        }

        $qty = (float) $original->quantity_in;

        $movement = DB::transaction(function () use ($original, $qty, $notes, $referenceType, $referenceId, $movementDate) {
            $branchId = $this->branchContext->requireId();

            if ($original->branch_id !== $branchId) {
                throw ValidationException::withMessages([
                    'movement' => 'Pergerakan stok tidak valid untuk cabang aktif.',
                ]);
            }

            $productId = (int) $original->product_id;
            $locationId = (int) $original->inventory_location_id;
            $batchId = $original->inventory_batch_id !== null ? (int) $original->inventory_batch_id : null;

            $this->lockAndAssertProductInBranch($branchId, $productId);
            $this->lockAndAssertLocationInBranch($branchId, $locationId);

            if ($batchId !== null) {
                $this->lockAndAssertBatchForMovement($branchId, $productId, $batchId, forOutbound: true);

                $batchStock = $this->movements->currentStockByBatch($branchId, $productId, $locationId, $batchId);

                if ($batchStock < $qty) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Stok batch pada lokasi ini tidak mencukupi untuk pembatalan penerimaan.',
                    ]);
                }
            }

            $currentStock = $this->movements->currentStock($branchId, $productId, $locationId);

            if ($currentStock < $qty) {
                throw ValidationException::withMessages([
                    'quantity' => 'Stok pada lokasi ini tidak mencukupi untuk pembatalan penerimaan.',
                ]);
            }

            return $this->movements->create([
                'branch_id' => $branchId,
                'inventory_location_id' => $locationId,
                'product_id' => $productId,
                'supplier_id' => $original->supplier_id,
                'inventory_batch_id' => $batchId,
                'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_OUT,
                'movement_date' => $movementDate ?? now()->toDateString(),
                'quantity_in' => 0,
                'quantity_out' => $qty,
                'unit_cost' => (float) ($original->unit_cost ?? 0),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'notes' => $notes ?? sprintf('Pembalikan pergerakan pembelian #%d', $original->id),
                'created_by' => Auth::id(),
            ]);
        });

        $this->logInventoryMovement($movement);

        return $movement;
    }

    public function adjustOut(
        int $productId,
        int $locationId,
        float $qty,
        ?string $notes = null,
        ?int $inventoryBatchId = null,
    ): InventoryMovement {
        $this->assertPositiveQuantity($qty);

        $movement = DB::transaction(function () use ($productId, $locationId, $qty, $notes, $inventoryBatchId) {
            $branchId = $this->branchContext->requireId();
            $this->lockAndAssertProductInBranch($branchId, $productId);
            $this->lockAndAssertLocationInBranch($branchId, $locationId);

            $batch = null;

            if ($inventoryBatchId !== null) {
                $batch = $this->lockAndAssertBatchForMovement($branchId, $productId, $inventoryBatchId, forOutbound: true);

                $batchStock = $this->movements->currentStockByBatch($branchId, $productId, $locationId, $batch->id);

                if ($batchStock < $qty) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Stok batch pada lokasi ini tidak mencukupi.',
                    ]);
                }
            }

            $currentStock = $this->movements->currentStock($branchId, $productId, $locationId);

            if ($currentStock < $qty) {
                throw ValidationException::withMessages([
                    'quantity' => 'Stok pada lokasi ini tidak mencukupi.',
                ]);
            }

            return $this->movements->create([
                'branch_id' => $branchId,
                'inventory_location_id' => $locationId,
                'product_id' => $productId,
                'supplier_id' => null,
                'inventory_batch_id' => $batch?->id,
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

        $this->logInventoryMovement($movement);

        return $movement;
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

    public function getInventoryValue(?int $locationId = null, ?int $branchId = null): float
    {
        $branchId = $branchId ?? $this->branchContext->requireId();

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

    public function getStockByLocationSummary(?int $branchId = null): Collection
    {
        return $this->movements->stockByLocationSummary($branchId ?? $this->branchContext->requireId());
    }

    public function getRecentMovements(int $limit = 10, ?int $branchId = null): Collection
    {
        return $this->movements->recentMovements($branchId ?? $this->branchContext->requireId(), $limit);
    }

    /**
     * @return array{inventory_value: float}
     */
    public function getBranchSummary(?int $locationId = null, ?int $branchId = null): array
    {
        return [
            'inventory_value' => $this->getInventoryValue($locationId, $branchId),
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
        ?array $batchData = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $movementDate = null,
    ): InventoryMovement {
        $this->assertPositiveQuantity($qty);

        return DB::transaction(function () use ($movementType, $productId, $locationId, $qty, $unitCost, $supplierId, $notes, $batchData, $referenceType, $referenceId, $movementDate) {
            $branchId = $this->branchContext->requireId();
            $this->lockAndAssertProductInBranch($branchId, $productId);
            $this->lockAndAssertLocationInBranch($branchId, $locationId);

            if ($supplierId !== null) {
                $this->assertSupplierInBranch($branchId, $supplierId);
            }

            $batch = $this->resolveOrCreateBatch($branchId, $productId, $supplierId, $batchData);

            return $this->movements->create([
                'branch_id' => $branchId,
                'inventory_location_id' => $locationId,
                'product_id' => $productId,
                'supplier_id' => $supplierId,
                'inventory_batch_id' => $batch?->id,
                'movement_type' => $movementType,
                'movement_date' => $movementDate ?? now()->toDateString(),
                'quantity_in' => $qty,
                'quantity_out' => 0,
                'unit_cost' => max(0, $unitCost),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
        });
    }

    /**
     * @param  array{
     *     inventory_batch_id?: int|null,
     *     batch_number?: string|null,
     *     lot_number?: string|null,
     *     received_date?: string|null,
     *     expiry_date?: string|null,
     * }|null  $batchData
     */
    private function resolveOrCreateBatch(
        int $branchId,
        int $productId,
        ?int $supplierId,
        ?array $batchData,
    ): ?InventoryBatch {
        if ($batchData === null || $batchData === []) {
            return null;
        }

        if (! empty($batchData['inventory_batch_id'])) {
            return $this->lockAndAssertBatchForMovement(
                $branchId,
                $productId,
                (int) $batchData['inventory_batch_id'],
            );
        }

        $batchNumber = trim((string) ($batchData['batch_number'] ?? ''));

        if ($batchNumber === '') {
            return null;
        }

        $lotNumber = isset($batchData['lot_number']) && $batchData['lot_number'] !== ''
            ? trim((string) $batchData['lot_number'])
            : null;

        $receivedDate = $batchData['received_date'] ?? now()->toDateString();

        $duplicateExists = InventoryBatch::query()
            ->forBranch($branchId)
            ->forProduct($productId)
            ->where('batch_number', $batchNumber)
            ->where(function ($query) use ($lotNumber) {
                if ($lotNumber === null) {
                    $query->whereNull('lot_number');
                } else {
                    $query->where('lot_number', $lotNumber);
                }
            })
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'batch_number' => 'Kombinasi nomor batch dan lot sudah digunakan untuk produk ini.',
            ]);
        }

        $batch = InventoryBatch::create([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'supplier_id' => $supplierId,
            'batch_number' => $batchNumber,
            'lot_number' => $lotNumber,
            'received_date' => $receivedDate,
            'expiry_date' => $batchData['expiry_date'] ?? null,
            'notes' => null,
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);

        $user = Auth::user();
        $this->logActivity(
            InventoryActivityAction::INVENTORY_BATCH_CREATED,
            $batch,
            [
                'branch_id' => $batch->branch_id,
                'product_id' => $batch->product_id,
                'batch_number' => $batch->batch_number,
                'supplier_id' => $batch->supplier_id,
            ],
            null,
            $user instanceof User ? $user : null,
        );

        return $batch;
    }

    private function lockAndAssertBatchForMovement(
        int $branchId,
        int $productId,
        int $batchId,
        bool $forOutbound = false,
    ): InventoryBatch {
        $batch = InventoryBatch::query()
            ->where('branch_id', $branchId)
            ->whereKey($batchId)
            ->lockForUpdate()
            ->first();

        if (! $batch) {
            throw ValidationException::withMessages([
                'inventory_batch_id' => 'Batch tidak valid untuk cabang aktif.',
            ]);
        }

        if ($batch->product_id !== $productId) {
            throw ValidationException::withMessages([
                'inventory_batch_id' => 'Batch tidak sesuai dengan produk yang dipilih.',
            ]);
        }

        if (! $batch->is_active) {
            throw ValidationException::withMessages([
                'inventory_batch_id' => 'Batch tidak aktif dan tidak dapat digunakan.',
            ]);
        }

        if ($forOutbound && $batch->expiry_date !== null && $batch->expiry_date->lt(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'inventory_batch_id' => 'Batch ini sudah kedaluwarsa dan tidak dapat dikeluarkan.',
            ]);
        }

        return $batch;
    }

    private function assertBatchInBranch(int $branchId, int $batchId, int $productId): InventoryBatch
    {
        $batch = InventoryBatch::query()
            ->forBranch($branchId)
            ->whereKey($batchId)
            ->first();

        if (! $batch || $batch->product_id !== $productId) {
            throw ValidationException::withMessages([
                'inventory_batch_id' => 'Batch tidak valid untuk cabang aktif.',
            ]);
        }

        return $batch;
    }

    private function assertPositiveQuantity(float $qty): void
    {
        if ($qty <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Jumlah stok harus lebih besar dari nol.',
            ]);
        }
    }

    private function assertProductInBranch(int $branchId, int $productId): Product
    {
        $product = $this->products->findInBranch($branchId, $productId);

        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => 'Produk tidak valid untuk cabang aktif.',
            ]);
        }

        return $product;
    }

    private function assertLocationInBranch(int $branchId, int $locationId): InventoryLocation
    {
        $location = $this->locations->findInBranch($branchId, $locationId);

        if (! $location || ! $location->is_active) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Lokasi persediaan tidak valid untuk cabang aktif.',
            ]);
        }

        return $location;
    }

    private function assertSupplierInBranch(int $branchId, int $supplierId): Supplier
    {
        $supplier = $this->suppliers->findInBranch($branchId, $supplierId);

        if (! $supplier || ! $supplier->is_active) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Pemasok tidak valid untuk cabang aktif.',
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
                'product_id' => 'Produk tidak valid untuk cabang aktif.',
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
                'inventory_location_id' => 'Lokasi persediaan tidak valid untuk cabang aktif.',
            ]);
        }

        return $location;
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
