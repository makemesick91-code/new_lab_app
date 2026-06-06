<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\PurchaseOrderRepositoryInterface;
use App\Modules\Inventory\Interfaces\PurchaseRequestRepositoryInterface;
use App\Modules\Inventory\Interfaces\SupplierRepositoryInterface;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\PurchaseRequestItem;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(
        private readonly PurchaseOrderRepositoryInterface $purchaseOrders,
        private readonly PurchaseRequestRepositoryInterface $purchaseRequests,
        private readonly ProductRepositoryInterface $products,
        private readonly InventoryLocationRepositoryInterface $locations,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly BranchContext $branchContext,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForBranch(int $branchId, array $filters = []): LengthAwarePaginator
    {
        return $this->purchaseOrders->paginateForBranch($branchId, $filters);
    }

    public function findEligiblePurchaseRequestForCreate(int $purchaseRequestId): PurchaseRequest
    {
        $branchId = $this->branchContext->requireId();
        $purchaseRequest = $this->purchaseRequests->findById($branchId, $purchaseRequestId);

        if ($purchaseRequest === null) {
            throw ValidationException::withMessages([
                'purchase_request_id' => 'Permintaan pembelian tidak ditemukan di cabang aktif.',
            ]);
        }

        $this->assertPurchaseRequestEligible($purchaseRequest, $branchId);

        return $this->purchaseRequests->loadDetails($purchaseRequest);
    }

    /**
     * @return array<int, array{product_id: int, inventory_location_id?: int|null, purchase_request_item_id?: int|null, quantity_ordered: float, unit_price?: float|null, notes?: string|null}>
     */
    public function buildPrefillItemsFromPurchaseRequest(PurchaseRequest $purchaseRequest): array
    {
        return $this->resolveItemsFromPurchaseRequest($purchaseRequest, null);
    }

    /**
     * @param  array{order_date: string, supplier_id?: int|null, supplier_reference_number?: string|null, currency?: string|null, expected_delivery_date?: string|null, notes?: string|null, items: array<int, array{product_id: int, inventory_location_id?: int|null, purchase_request_item_id?: int|null, quantity_ordered: float, unit_price?: float|null, notes?: string|null}>}  $data
     */
    public function createDraft(array $data, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $user) {
            $branchId = $this->branchContext->requireId();
            $normalizedItems = $this->normalizeAndValidateItems($branchId, $data['items']);

            $supplierId = isset($data['supplier_id']) && $data['supplier_id'] !== '' && $data['supplier_id'] !== null
                ? (int) $data['supplier_id']
                : null;
            $supplier = $this->assertSupplierInBranch($branchId, $supplierId);

            $purchaseOrder = $this->purchaseOrders->create([
                'branch_id' => $branchId,
                'purchase_order_number' => $this->generatePurchaseOrderNumber($branchId, $data['order_date']),
                'order_date' => $data['order_date'],
                'status' => PurchaseOrder::STATUS_DRAFT,
                'supplier_id' => $supplierId,
                'supplier_snapshot_name' => $supplier?->name,
                'supplier_reference_number' => $data['supplier_reference_number'] ?? null,
                'currency' => $data['currency'] ?? 'IDR',
                'purchase_request_id' => null,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->purchaseOrders->replaceItems($purchaseOrder, $normalizedItems);

            return $this->loadDetails($purchaseOrder->refresh());
        });
    }

    /**
     * @param  array{supplier_id: int, supplier_reference_number?: string|null, currency?: string|null, expected_delivery_date?: string|null, notes?: string|null, items?: array<int, array{product_id: int, inventory_location_id?: int|null, purchase_request_item_id?: int|null, quantity_ordered: float, unit_price?: float|null, notes?: string|null}>}  $data
     */
    public function createDraftFromPurchaseRequest(PurchaseRequest $purchaseRequest, array $data, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseRequest, $data, $user) {
            $branchId = $this->branchContext->requireId();
            $this->assertPurchaseRequestEligible($purchaseRequest, $branchId);
            $this->assertNoActivePurchaseOrderForPurchaseRequest($purchaseRequest);

            $supplierId = isset($data['supplier_id']) && $data['supplier_id'] !== '' && $data['supplier_id'] !== null
                ? (int) $data['supplier_id']
                : null;

            if ($supplierId === null) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'Supplier wajib dipilih untuk pesanan pembelian.',
                ]);
            }

            $supplier = $this->assertSupplierInBranch($branchId, $supplierId);
            $items = $this->resolveItemsFromPurchaseRequest($purchaseRequest, $data['items'] ?? null);
            $normalizedItems = $this->normalizeAndValidateItems($branchId, $items, $purchaseRequest);

            $purchaseOrder = $this->purchaseOrders->create([
                'branch_id' => $branchId,
                'purchase_order_number' => $this->generatePurchaseOrderNumber($branchId, $data['order_date'] ?? now()->toDateString()),
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'status' => PurchaseOrder::STATUS_DRAFT,
                'supplier_id' => $supplierId,
                'supplier_snapshot_name' => $supplier->name,
                'supplier_reference_number' => $data['supplier_reference_number'] ?? null,
                'currency' => $data['currency'] ?? 'IDR',
                'purchase_request_id' => $purchaseRequest->id,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->purchaseOrders->replaceItems($purchaseOrder, $normalizedItems);

            return $this->loadDetails($purchaseOrder->refresh());
        });
    }

    /**
     * @param  array{order_date?: string, supplier_id?: int|null, supplier_reference_number?: string|null, currency?: string|null, expected_delivery_date?: string|null, notes?: string|null, items: array<int, array{product_id: int, inventory_location_id?: int|null, purchase_request_item_id?: int|null, quantity_ordered: float, unit_price?: float|null, notes?: string|null}>}  $data
     */
    public function updateDraft(PurchaseOrder $purchaseOrder, array $data, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $data) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->lockPurchaseOrderInBranch($branchId, $purchaseOrder->id);

            $this->assertStatus($locked, PurchaseOrder::STATUS_DRAFT);

            $purchaseRequest = $locked->purchase_request_id !== null
                ? $this->purchaseRequests->findById($branchId, (int) $locked->purchase_request_id)
                : null;
            $normalizedItems = $this->normalizeAndValidateItems($branchId, $data['items'], $purchaseRequest);

            $updateData = [
                'order_date' => $data['order_date'] ?? $locked->order_date->toDateString(),
                'supplier_reference_number' => $data['supplier_reference_number'] ?? $locked->supplier_reference_number,
                'currency' => $data['currency'] ?? $locked->currency,
                'expected_delivery_date' => array_key_exists('expected_delivery_date', $data)
                    ? $data['expected_delivery_date']
                    : $locked->expected_delivery_date?->toDateString(),
                'notes' => $data['notes'] ?? $locked->notes,
            ];

            if (array_key_exists('supplier_id', $data)) {
                $newSupplierId = $data['supplier_id'] !== null && $data['supplier_id'] !== ''
                    ? (int) $data['supplier_id']
                    : null;

                if ($newSupplierId !== $locked->supplier_id) {
                    $supplier = $this->assertSupplierInBranch($branchId, $newSupplierId);
                    $updateData['supplier_id'] = $newSupplierId;
                    $updateData['supplier_snapshot_name'] = $supplier?->name;
                }
            }

            $updated = $this->purchaseOrders->update($locked, $updateData);
            $this->purchaseOrders->replaceItems($updated, $normalizedItems);

            return $this->loadDetails($updated->refresh());
        });
    }

    public function submit(PurchaseOrder $purchaseOrder, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $user) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->lockPurchaseOrderInBranch($branchId, $purchaseOrder->id);

            $this->assertStatus($locked, PurchaseOrder::STATUS_DRAFT);
            $this->assertPurchaseOrderReadyForSubmit($branchId, $locked);

            return $this->purchaseOrders->update($locked, [
                'status' => PurchaseOrder::STATUS_SUBMITTED,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
            ]);
        });
    }

    public function approve(PurchaseOrder $purchaseOrder, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $user) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->lockPurchaseOrderInBranch($branchId, $purchaseOrder->id);

            $this->assertStatus($locked, PurchaseOrder::STATUS_SUBMITTED);
            $this->assertActiveSupplierInBranch($branchId, (int) $locked->supplier_id);

            return $this->purchaseOrders->update($locked, [
                'status' => PurchaseOrder::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);
        });
    }

    public function markAsSent(PurchaseOrder $purchaseOrder, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $user) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->lockPurchaseOrderInBranch($branchId, $purchaseOrder->id);

            $this->assertStatus($locked, PurchaseOrder::STATUS_APPROVED);
            $this->assertActiveSupplierInBranch($branchId, (int) $locked->supplier_id);

            return $this->purchaseOrders->update($locked, [
                'status' => PurchaseOrder::STATUS_SENT,
                'sent_by' => $user->id,
                'sent_at' => now(),
            ]);
        });
    }

    public function cancel(PurchaseOrder $purchaseOrder, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->lockPurchaseOrderInBranch($branchId, $purchaseOrder->id);

            $this->assertStatus($locked, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SUBMITTED]);

            return $this->purchaseOrders->update($locked, [
                'status' => PurchaseOrder::STATUS_CANCELLED,
            ]);
        });
    }

    private function lockPurchaseOrderInBranch(int $branchId, int $purchaseOrderId): PurchaseOrder
    {
        $purchaseOrder = PurchaseOrder::query()
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->find($purchaseOrderId);

        if ($purchaseOrder === null) {
            throw ValidationException::withMessages([
                'purchase_order' => 'Pesanan pembelian tidak ditemukan di cabang aktif.',
            ]);
        }

        return $purchaseOrder;
    }

    private function generatePurchaseOrderNumber(int $branchId, Carbon|string $orderDate): string
    {
        $datePart = $orderDate instanceof Carbon
            ? $orderDate->format('Ymd')
            : str_replace('-', '', $orderDate);
        $prefix = sprintf('PO-%s-%d-', $datePart, $branchId);

        $latest = $this->purchaseOrders->latestNumberForDateAndBranch($prefix, $branchId);
        $sequence = $latest ? ((int) substr($latest, -6)) + 1 : 1;

        do {
            $candidate = sprintf('%s%06d', $prefix, $sequence);
            $sequence++;
        } while ($this->purchaseOrders->existsNumber($candidate));

        return $candidate;
    }

    /**
     * @param  array<int, array{product_id: int, inventory_location_id?: int|null, purchase_request_item_id?: int|null, quantity_ordered: float, unit_price?: float|null, notes?: string|null}>  $items
     * @return array<int, array{product_id: int, inventory_location_id: int|null, purchase_request_item_id: int|null, quantity_ordered: float, unit_price: float|null, notes: string|null}>
     */
    private function normalizeAndValidateItems(int $branchId, array $items, ?PurchaseRequest $purchaseRequest = null): array
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'Minimal satu item diperlukan.',
            ]);
        }

        $purchaseRequestItems = $purchaseRequest !== null
            ? $purchaseRequest->items()->get()->keyBy('id')
            : collect();

        $normalized = [];

        foreach ($items as $index => $item) {
            $quantity = (float) ($item['quantity_ordered'] ?? 0);

            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity_ordered" => 'Jumlah pesanan harus lebih dari nol.',
                ]);
            }

            if (isset($item['unit_price']) && $item['unit_price'] !== '' && (float) $item['unit_price'] < 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_price" => 'Harga satuan tidak boleh negatif.',
                ]);
            }

            $product = $this->assertActiveProductInBranch($branchId, (int) $item['product_id'], $index);

            $locationId = isset($item['inventory_location_id']) && $item['inventory_location_id'] !== ''
                ? (int) $item['inventory_location_id']
                : null;

            if ($locationId !== null) {
                $this->assertActiveLocationInBranch($branchId, $locationId, $index);
            }

            $purchaseRequestItemId = isset($item['purchase_request_item_id']) && $item['purchase_request_item_id'] !== ''
                ? (int) $item['purchase_request_item_id']
                : null;

            if ($purchaseRequest !== null && $purchaseRequestItemId !== null) {
                /** @var PurchaseRequestItem|null $purchaseRequestItem */
                $purchaseRequestItem = $purchaseRequestItems->get($purchaseRequestItemId);

                if ($purchaseRequestItem === null) {
                    throw ValidationException::withMessages([
                        "items.{$index}.purchase_request_item_id" => 'Item permintaan pembelian tidak valid untuk dokumen terkait.',
                    ]);
                }

                if ($quantity > (float) $purchaseRequestItem->quantity_requested) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity_ordered" => 'Jumlah pesanan tidak boleh melebihi jumlah yang diminta pada permintaan pembelian.',
                    ]);
                }
            }

            $unitPrice = isset($item['unit_price']) && $item['unit_price'] !== ''
                ? (float) $item['unit_price']
                : null;

            $normalized[] = [
                'product_id' => $product->id,
                'inventory_location_id' => $locationId,
                'purchase_request_item_id' => $purchaseRequestItemId,
                'quantity_ordered' => $quantity,
                'unit_price' => $unitPrice,
                'notes' => $item['notes'] ?? null,
            ];
        }

        return $normalized;
    }

    private function assertSupplierInBranch(int $branchId, ?int $supplierId, bool $required = false): ?Supplier
    {
        if ($supplierId === null) {
            if ($required) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'Supplier wajib dipilih.',
                ]);
            }

            return null;
        }

        $supplier = $this->suppliers->findInBranch($branchId, $supplierId);

        if ($supplier === null) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Supplier tidak ditemukan di cabang aktif.',
            ]);
        }

        return $supplier;
    }

    private function assertActiveSupplierInBranch(int $branchId, int $supplierId): Supplier
    {
        $supplier = $this->assertSupplierInBranch($branchId, $supplierId, true);

        if (! $supplier->is_active) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Supplier tidak aktif.',
            ]);
        }

        return $supplier;
    }

    private function assertPurchaseRequestEligible(PurchaseRequest $purchaseRequest, int $branchId): void
    {
        if ($purchaseRequest->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'purchase_request' => 'Permintaan pembelian tidak ditemukan di cabang aktif.',
            ]);
        }

        if (! $purchaseRequest->isApproved()) {
            throw ValidationException::withMessages([
                'purchase_request' => 'Pesanan pembelian hanya bisa dibuat dari permintaan pembelian yang sudah disetujui.',
            ]);
        }
    }

    private function assertNoActivePurchaseOrderForPurchaseRequest(PurchaseRequest $purchaseRequest): void
    {
        $hasActive = PurchaseOrder::query()
            ->where('purchase_request_id', $purchaseRequest->id)
            ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->exists();

        if ($hasActive) {
            throw ValidationException::withMessages([
                'purchase_request' => 'Permintaan pembelian ini sudah memiliki pesanan pembelian aktif.',
            ]);
        }
    }

    /**
     * @param  string|array<int, string>  $allowedStatuses
     */
    private function assertStatus(PurchaseOrder $purchaseOrder, string|array $allowedStatuses): void
    {
        $allowed = (array) $allowedStatuses;

        if (! in_array($purchaseOrder->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'Status pesanan pembelian tidak valid untuk operasi ini.',
            ]);
        }
    }

    private function assertPurchaseOrderReadyForSubmit(int $branchId, PurchaseOrder $purchaseOrder): void
    {
        $purchaseOrder->load('items');

        if ($purchaseOrder->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Pesanan pembelian harus memiliki minimal satu item sebelum diajukan.',
            ]);
        }

        if ($purchaseOrder->supplier_id === null) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Supplier wajib dipilih sebelum pesanan pembelian diajukan.',
            ]);
        }

        $this->assertActiveSupplierInBranch($branchId, (int) $purchaseOrder->supplier_id);

        foreach ($purchaseOrder->items as $index => $item) {
            $this->assertActiveProductInBranch($branchId, (int) $item->product_id, $index);

            if ($item->inventory_location_id !== null) {
                $this->assertActiveLocationInBranch($branchId, (int) $item->inventory_location_id, $index);
            }
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
                "items.{$index}.inventory_location_id" => 'Lokasi persediaan tidak ditemukan di cabang aktif.',
            ]);
        }

        if (! $location->is_active) {
            throw ValidationException::withMessages([
                "items.{$index}.inventory_location_id" => 'Lokasi persediaan tidak aktif.',
            ]);
        }

        return $location;
    }

    /**
     * @param  array<int, array{product_id: int, inventory_location_id?: int|null, purchase_request_item_id?: int|null, quantity_ordered: float, unit_price?: float|null, notes?: string|null}>|null  $items
     * @return array<int, array{product_id: int, inventory_location_id?: int|null, purchase_request_item_id?: int|null, quantity_ordered: float, unit_price?: float|null, notes?: string|null}>
     */
    private function resolveItemsFromPurchaseRequest(PurchaseRequest $purchaseRequest, ?array $items): array
    {
        if ($items !== null && $items !== []) {
            return $items;
        }

        $purchaseRequest->load('items');

        return $purchaseRequest->items
            ->map(fn (PurchaseRequestItem $purchaseRequestItem) => [
                'product_id' => $purchaseRequestItem->product_id,
                'inventory_location_id' => $purchaseRequestItem->inventory_location_id,
                'purchase_request_item_id' => $purchaseRequestItem->id,
                'quantity_ordered' => (float) $purchaseRequestItem->quantity_requested,
                'unit_price' => $purchaseRequestItem->estimated_unit_price !== null
                    ? (float) $purchaseRequestItem->estimated_unit_price
                    : null,
                'notes' => $purchaseRequestItem->notes,
            ])
            ->values()
            ->all();
    }

    private function loadDetails(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return $this->purchaseOrders->findForBranch($purchaseOrder->branch_id, $purchaseOrder->id)
            ?? $purchaseOrder->load([
                'supplier',
                'purchaseRequest',
                'submittedBy',
                'approvedBy',
                'sentBy',
                'createdBy',
                'items.product',
                'items.inventoryLocation',
                'items.purchaseRequestItem',
            ]);
    }
}
