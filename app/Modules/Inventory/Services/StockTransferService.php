<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Enums\InventoryActivityAction;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\StockTransferRepositoryInterface;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Inventory\Services\Concerns\LogsInventoryActivity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockTransferService
{
    use LogsInventoryActivity;

    public function __construct(
        private readonly StockTransferRepositoryInterface $transfers,
        private readonly InventoryMovementRepositoryInterface $movements,
        private readonly ProductRepositoryInterface $products,
        private readonly InventoryLocationRepositoryInterface $locations,
        private readonly BranchContext $branchContext,
        private readonly InventoryActivityLogService $activityLogger,
        private readonly BatchStockOptionService $batchStockOptions,
    ) {}

    /**
     * @param  array<int, array{product_id: int, quantity: float, inventory_batch_id?: int|null, notes?: string|null}>  $items
     */
    public function createTransfer(
        int $sourceLocationId,
        int $destinationLocationId,
        array $items,
        ?string $notes = null,
        ?string $transferDate = null,
    ): StockTransfer {
        $result = DB::transaction(function () use ($sourceLocationId, $destinationLocationId, $items, $notes, $transferDate) {
            $branchId = $this->branchContext->requireId();
            $actorId = $this->currentActorId();
            $source = $this->assertActiveLocationInBranch($branchId, $sourceLocationId, 'source_inventory_location_id');
            $destination = $this->assertActiveLocationInBranch($branchId, $destinationLocationId, 'destination_inventory_location_id');
            $this->assertDifferentLocations($source, $destination);
            $normalizedItems = $this->normalizeAndValidateItems($branchId, $source->id, $items);

            $transfer = $this->transfers->create([
                'branch_id' => $branchId,
                'transfer_number' => $this->generateTransferNumber(),
                'source_inventory_location_id' => $source->id,
                'destination_inventory_location_id' => $destination->id,
                'transfer_date' => $transferDate ?? now()->toDateString(),
                'status' => StockTransfer::STATUS_DRAFT,
                'notes' => $notes,
                'requested_by' => $actorId,
                'approved_by' => null,
                'completed_at' => null,
                'created_by' => $actorId,
            ]);

            $this->transfers->replaceItems($transfer, $normalizedItems);

            return $this->transfers->loadDetails($transfer->refresh());
        });

        $this->logStockTransferActivity(InventoryActivityAction::STOCK_TRANSFER_CREATED, $result, null);

        return $result;
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, inventory_batch_id?: int|null, notes?: string|null}>  $items
     */
    public function updateTransfer(
        int $transferId,
        int $sourceLocationId,
        int $destinationLocationId,
        array $items,
        ?string $notes = null,
        ?string $transferDate = null,
    ): StockTransfer {
        $branchId = $this->branchContext->requireId();
        $existing = $this->transfers->findById($branchId, $transferId);
        $statusFrom = $existing?->status;

        $result = DB::transaction(function () use ($transferId, $sourceLocationId, $destinationLocationId, $items, $notes, $transferDate) {
            $branchId = $this->branchContext->requireId();
            $transfer = $this->lockTransferInBranch($branchId, $transferId);

            if ($transfer->status !== StockTransfer::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Transfer stok hanya bisa diubah saat masih DRAFT.',
                ]);
            }

            $source = $this->assertActiveLocationInBranch($branchId, $sourceLocationId, 'source_inventory_location_id');
            $destination = $this->assertActiveLocationInBranch($branchId, $destinationLocationId, 'destination_inventory_location_id');
            $this->assertDifferentLocations($source, $destination);
            $normalizedItems = $this->normalizeAndValidateItems($branchId, $source->id, $items);

            $updated = $this->transfers->update($transfer, [
                'source_inventory_location_id' => $source->id,
                'destination_inventory_location_id' => $destination->id,
                'transfer_date' => $transferDate ?? $transfer->transfer_date->toDateString(),
                'notes' => $notes,
            ]);

            $this->transfers->replaceItems($updated, $normalizedItems);

            return $this->transfers->loadDetails($updated->refresh());
        });

        $this->logStockTransferActivity(InventoryActivityAction::STOCK_TRANSFER_UPDATED, $result, $statusFrom);

        return $result;
    }

    public function submitTransfer(int $transferId): StockTransfer
    {
        $branchId = $this->branchContext->requireId();
        $existing = $this->transfers->findById($branchId, $transferId);
        $statusFrom = $existing?->status;

        $result = DB::transaction(function () use ($transferId) {
            $branchId = $this->branchContext->requireId();
            $transfer = $this->lockTransferInBranch($branchId, $transferId);

            if ($transfer->status !== StockTransfer::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Transfer stok hanya bisa diajukan dari status DRAFT.',
                ]);
            }

            $this->assertTransferReady($branchId, $transfer);

            return $this->transfers->update($transfer, [
                'status' => StockTransfer::STATUS_SUBMITTED,
            ]);
        });

        $this->logStockTransferActivity(InventoryActivityAction::STOCK_TRANSFER_SUBMITTED, $result, $statusFrom);

        return $result;
    }

    public function shipTransfer(int $transferId): StockTransfer
    {
        $branchId = $this->branchContext->requireId();
        $existing = $this->transfers->findById($branchId, $transferId);
        $statusFrom = $existing?->status;
        $createdMovements = [];

        $result = DB::transaction(function () use ($transferId, &$createdMovements) {
            $branchId = $this->branchContext->requireId();
            $transfer = $this->lockTransferInBranch($branchId, $transferId);

            if ($transfer->status === StockTransfer::STATUS_IN_TRANSIT) {
                throw ValidationException::withMessages([
                    'status' => 'Transfer stok sudah dikirim.',
                ]);
            }

            if ($transfer->status === StockTransfer::STATUS_RECEIVED
                || $transfer->status === StockTransfer::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'status' => 'Transfer stok yang sudah diterima tidak bisa dikirim.',
                ]);
            }

            if ($transfer->status === StockTransfer::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => 'Transfer stok yang dibatalkan tidak bisa dikirim.',
                ]);
            }

            if ($transfer->status !== StockTransfer::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => 'Transfer stok harus diajukan sebelum dikirim.',
                ]);
            }

            $source = $this->lockAndAssertActiveLocationInBranch($branchId, (int) $transfer->source_inventory_location_id, 'source_inventory_location_id');
            $destination = $this->lockAndAssertActiveLocationInBranch($branchId, (int) $transfer->destination_inventory_location_id, 'destination_inventory_location_id');
            $this->assertDifferentLocations($source, $destination);

            $items = StockTransferItem::query()
                ->where('stock_transfer_id', $transfer->id)
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Transfer stok harus memiliki minimal satu item.',
                ]);
            }

            foreach ($items->groupBy('product_id') as $productId => $groupedItems) {
                $product = $this->lockAndAssertActiveProductInBranch($branchId, (int) $productId);
                $quantity = round((float) $groupedItems->sum(fn (StockTransferItem $item) => (float) $item->quantity), 4);
                $this->assertPositiveQuantity($quantity);

                $currentStock = $this->movements->currentStock($branchId, $product->id, $source->id);

                if ($currentStock < $quantity) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Stok sumber tidak mencukupi untuk mengirim transfer.',
                    ]);
                }
            }

            foreach ($items as $item) {
                $product = $this->lockAndAssertActiveProductInBranch($branchId, (int) $item->product_id);
                $quantity = (float) $item->quantity;
                $this->assertPositiveQuantity($quantity);

                $batchId = $item->inventory_batch_id ? (int) $item->inventory_batch_id : null;

                if ($batchId !== null) {
                    $batch = $this->lockAndAssertBatchForTransfer($branchId, $product->id, $batchId, true);
                    $batchStock = $this->movements->currentStockByBatch($branchId, $product->id, $source->id, $batch->id);

                    if ($batchStock < $quantity) {
                        throw ValidationException::withMessages([
                            'quantity' => 'Stok batch pada lokasi ini tidak mencukupi.',
                        ]);
                    }
                }

                $movement = $this->createTransferMovement(
                    $transfer,
                    $product,
                    $source,
                    0,
                    $quantity,
                    InventoryMovement::TYPE_TRANSFER_OUT,
                    $batchId,
                );
                $createdMovements[] = $movement;
            }

            return $this->transfers->update($transfer, [
                'status' => StockTransfer::STATUS_IN_TRANSIT,
                'shipped_by' => $this->currentActorId(),
                'shipped_at' => now(),
            ]);
        });

        $this->logStockTransferActivity(InventoryActivityAction::STOCK_TRANSFER_APPROVED, $result, $statusFrom);

        foreach ($createdMovements as $movement) {
            $this->logInventoryMovement($movement);
        }

        return $result;
    }

    public function receiveTransfer(int $transferId): StockTransfer
    {
        $branchId = $this->branchContext->requireId();
        $existing = $this->transfers->findById($branchId, $transferId);
        $statusFrom = $existing?->status;
        $createdMovements = [];

        $result = DB::transaction(function () use ($transferId, &$createdMovements) {
            $branchId = $this->branchContext->requireId();
            $transfer = $this->lockTransferInBranch($branchId, $transferId);

            if ($transfer->status === StockTransfer::STATUS_RECEIVED
                || $transfer->status === StockTransfer::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'status' => 'Transfer stok sudah diterima.',
                ]);
            }

            if ($transfer->status === StockTransfer::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => 'Transfer stok yang dibatalkan tidak bisa diterima.',
                ]);
            }

            if ($transfer->status !== StockTransfer::STATUS_IN_TRANSIT) {
                throw ValidationException::withMessages([
                    'status' => 'Transfer stok harus dalam perjalanan sebelum diterima.',
                ]);
            }

            $source = $this->lockAndAssertActiveLocationInBranch($branchId, (int) $transfer->source_inventory_location_id, 'source_inventory_location_id');
            $destination = $this->lockAndAssertActiveLocationInBranch($branchId, (int) $transfer->destination_inventory_location_id, 'destination_inventory_location_id');
            $this->assertDifferentLocations($source, $destination);

            $items = StockTransferItem::query()
                ->where('stock_transfer_id', $transfer->id)
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Transfer stok harus memiliki minimal satu item.',
                ]);
            }

            $this->assertReceiveLedgerIntegrity($branchId, $transfer, $items);

            foreach ($items as $item) {
                $product = $this->lockAndAssertActiveProductInBranch($branchId, (int) $item->product_id);
                $quantity = (float) $item->quantity;
                $this->assertPositiveQuantity($quantity);

                $movement = $this->createTransferMovement(
                    $transfer,
                    $product,
                    $destination,
                    $quantity,
                    0,
                    InventoryMovement::TYPE_TRANSFER_IN,
                    $item->inventory_batch_id ? (int) $item->inventory_batch_id : null,
                );
                $createdMovements[] = $movement;
            }

            return $this->transfers->update($transfer, [
                'status' => StockTransfer::STATUS_RECEIVED,
                'approved_by' => $this->currentActorId(),
                'completed_at' => now(),
            ]);
        });

        $this->logStockTransferActivity(InventoryActivityAction::STOCK_TRANSFER_RECEIVED, $result, $statusFrom);

        foreach ($createdMovements as $movement) {
            $this->logInventoryMovement($movement);
        }

        return $result;
    }

    /**
     * @return array{
     *     ledger_movements: Collection<int, InventoryMovement>,
     *     transfer_out_movements: Collection<int, InventoryMovement>,
     *     transfer_in_movements: Collection<int, InventoryMovement>,
     *     total_out: float,
     *     total_in: float,
     *     in_transit_qty: float
     * }
     */
    public function getTransferMovementSummary(StockTransfer $transfer): array
    {
        $branchId = $this->branchContext->requireId();

        if ((int) $transfer->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'stock_transfer_id' => 'Transfer stok tidak valid untuk cabang aktif.',
            ]);
        }

        $movements = $transfer->isInTransit() || $transfer->isReceived()
            ? $this->movements->transferMovements($branchId, $transfer)
            : collect();

        return $this->buildTransferMovementSummary($movements);
    }

    public function cancelTransfer(int $transferId, ?string $notes = null): StockTransfer
    {
        $branchId = $this->branchContext->requireId();
        $existing = $this->transfers->findById($branchId, $transferId);
        $statusFrom = $existing?->status;

        $result = DB::transaction(function () use ($transferId, $notes) {
            $branchId = $this->branchContext->requireId();
            $transfer = $this->lockTransferInBranch($branchId, $transferId);

            if ($transfer->status === StockTransfer::STATUS_IN_TRANSIT) {
                throw ValidationException::withMessages([
                    'status' => 'Transfer stok yang sudah dikirim tidak bisa dibatalkan.',
                ]);
            }

            if ($transfer->status === StockTransfer::STATUS_RECEIVED
                || $transfer->status === StockTransfer::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'status' => 'Transfer stok yang sudah selesai tidak bisa dibatalkan.',
                ]);
            }

            if ($transfer->status === StockTransfer::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => 'Transfer stok sudah dibatalkan.',
                ]);
            }

            return $this->transfers->update($transfer, [
                'status' => StockTransfer::STATUS_CANCELLED,
                'notes' => $notes ?? $transfer->notes,
            ]);
        });

        $this->logStockTransferActivity(InventoryActivityAction::STOCK_TRANSFER_CANCELLED, $result, $statusFrom);

        return $result;
    }

    public function getTransferDetails(int $transferId): StockTransfer
    {
        $branchId = $this->branchContext->requireId();
        $transfer = $this->transfers->findById($branchId, $transferId);

        if (! $transfer) {
            throw ValidationException::withMessages([
                'stock_transfer_id' => 'Transfer stok tidak valid untuk cabang aktif.',
            ]);
        }

        return $transfer;
    }

    /**
     * @param  Collection<int, StockTransferItem>  $items
     */
    private function assertReceiveLedgerIntegrity(int $branchId, StockTransfer $transfer, Collection $items): void
    {
        $movements = $this->movements->lockTransferMovementsForUpdate($branchId, $transfer);
        $outMovements = $movements
            ->where('movement_type', InventoryMovement::TYPE_TRANSFER_OUT)
            ->values();
        $inMovements = $movements
            ->where('movement_type', InventoryMovement::TYPE_TRANSFER_IN)
            ->values();

        if ($inMovements->isNotEmpty()) {
            throw ValidationException::withMessages([
                'status' => 'Transfer stok sudah memiliki ledger masuk dan tidak bisa diterima ulang.',
            ]);
        }

        if ($outMovements->isEmpty()) {
            throw ValidationException::withMessages([
                'movement' => 'Transfer stok belum memiliki ledger keluar yang valid.',
            ]);
        }

        $expected = [];

        foreach ($items as $item) {
            $productId = (int) $item->product_id;
            $batchId = $item->inventory_batch_id ? (int) $item->inventory_batch_id : null;
            $quantity = (float) $item->quantity;
            $this->assertPositiveQuantity($quantity);

            $key = $this->movementLineKey($productId, $batchId);
            $expected[$key] = [
                'product_id' => $productId,
                'inventory_batch_id' => $batchId,
                'quantity' => round(($expected[$key]['quantity'] ?? 0) + $quantity, 4),
            ];
        }

        $actualOut = [];

        foreach ($outMovements as $movement) {
            $quantityOut = (float) $movement->quantity_out;
            $quantityIn = (float) $movement->quantity_in;
            $productId = (int) $movement->product_id;
            $batchId = $movement->inventory_batch_id ? (int) $movement->inventory_batch_id : null;
            $key = $this->movementLineKey($productId, $batchId);

            if ((int) $movement->branch_id !== (int) $transfer->branch_id
                || $movement->reference_type !== $transfer->getTable()
                || (int) $movement->reference_id !== (int) $transfer->id
                || (int) $movement->inventory_location_id !== (int) $transfer->source_inventory_location_id
                || $quantityOut <= 0
                || $quantityIn !== 0.0
                || ! isset($expected[$key])) {
                throw ValidationException::withMessages([
                    'movement' => 'Ledger keluar transfer tidak sesuai dengan dokumen transfer.',
                ]);
            }

            $actualOut[$key] = [
                'quantity' => round(($actualOut[$key]['quantity'] ?? 0) + $quantityOut, 4),
            ];
        }

        foreach ($expected as $key => $line) {
            if (! isset($actualOut[$key]) || ! $this->quantitiesMatch($actualOut[$key]['quantity'], $line['quantity'])) {
                throw ValidationException::withMessages([
                    'movement' => 'Ledger keluar transfer tidak sesuai dengan dokumen transfer.',
                ]);
            }
        }

        $totalOut = round(array_sum(array_column($actualOut, 'quantity')), 4);
        $proposedIn = round(array_sum(array_column($expected, 'quantity')), 4);
        $existingIn = round((float) $inMovements->sum(fn (InventoryMovement $movement) => (float) $movement->quantity_in), 4);

        if ($totalOut <= 0) {
            throw ValidationException::withMessages([
                'movement' => 'Transfer stok belum memiliki ledger keluar yang valid.',
            ]);
        }

        if (($existingIn + $proposedIn) - $totalOut > 0.0001) {
            throw ValidationException::withMessages([
                'quantity' => 'Jumlah ledger masuk transfer tidak boleh melebihi ledger keluar.',
            ]);
        }
    }

    private function assertTransferReady(int $branchId, StockTransfer $transfer): void
    {
        $source = $this->assertActiveLocationInBranch($branchId, (int) $transfer->source_inventory_location_id, 'source_inventory_location_id');
        $destination = $this->assertActiveLocationInBranch($branchId, (int) $transfer->destination_inventory_location_id, 'destination_inventory_location_id');
        $this->assertDifferentLocations($source, $destination);

        $items = $transfer->items()->get();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Transfer stok harus memiliki minimal satu item.',
            ]);
        }

        foreach ($items as $item) {
            $product = $this->assertActiveProductInBranch($branchId, (int) $item->product_id);
            $this->assertPositiveQuantity((float) $item->quantity);

            if ($product->requires_batch_tracking) {
                if (! $item->inventory_batch_id) {
                    throw ValidationException::withMessages([
                        'inventory_batch_id' => 'Batch wajib dipilih untuk produk dengan pelacakan batch.',
                    ]);
                }

                $this->assertBatchForTransferItem($branchId, (int) $item->product_id, (int) $item->inventory_batch_id);
            } elseif ($item->inventory_batch_id) {
                throw ValidationException::withMessages([
                    'inventory_batch_id' => 'Batch tidak diperlukan untuk produk tanpa pelacakan batch.',
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, inventory_batch_id?: int|null, notes?: string|null}>  $items
     * @return array<int, array{product_id: int, quantity: float, inventory_batch_id?: int|null, notes?: string|null}>
     */
    private function normalizeAndValidateItems(int $branchId, int $sourceLocationId, array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'Transfer stok harus memiliki minimal satu item.',
            ]);
        }

        $normalized = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (float) ($item['quantity'] ?? 0);
            $notes = $item['notes'] ?? null;
            $product = $this->assertActiveProductInBranch($branchId, $productId);
            $batchId = isset($item['inventory_batch_id']) && $item['inventory_batch_id'] !== '' && $item['inventory_batch_id'] !== null
                ? (int) $item['inventory_batch_id']
                : null;

            $this->assertPositiveQuantity($quantity);

            if ($product->requires_batch_tracking) {
                if ($batchId === null) {
                    throw ValidationException::withMessages([
                        'inventory_batch_id' => 'Batch wajib dipilih untuk produk dengan pelacakan batch.',
                    ]);
                }

                $this->assertBatchForTransferItem($branchId, $productId, $batchId);
            } else {
                $batchId = null;
            }

            if ($batchId !== null) {
                $this->batchStockOptions->assertBatchAvailableForProductLocation(
                    $branchId,
                    $productId,
                    $sourceLocationId,
                    $batchId,
                    $quantity,
                );
            }

            $lineKey = $productId.':'.($batchId ?? 'none');

            if (! isset($normalized[$lineKey])) {
                $normalized[$lineKey] = [
                    'product_id' => $productId,
                    'inventory_batch_id' => $batchId,
                    'quantity' => 0,
                    'notes' => null,
                ];
            }

            $normalized[$lineKey]['quantity'] = round($normalized[$lineKey]['quantity'] + $quantity, 4);
            $normalized[$lineKey]['notes'] = $notes ?: $normalized[$lineKey]['notes'];
        }

        return array_values($normalized);
    }

    private function createTransferMovement(
        StockTransfer $transfer,
        Product $product,
        InventoryLocation $location,
        float $quantityIn,
        float $quantityOut,
        string $movementType,
        ?int $inventoryBatchId = null,
    ): InventoryMovement {
        return $this->movements->create([
            'branch_id' => $transfer->branch_id,
            'inventory_location_id' => $location->id,
            'product_id' => $product->id,
            'supplier_id' => null,
            'inventory_batch_id' => $inventoryBatchId,
            'movement_type' => $movementType,
            'movement_date' => $transfer->transfer_date->toDateString(),
            'quantity_in' => $quantityIn,
            'quantity_out' => $quantityOut,
            'unit_cost' => max(0, (float) $product->average_cost),
            'reference_type' => $transfer->getTable(),
            'reference_id' => $transfer->id,
            'notes' => 'Dihasilkan dari transfer stok '.$transfer->transfer_number,
            'created_by' => Auth::id(),
        ]);
    }

    private function lockTransferInBranch(int $branchId, int $transferId): StockTransfer
    {
        $transfer = StockTransfer::query()
            ->where('branch_id', $branchId)
            ->whereKey($transferId)
            ->lockForUpdate()
            ->first();

        if (! $transfer) {
            throw ValidationException::withMessages([
                'stock_transfer_id' => 'Transfer stok tidak valid untuk cabang aktif.',
            ]);
        }

        return $transfer;
    }

    private function assertDifferentLocations(InventoryLocation $source, InventoryLocation $destination): void
    {
        if ($source->id === $destination->id) {
            throw ValidationException::withMessages([
                'destination_inventory_location_id' => 'Lokasi tujuan harus berbeda dari lokasi sumber.',
            ]);
        }
    }

    private function assertActiveLocationInBranch(int $branchId, int $locationId, string $field): InventoryLocation
    {
        $location = $this->locations->findInBranch($branchId, $locationId);

        if (! $location || ! $location->is_active) {
            throw ValidationException::withMessages([
                $field => 'Lokasi persediaan tidak valid untuk cabang aktif.',
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

    private function lockAndAssertActiveLocationInBranch(int $branchId, int $locationId, string $field): InventoryLocation
    {
        $location = InventoryLocation::query()
            ->where('branch_id', $branchId)
            ->whereKey($locationId)
            ->lockForUpdate()
            ->first();

        if (! $location || ! $location->is_active) {
            throw ValidationException::withMessages([
                $field => 'Lokasi persediaan tidak valid untuk cabang aktif.',
            ]);
        }

        return $location;
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

    private function assertPositiveQuantity(float $quantity): void
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Jumlah transfer harus lebih besar dari nol.',
            ]);
        }
    }

    /**
     * @param  Collection<int, InventoryMovement>  $movements
     * @return array{
     *     ledger_movements: Collection<int, InventoryMovement>,
     *     transfer_out_movements: Collection<int, InventoryMovement>,
     *     transfer_in_movements: Collection<int, InventoryMovement>,
     *     total_out: float,
     *     total_in: float,
     *     in_transit_qty: float
     * }
     */
    private function buildTransferMovementSummary(Collection $movements): array
    {
        $outMovements = $movements
            ->where('movement_type', InventoryMovement::TYPE_TRANSFER_OUT)
            ->values();
        $inMovements = $movements
            ->where('movement_type', InventoryMovement::TYPE_TRANSFER_IN)
            ->values();
        $totalOut = round((float) $outMovements->sum(fn (InventoryMovement $movement) => (float) $movement->quantity_out), 4);
        $totalIn = round((float) $inMovements->sum(fn (InventoryMovement $movement) => (float) $movement->quantity_in), 4);

        return [
            'ledger_movements' => $movements->values(),
            'transfer_out_movements' => $outMovements,
            'transfer_in_movements' => $inMovements,
            'total_out' => $totalOut,
            'total_in' => $totalIn,
            'in_transit_qty' => max(0.0, round($totalOut - $totalIn, 4)),
        ];
    }

    private function movementLineKey(int $productId, ?int $batchId): string
    {
        return $productId.':'.($batchId ?? 'none');
    }

    private function quantitiesMatch(float $left, float $right): bool
    {
        return abs(round($left, 4) - round($right, 4)) <= 0.0001;
    }

    private function assertBatchForTransferItem(int $branchId, int $productId, int $batchId): void
    {
        $this->lockAndAssertBatchForTransfer($branchId, $productId, $batchId, false);
    }

    private function lockAndAssertBatchForTransfer(
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

    private function currentActorId(): int
    {
        $actorId = Auth::id();

        if (! $actorId) {
            throw ValidationException::withMessages([
                'created_by' => 'Pengguna aktif diperlukan untuk transfer stok.',
            ]);
        }

        return (int) $actorId;
    }

    private function generateTransferNumber(): string
    {
        return 'TRF-'.now()->format('Ym').'-'.Str::upper(Str::random(6));
    }

    private function logStockTransferActivity(string $action, StockTransfer $transfer, ?string $statusFrom): void
    {
        $transfer->loadMissing('items');

        $metadata = [
            'document_number' => $transfer->transfer_number,
            'branch_id' => $transfer->branch_id,
            'status_to' => $transfer->status,
            'source_location_id' => $transfer->source_inventory_location_id,
            'destination_location_id' => $transfer->destination_inventory_location_id,
            'item_count' => $transfer->items->count(),
        ];

        if ($statusFrom !== null) {
            $metadata['status_from'] = $statusFrom;
        }

        $user = Auth::user();
        $this->logActivity($action, $transfer, $metadata, null, $user instanceof User ? $user : null);
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
