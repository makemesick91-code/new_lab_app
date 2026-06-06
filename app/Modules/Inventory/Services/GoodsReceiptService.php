<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\GoodsReceiptRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\PurchaseOrderRepositoryInterface;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceiptService
{
    private const RECEIVABLE_PO_STATUSES = [
        PurchaseOrder::STATUS_APPROVED,
        PurchaseOrder::STATUS_SENT,
        PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
    ];

    public function __construct(
        private readonly GoodsReceiptRepositoryInterface $goodsReceipts,
        private readonly PurchaseOrderRepositoryInterface $purchaseOrders,
        private readonly ProductRepositoryInterface $products,
        private readonly InventoryLocationRepositoryInterface $locations,
        private readonly InventoryStockService $inventoryStock,
        private readonly BranchContext $branchContext,
    ) {}

    public function listForBranch(int $branchId, array $filters = []): LengthAwarePaginator
    {
        return $this->goodsReceipts->paginateForBranch($branchId, $filters);
    }

    public function findEligiblePurchaseOrderForCreate(int $purchaseOrderId): PurchaseOrder
    {
        $branchId = $this->branchContext->requireId();
        $purchaseOrder = $this->purchaseOrders->findForBranch($branchId, $purchaseOrderId);

        if ($purchaseOrder === null) {
            throw ValidationException::withMessages([
                'purchase_order_id' => 'Pesanan pembelian tidak ditemukan atau tidak dapat diterima.',
            ]);
        }

        $this->validateReceivablePurchaseOrder($purchaseOrder, $branchId);

        return $purchaseOrder->load(['supplier', 'items.product', 'items.inventoryLocation']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildPrefillItemsFromPurchaseOrder(PurchaseOrder $purchaseOrder): array
    {
        $purchaseOrder->loadMissing('items.product', 'items.inventoryLocation');
        $prefillItems = [];

        foreach ($purchaseOrder->items as $poItem) {
            $remainingQty = $this->calculateRemainingQty($poItem);

            if ($remainingQty <= 0) {
                continue;
            }

            $prefillItems[] = [
                'purchase_order_item_id' => $poItem->id,
                'product_id' => (int) $poItem->product_id,
                'product_name' => $poItem->product?->name,
                'requires_batch_tracking' => (bool) ($poItem->product?->requires_batch_tracking ?? false),
                'inventory_location_id' => $poItem->inventory_location_id,
                'quantity_ordered' => (float) $poItem->quantity_ordered,
                'previously_received_qty' => $this->calculatePreviouslyReceivedQty($poItem->id),
                'remaining_qty' => $remainingQty,
                'accepted_qty' => $remainingQty,
                'rejected_qty' => 0.0,
                'unit_cost' => (float) ($poItem->unit_price ?? 0),
            ];
        }

        return $prefillItems;
    }

    /**
     * @param  array{purchase_order_id: int, receipt_date: string, supplier_delivery_number?: string|null, supplier_invoice_number?: string|null, notes?: string|null, items: array<int, array{purchase_order_item_id: int, product_id: int, inventory_location_id: int, accepted_qty: float, rejected_qty?: float, notes?: string|null}>}  $data
     */
    public function createFromPurchaseOrder(array $data, User $user): GoodsReceipt
    {
        return DB::transaction(function () use ($data, $user) {
            $branchId = $this->branchContext->requireId();
            $purchaseOrder = $this->lockPurchaseOrderInBranch($branchId, (int) $data['purchase_order_id']);

            if ($purchaseOrder === null) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => 'Pesanan pembelian tidak ditemukan atau tidak dapat diterima.',
                ]);
            }

            $this->validateReceivablePurchaseOrder($purchaseOrder, $branchId);
            $normalizedItems = $this->normalizeAndValidateItems($branchId, $purchaseOrder, $data['items'], $data['receipt_date'] ?? null);

            $goodsReceipt = $this->goodsReceipts->create([
                'branch_id' => $branchId,
                'purchase_order_id' => $purchaseOrder->id,
                'receipt_number' => $this->generateReceiptNumber($branchId, $data['receipt_date']),
                'receipt_date' => $data['receipt_date'],
                'supplier_delivery_number' => $data['supplier_delivery_number'] ?? null,
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
                'status' => GoodsReceipt::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->goodsReceipts->replaceItems($goodsReceipt, $normalizedItems);

            return $this->loadDetails($goodsReceipt->refresh());
        });
    }

    /**
     * @param  array{receipt_date?: string, supplier_delivery_number?: string|null, supplier_invoice_number?: string|null, notes?: string|null, items: array<int, array{purchase_order_item_id: int, product_id: int, inventory_location_id: int, accepted_qty: float, rejected_qty?: float, notes?: string|null}>}  $data
     */
    public function updateDraft(GoodsReceipt $goodsReceipt, array $data, User $user): GoodsReceipt
    {
        return DB::transaction(function () use ($goodsReceipt, $data) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->goodsReceipts->lockForPosting($goodsReceipt->id, $branchId);

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'goods_receipt' => 'Penerimaan barang tidak ditemukan di cabang aktif.',
                ]);
            }

            $this->ensureBranchMatches($locked, $branchId);
            $this->ensureNotPosted($locked);
            $this->assertStatus($locked, GoodsReceipt::STATUS_DRAFT);

            $purchaseOrder = $this->lockPurchaseOrderInBranch($branchId, (int) $locked->purchase_order_id);

            if ($purchaseOrder === null) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => 'Pesanan pembelian tidak ditemukan atau tidak dapat diterima.',
                ]);
            }

            $this->validateReceivablePurchaseOrder($purchaseOrder, $branchId);
            $normalizedItems = $this->normalizeAndValidateItems(
                $branchId,
                $purchaseOrder,
                $data['items'],
                $data['receipt_date'] ?? $locked->receipt_date->toDateString(),
            );

            $updated = $this->goodsReceipts->update($locked, [
                'receipt_date' => $data['receipt_date'] ?? $locked->receipt_date->toDateString(),
                'supplier_delivery_number' => $data['supplier_delivery_number'] ?? $locked->supplier_delivery_number,
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? $locked->supplier_invoice_number,
                'notes' => $data['notes'] ?? $locked->notes,
            ]);

            $this->goodsReceipts->replaceItems($updated, $normalizedItems);

            return $this->loadDetails($updated->refresh());
        });
    }

    public function submit(GoodsReceipt $goodsReceipt, User $user): GoodsReceipt
    {
        return DB::transaction(function () use ($goodsReceipt, $user) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->goodsReceipts->lockForPosting($goodsReceipt->id, $branchId);

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'goods_receipt' => 'Penerimaan barang tidak ditemukan di cabang aktif.',
                ]);
            }

            $this->ensureBranchMatches($locked, $branchId);
            $this->ensureNotPosted($locked);
            $this->assertStatus($locked, GoodsReceipt::STATUS_DRAFT);
            $this->assertHasPostableQuantity($locked);

            return $this->goodsReceipts->update($locked, [
                'status' => GoodsReceipt::STATUS_SUBMITTED,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
            ]);
        });
    }

    public function post(GoodsReceipt $goodsReceipt, User $user): GoodsReceipt
    {
        return DB::transaction(function () use ($goodsReceipt, $user) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->goodsReceipts->lockForPosting($goodsReceipt->id, $branchId);

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'goods_receipt' => 'Penerimaan barang tidak ditemukan di cabang aktif.',
                ]);
            }

            $this->ensureBranchMatches($locked, $branchId);
            $this->ensureNotPosted($locked);
            $this->assertStatus($locked, [GoodsReceipt::STATUS_DRAFT, GoodsReceipt::STATUS_SUBMITTED]);

            if ($locked->isCancelled()) {
                throw ValidationException::withMessages([
                    'status' => 'Penerimaan barang yang dibatalkan tidak dapat diposting.',
                ]);
            }

            $purchaseOrder = $this->lockPurchaseOrderInBranch($branchId, (int) $locked->purchase_order_id);

            if ($purchaseOrder === null) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => 'Pesanan pembelian tidak ditemukan atau tidak dapat diterima.',
                ]);
            }

            $this->validateReceivablePurchaseOrder($purchaseOrder, $branchId);

            $locked->load('items.purchaseOrderItem');
            $items = $locked->items;

            $this->validateQuantities(
                $items->map(fn (GoodsReceiptItem $item) => [
                    'purchase_order_item_id' => $item->purchase_order_item_id,
                    'accepted_qty' => (float) $item->accepted_qty,
                ])->all(),
                $purchaseOrder,
            );
            $this->assertHasPostableQuantity($locked);

            $supplierId = $purchaseOrder->supplier_id !== null ? (int) $purchaseOrder->supplier_id : null;
            $movementNotes = sprintf('Dihasilkan dari penerimaan barang %s', $locked->receipt_number);

            foreach ($items as $item) {
                $poItem = $item->purchaseOrderItem;
                $acceptedQty = (float) $item->accepted_qty;
                $costSnapshot = $this->snapshotCostFromPurchaseOrderItem($poItem, $acceptedQty);

                if ($acceptedQty > 0) {
                    $item->loadMissing('product');
                    $product = $item->product;

                    if ($product === null || $product->branch_id !== $branchId || ! $product->is_active) {
                        throw ValidationException::withMessages([
                            'items' => 'Produk tidak valid untuk cabang aktif.',
                        ]);
                    }

                    $batchData = $this->buildBatchDataForPost($item, $product, $locked->receipt_date->toDateString());

                    $movement = $this->inventoryStock->receiveStock(
                        (int) $item->product_id,
                        (int) $item->inventory_location_id,
                        $acceptedQty,
                        $costSnapshot['unit_cost'],
                        $supplierId,
                        $movementNotes,
                        $batchData,
                        $locked->getTable(),
                        $locked->id,
                        $locked->receipt_date->toDateString(),
                    );

                    $this->goodsReceipts->updateItem($item, [
                        'unit_cost' => $costSnapshot['unit_cost'],
                        'line_total' => $costSnapshot['line_total'],
                        'inventory_movement_id' => $movement->id,
                        'inventory_batch_id' => $movement->inventory_batch_id,
                    ]);

                    $this->purchaseOrders->incrementItemQuantityReceived(
                        (int) $item->purchase_order_item_id,
                        $acceptedQty,
                    );
                } else {
                    $this->goodsReceipts->updateItem($item, [
                        'unit_cost' => $costSnapshot['unit_cost'],
                        'line_total' => $costSnapshot['line_total'],
                    ]);
                }
            }

            $purchaseOrder->refresh()->load('items');
            $this->updatePurchaseOrderReceivingStatus($purchaseOrder);

            return $this->goodsReceipts->update($locked, [
                'status' => GoodsReceipt::STATUS_POSTED,
                'posted_by' => $user->id,
                'posted_at' => now(),
            ]);
        });
    }

    public function cancel(GoodsReceipt $goodsReceipt, User $user): GoodsReceipt
    {
        return DB::transaction(function () use ($goodsReceipt, $user) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->goodsReceipts->lockForPosting($goodsReceipt->id, $branchId);

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'goods_receipt' => 'Penerimaan barang tidak ditemukan di cabang aktif.',
                ]);
            }

            $this->ensureBranchMatches($locked, $branchId);
            $this->ensureNotPosted($locked);
            $this->assertStatus($locked, GoodsReceipt::STATUS_DRAFT);

            return $this->goodsReceipts->update($locked, [
                'status' => GoodsReceipt::STATUS_CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
            ]);
        });
    }

    public function validateReceivablePurchaseOrder(PurchaseOrder $purchaseOrder, int $branchId): void
    {
        if ($purchaseOrder->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'purchase_order_id' => 'Pesanan pembelian tidak ditemukan atau tidak dapat diterima.',
            ]);
        }

        if (! in_array($purchaseOrder->status, self::RECEIVABLE_PO_STATUSES, true)) {
            throw ValidationException::withMessages([
                'purchase_order_id' => 'Pesanan pembelian tidak ditemukan atau tidak dapat diterima.',
            ]);
        }
    }

    /**
     * @param  array<int, array{purchase_order_item_id: int, accepted_qty: float}>  $items
     */
    public function validateQuantities(array $items, PurchaseOrder $purchaseOrder): void
    {
        $purchaseOrder->loadMissing('items');
        $poItems = $purchaseOrder->items->keyBy('id');

        foreach ($items as $index => $item) {
            $poItemId = (int) $item['purchase_order_item_id'];
            $acceptedQty = (float) $item['accepted_qty'];

            if ($acceptedQty < 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.accepted_qty" => 'Jumlah diterima baik harus nol atau lebih.',
                ]);
            }

            /** @var PurchaseOrderItem|null $poItem */
            $poItem = $poItems->get($poItemId);

            if ($poItem === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.purchase_order_item_id" => 'Item pesanan pembelian tidak valid untuk dokumen terkait.',
                ]);
            }

            $previouslyReceived = $this->calculatePreviouslyReceivedQty($poItemId);
            $newCumulative = $previouslyReceived + $acceptedQty;

            if ($newCumulative > (float) $poItem->quantity_ordered) {
                throw ValidationException::withMessages([
                    "items.{$index}.accepted_qty" => 'Jumlah diterima baik melebihi sisa pesanan untuk item ini.',
                ]);
            }
        }
    }

    public function calculatePreviouslyReceivedQty(int $purchaseOrderItemId): float
    {
        return $this->goodsReceipts->getPreviouslyReceivedQty($purchaseOrderItemId);
    }

    public function calculateRemainingQty(PurchaseOrderItem $purchaseOrderItem): float
    {
        return max(
            0,
            (float) $purchaseOrderItem->quantity_ordered - $this->calculatePreviouslyReceivedQty($purchaseOrderItem->id),
        );
    }

    public function ensureNotPosted(GoodsReceipt $goodsReceipt): void
    {
        if ($goodsReceipt->posted_at !== null || $goodsReceipt->status === GoodsReceipt::STATUS_POSTED) {
            throw new DomainException('Penerimaan barang sudah diposting.');
        }
    }

    public function ensureBranchMatches(GoodsReceipt $goodsReceipt, int $branchId): void
    {
        if ($goodsReceipt->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'goods_receipt' => 'Penerimaan barang tidak ditemukan di cabang aktif.',
            ]);
        }
    }

    public function generateReceiptNumber(int $branchId, Carbon|string $receiptDate): string
    {
        $datePart = $receiptDate instanceof Carbon
            ? $receiptDate->format('Ymd')
            : str_replace('-', '', $receiptDate);
        $prefix = sprintf('GR-%s-%d-', $datePart, $branchId);

        $latest = $this->goodsReceipts->latestNumberForDateAndBranch($prefix, $branchId);
        $sequence = $latest ? ((int) substr($latest, -6)) + 1 : 1;

        do {
            $candidate = sprintf('%s%06d', $prefix, $sequence);
            $sequence++;
        } while ($this->goodsReceipts->existsNumber($candidate));

        return $candidate;
    }

    /**
     * @return array{unit_cost: float, line_total: float}
     */
    public function snapshotCostFromPurchaseOrderItem(PurchaseOrderItem $item, float $acceptedQty): array
    {
        $unitCost = (float) ($item->unit_price ?? 0);

        return [
            'unit_cost' => $unitCost,
            'line_total' => round($acceptedQty * $unitCost, 2),
        ];
    }

    public function updatePurchaseOrderReceivingStatus(PurchaseOrder $purchaseOrder): void
    {
        $purchaseOrder->load('items');

        $allFullyReceived = true;
        $anyReceived = false;

        foreach ($purchaseOrder->items as $item) {
            $received = (float) ($item->quantity_received ?? 0);
            $ordered = (float) $item->quantity_ordered;

            if ($received > 0) {
                $anyReceived = true;
            }

            if ($received < $ordered) {
                $allFullyReceived = false;
            }
        }

        if ($allFullyReceived && $purchaseOrder->items->isNotEmpty()) {
            $this->purchaseOrders->update($purchaseOrder, [
                'status' => PurchaseOrder::STATUS_FULLY_RECEIVED,
            ]);

            return;
        }

        if ($anyReceived) {
            $this->purchaseOrders->update($purchaseOrder, [
                'status' => PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ]);
        }
    }

    /**
     * @param  array<int, array{purchase_order_item_id: int, product_id: int, inventory_location_id: int, accepted_qty: float, rejected_qty?: float, notes?: string|null}>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAndValidateItems(
        int $branchId,
        PurchaseOrder $purchaseOrder,
        array $items,
        mixed $receiptDate = null,
    ): array {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'Minimal satu item diperlukan.',
            ]);
        }

        $purchaseOrder->loadMissing('items');
        $poItems = $purchaseOrder->items->keyBy('id');
        $seenPoItemIds = [];
        $normalized = [];

        foreach ($items as $index => $item) {
            $poItemId = (int) $item['purchase_order_item_id'];

            if (isset($seenPoItemIds[$poItemId])) {
                throw ValidationException::withMessages([
                    "items.{$index}.purchase_order_item_id" => 'Item pesanan pembelian duplikat tidak diperbolehkan.',
                ]);
            }

            $seenPoItemIds[$poItemId] = true;

            /** @var PurchaseOrderItem|null $poItem */
            $poItem = $poItems->get($poItemId);

            if ($poItem === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.purchase_order_item_id" => 'Item pesanan pembelian tidak valid untuk dokumen terkait.',
                ]);
            }

            if ((int) $item['product_id'] !== (int) $poItem->product_id) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'Produk tidak sesuai dengan item pesanan pembelian.',
                ]);
            }

            $acceptedQty = (float) ($item['accepted_qty'] ?? 0);
            $rejectedQty = (float) ($item['rejected_qty'] ?? 0);

            if ($acceptedQty < 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.accepted_qty" => 'Jumlah diterima baik harus nol atau lebih.',
                ]);
            }

            if ($rejectedQty < 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.rejected_qty" => 'Jumlah ditolak harus nol atau lebih.',
                ]);
            }

            $locationId = (int) $item['inventory_location_id'];
            $product = $this->assertActiveProductInBranch($branchId, (int) $item['product_id'], $index);
            $this->assertActiveLocationInBranch($branchId, $locationId, $index);
            $this->assertBatchInputForItem($product, $item, $receiptDate, $index);

            $previouslyReceived = $this->calculatePreviouslyReceivedQty($poItemId);

            if ($previouslyReceived + $acceptedQty > (float) $poItem->quantity_ordered) {
                throw ValidationException::withMessages([
                    "items.{$index}.accepted_qty" => 'Jumlah diterima baik melebihi sisa pesanan untuk item ini.',
                ]);
            }

            $unitCost = (float) ($poItem->unit_price ?? 0);
            $batchFields = $this->extractPersistedBatchFields($item, $receiptDate);

            $normalized[] = array_merge([
                'purchase_order_item_id' => $poItemId,
                'product_id' => (int) $item['product_id'],
                'inventory_location_id' => $locationId,
                'ordered_qty' => (float) $poItem->quantity_ordered,
                'previously_received_qty' => $previouslyReceived,
                'accepted_qty' => $acceptedQty,
                'rejected_qty' => $rejectedQty,
                'received_qty' => $acceptedQty + $rejectedQty,
                'unit_cost' => $unitCost,
                'line_total' => round($acceptedQty * $unitCost, 2),
                'notes' => $item['notes'] ?? null,
            ], $batchFields);
        }

        return $normalized;
    }

    private function lockPurchaseOrderInBranch(int $branchId, int $purchaseOrderId): ?PurchaseOrder
    {
        return PurchaseOrder::query()
            ->with('items')
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->find($purchaseOrderId);
    }

    private function assertHasPostableQuantity(GoodsReceipt $goodsReceipt): void
    {
        $goodsReceipt->loadMissing('items');

        $hasAccepted = $goodsReceipt->items->contains(
            fn (GoodsReceiptItem $item) => (float) $item->accepted_qty > 0,
        );

        if (! $hasAccepted) {
            throw ValidationException::withMessages([
                'items' => 'Minimal satu item harus memiliki diterima baik lebih dari nol.',
            ]);
        }
    }

    /**
     * @param  string|array<int, string>  $allowedStatuses
     */
    private function assertStatus(GoodsReceipt $goodsReceipt, string|array $allowedStatuses): void
    {
        $allowed = (array) $allowedStatuses;

        if (! in_array($goodsReceipt->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'Status penerimaan barang tidak valid untuk operasi ini.',
            ]);
        }
    }

    private function assertActiveProductInBranch(int $branchId, int $productId, int $index): Product
    {
        $product = $this->products->findInBranch($branchId, $productId);

        if ($product === null) {
            throw ValidationException::withMessages([
                "items.{$index}.product_id" => 'Produk tidak ditemukan di cabang aktif.',
            ]);
        }

        if (! $product->is_active) {
            throw ValidationException::withMessages([
                "items.{$index}.product_id" => 'Produk tidak aktif.',
            ]);
        }

        return $product;
    }

    private function assertActiveLocationInBranch(int $branchId, int $locationId, int $index): InventoryLocation
    {
        $location = $this->locations->findInBranch($branchId, $locationId);

        if ($location === null) {
            throw ValidationException::withMessages([
                "items.{$index}.inventory_location_id" => 'Lokasi persediaan wajib dipilih dan harus aktif.',
            ]);
        }

        if (! $location->is_active) {
            throw ValidationException::withMessages([
                "items.{$index}.inventory_location_id" => 'Lokasi persediaan tidak aktif.',
            ]);
        }

        return $location;
    }

    private function loadDetails(GoodsReceipt $goodsReceipt): GoodsReceipt
    {
        return $this->goodsReceipts->findForBranch($goodsReceipt->branch_id, $goodsReceipt->id)
            ?? $goodsReceipt->load([
                'purchaseOrder.supplier',
                'items.product',
                'items.inventoryLocation',
                'items.inventoryBatch',
                'items.purchaseOrderItem',
                'createdBy',
            ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function assertBatchInputForItem(Product $product, array $item, mixed $receiptDate, int $index): void
    {
        $acceptedQty = (float) ($item['accepted_qty'] ?? 0);

        if (! $product->requires_batch_tracking || $acceptedQty <= 0) {
            return;
        }

        $branchId = $this->branchContext->requireId();
        $batchMode = $item['batch_mode'] ?? null;

        if ($batchMode === 'existing' || ($batchMode === null && filled($item['inventory_batch_id'] ?? null))) {
            if (! filled($item['inventory_batch_id'] ?? null)) {
                throw ValidationException::withMessages([
                    "items.{$index}.inventory_batch_id" => 'Pilih batch yang ada atau buat batch baru.',
                ]);
            }

            $this->assertExistingBatchForItem(
                $branchId,
                (int) $item['product_id'],
                (int) $item['inventory_batch_id'],
                $index,
            );

            return;
        }

        if (! filled($item['batch_number'] ?? null)) {
            throw ValidationException::withMessages([
                "items.{$index}.batch_number" => 'Nomor batch wajib diisi untuk produk dengan pelacakan batch.',
            ]);
        }

        $batchReceivedDate = $item['batch_received_date'] ?? $receiptDate;

        if (! filled($batchReceivedDate)) {
            throw ValidationException::withMessages([
                "items.{$index}.batch_received_date" => 'Tanggal terima batch wajib diisi untuk produk dengan pelacakan batch.',
            ]);
        }

        if (filled($item['expiry_date'] ?? null) && $item['expiry_date'] < $batchReceivedDate) {
            throw ValidationException::withMessages([
                "items.{$index}.expiry_date" => 'Tanggal kedaluwarsa tidak boleh sebelum tanggal terima batch.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function extractPersistedBatchFields(array $item, mixed $receiptDate): array
    {
        $batchMode = $item['batch_mode'] ?? null;

        if ($batchMode === 'existing' || ($batchMode === null && filled($item['inventory_batch_id'] ?? null))) {
            return [
                'inventory_batch_id' => filled($item['inventory_batch_id'] ?? null)
                    ? (int) $item['inventory_batch_id']
                    : null,
                'batch_number' => null,
                'lot_number' => null,
                'batch_received_date' => null,
                'expiry_date' => null,
            ];
        }

        if (! filled($item['batch_number'] ?? null)) {
            return [
                'inventory_batch_id' => null,
                'batch_number' => null,
                'lot_number' => null,
                'batch_received_date' => null,
                'expiry_date' => null,
            ];
        }

        return [
            'inventory_batch_id' => null,
            'batch_number' => trim((string) $item['batch_number']),
            'lot_number' => filled($item['lot_number'] ?? null) ? trim((string) $item['lot_number']) : null,
            'batch_received_date' => $item['batch_received_date'] ?? $receiptDate,
            'expiry_date' => $item['expiry_date'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildBatchDataForPost(GoodsReceiptItem $item, Product $product, string $receiptDate): ?array
    {
        if (! $product->requires_batch_tracking) {
            return null;
        }

        if ($item->inventory_batch_id !== null) {
            return ['inventory_batch_id' => (int) $item->inventory_batch_id];
        }

        if (! filled($item->batch_number)) {
            throw ValidationException::withMessages([
                'items' => 'Nomor batch wajib diisi untuk produk dengan pelacakan batch.',
            ]);
        }

        return [
            'batch_number' => $item->batch_number,
            'lot_number' => $item->lot_number,
            'received_date' => $item->batch_received_date?->toDateString() ?? $receiptDate,
            'expiry_date' => $item->expiry_date?->toDateString(),
        ];
    }

    private function assertExistingBatchForItem(int $branchId, int $productId, int $batchId, int $index): void
    {
        $batch = InventoryBatch::query()
            ->where('branch_id', $branchId)
            ->whereKey($batchId)
            ->first();

        if ($batch === null || ! $batch->is_active) {
            throw ValidationException::withMessages([
                "items.{$index}.inventory_batch_id" => 'Batch tidak valid untuk cabang aktif.',
            ]);
        }

        if ($batch->product_id !== $productId) {
            throw ValidationException::withMessages([
                "items.{$index}.inventory_batch_id" => 'Batch tidak sesuai dengan produk pada baris ini.',
            ]);
        }
    }
}
