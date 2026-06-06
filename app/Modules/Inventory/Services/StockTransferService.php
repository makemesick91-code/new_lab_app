<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\StockTransferRepositoryInterface;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockTransferService
{
    public function __construct(
        private readonly StockTransferRepositoryInterface $transfers,
        private readonly InventoryMovementRepositoryInterface $movements,
        private readonly ProductRepositoryInterface $products,
        private readonly InventoryLocationRepositoryInterface $locations,
        private readonly BranchContext $branchContext,
    ) {}

    /**
     * @param  array<int, array{product_id: int, quantity: float, notes?: string|null}>  $items
     */
    public function createTransfer(
        int $sourceLocationId,
        int $destinationLocationId,
        array $items,
        ?string $notes = null,
        ?string $transferDate = null,
    ): StockTransfer {
        return DB::transaction(function () use ($sourceLocationId, $destinationLocationId, $items, $notes, $transferDate) {
            $branchId = $this->branchContext->requireId();
            $actorId = $this->currentActorId();
            $source = $this->assertActiveLocationInBranch($branchId, $sourceLocationId, 'source_inventory_location_id');
            $destination = $this->assertActiveLocationInBranch($branchId, $destinationLocationId, 'destination_inventory_location_id');
            $this->assertDifferentLocations($source, $destination);
            $normalizedItems = $this->normalizeAndValidateItems($branchId, $items);

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
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, notes?: string|null}>  $items
     */
    public function updateTransfer(
        int $transferId,
        int $sourceLocationId,
        int $destinationLocationId,
        array $items,
        ?string $notes = null,
        ?string $transferDate = null,
    ): StockTransfer {
        return DB::transaction(function () use ($transferId, $sourceLocationId, $destinationLocationId, $items, $notes, $transferDate) {
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
            $normalizedItems = $this->normalizeAndValidateItems($branchId, $items);

            $updated = $this->transfers->update($transfer, [
                'source_inventory_location_id' => $source->id,
                'destination_inventory_location_id' => $destination->id,
                'transfer_date' => $transferDate ?? $transfer->transfer_date->toDateString(),
                'notes' => $notes,
            ]);

            $this->transfers->replaceItems($updated, $normalizedItems);

            return $this->transfers->loadDetails($updated->refresh());
        });
    }

    public function submitTransfer(int $transferId): StockTransfer
    {
        return DB::transaction(function () use ($transferId) {
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
    }

    public function completeTransfer(int $transferId): StockTransfer
    {
        return DB::transaction(function () use ($transferId) {
            $branchId = $this->branchContext->requireId();
            $transfer = $this->lockTransferInBranch($branchId, $transferId);

            if ($transfer->status === StockTransfer::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'status' => 'Transfer stok sudah diselesaikan.',
                ]);
            }

            if ($transfer->status === StockTransfer::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => 'Transfer stok yang dibatalkan tidak bisa diselesaikan.',
                ]);
            }

            if ($transfer->status !== StockTransfer::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => 'Transfer stok harus diajukan sebelum diselesaikan.',
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
                $quantity = round((float) $groupedItems->sum(fn (StockTransferItem $item) => (float) $item->quantity), 2);
                $this->assertPositiveQuantity($quantity);

                $currentStock = $this->movements->currentStock($branchId, $product->id, $source->id);

                if ($currentStock < $quantity) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Stok sumber tidak mencukupi untuk menyelesaikan transfer.',
                    ]);
                }
            }

            foreach ($items as $item) {
                $product = $this->lockAndAssertActiveProductInBranch($branchId, (int) $item->product_id);
                $quantity = (float) $item->quantity;
                $this->assertPositiveQuantity($quantity);

                $this->createTransferMovement($transfer, $product, $source, 0, $quantity, InventoryMovement::TYPE_TRANSFER_OUT);
                $this->createTransferMovement($transfer, $product, $destination, $quantity, 0, InventoryMovement::TYPE_TRANSFER_IN);
            }

            return $this->transfers->update($transfer, [
                'status' => StockTransfer::STATUS_COMPLETED,
                'approved_by' => $this->currentActorId(),
                'completed_at' => now(),
            ]);
        });
    }

    public function cancelTransfer(int $transferId, ?string $notes = null): StockTransfer
    {
        return DB::transaction(function () use ($transferId, $notes) {
            $branchId = $this->branchContext->requireId();
            $transfer = $this->lockTransferInBranch($branchId, $transferId);

            if ($transfer->status === StockTransfer::STATUS_COMPLETED) {
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
            $this->assertActiveProductInBranch($branchId, (int) $item->product_id);
            $this->assertPositiveQuantity((float) $item->quantity);
        }
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, notes?: string|null}>  $items
     * @return array<int, array{product_id: int, quantity: float, notes?: string|null}>
     */
    private function normalizeAndValidateItems(int $branchId, array $items): array
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

            $this->assertActiveProductInBranch($branchId, $productId);
            $this->assertPositiveQuantity($quantity);

            if (! isset($normalized[$productId])) {
                $normalized[$productId] = [
                    'product_id' => $productId,
                    'quantity' => 0,
                    'notes' => null,
                ];
            }

            $normalized[$productId]['quantity'] = round($normalized[$productId]['quantity'] + $quantity, 2);
            $normalized[$productId]['notes'] = $notes ?: $normalized[$productId]['notes'];
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
    ): InventoryMovement {
        return $this->movements->create([
            'branch_id' => $transfer->branch_id,
            'inventory_location_id' => $location->id,
            'product_id' => $product->id,
            'supplier_id' => null,
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
}
