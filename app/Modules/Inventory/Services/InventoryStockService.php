<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\SupplierRepositoryInterface;
use App\Modules\Inventory\Models\InventoryBatch;
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
        $this->assertProductInBranch($branchId, $productId);
        $this->assertLocationInBranch($branchId, $locationId);

        return $this->listActiveBatchesForProduct($productId)
            ->map(function (InventoryBatch $batch) use ($branchId, $productId, $locationId) {
                $stock = $this->movements->currentStockByBatch($branchId, $productId, $locationId, $batch->id);

                return [
                    'id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'lot_number' => $batch->lot_number,
                    'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                    'stock' => $stock,
                ];
            })
            ->filter(fn (array $batch) => $batch['stock'] > 0)
            ->values();
    }

    /**
     * @return array<int, array<int, array<int, array{id: int, batch_number: string, lot_number: string|null, expiry_date: string|null, stock: float}>>>
     */
    public function batchOptionsMatrixForTransfer(): array
    {
        $branchId = $this->branchContext->requireId();
        $products = $this->products->listActive($branchId);
        $locations = $this->locations->listActive($branchId);
        $matrix = [];

        foreach ($products as $product) {
            foreach ($locations as $location) {
                $batches = $this->listBatchesWithStockAtLocation($product->id, $location->id);

                if ($batches->isNotEmpty()) {
                    $matrix[$product->id][$location->id] = $batches->all();
                }
            }
        }

        return $matrix;
    }

    public function createOpeningStock(
        int $productId,
        int $locationId,
        float $qty,
        float $unitCost = 0,
        ?string $notes = null,
        ?array $batchData = null,
    ): InventoryMovement {
        return $this->createInboundMovement(
            InventoryMovement::TYPE_OPENING,
            $productId,
            $locationId,
            $qty,
            $unitCost,
            null,
            $notes,
            $batchData,
        );
    }

    public function receiveStock(
        int $productId,
        int $locationId,
        float $qty,
        float $unitCost = 0,
        ?int $supplierId = null,
        ?string $notes = null,
        ?array $batchData = null,
    ): InventoryMovement {
        return $this->createInboundMovement(
            InventoryMovement::TYPE_PURCHASE,
            $productId,
            $locationId,
            $qty,
            $unitCost,
            $supplierId,
            $notes,
            $batchData,
        );
    }

    public function adjustIn(
        int $productId,
        int $locationId,
        float $qty,
        ?string $notes = null,
        ?array $batchData = null,
    ): InventoryMovement {
        return $this->createInboundMovement(
            InventoryMovement::TYPE_ADJUSTMENT_IN,
            $productId,
            $locationId,
            $qty,
            0,
            null,
            $notes,
            $batchData,
        );
    }

    public function adjustOut(
        int $productId,
        int $locationId,
        float $qty,
        ?string $notes = null,
        ?int $inventoryBatchId = null,
    ): InventoryMovement {
        $this->assertPositiveQuantity($qty);

        return DB::transaction(function () use ($productId, $locationId, $qty, $notes, $inventoryBatchId) {
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

    /**
     * @return array{inventory_value: float}
     */
    public function getBranchSummary(?int $locationId = null): array
    {
        return [
            'inventory_value' => $this->getInventoryValue($locationId),
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
    ): InventoryMovement {
        $this->assertPositiveQuantity($qty);

        return DB::transaction(function () use ($movementType, $productId, $locationId, $qty, $unitCost, $supplierId, $notes, $batchData) {
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

        return InventoryBatch::create([
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
}
