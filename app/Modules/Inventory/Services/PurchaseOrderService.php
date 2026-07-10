<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Enums\InventoryActivityAction;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\PurchaseOrderRepositoryInterface;
use App\Modules\Inventory\Interfaces\PurchaseRequestRepositoryInterface;
use App\Modules\Inventory\Interfaces\SupplierRepositoryInterface;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\PurchaseRequestItem;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\Concerns\LogsInventoryActivity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    use LogsInventoryActivity;

    public function __construct(
        private readonly PurchaseOrderRepositoryInterface $purchaseOrders,
        private readonly PurchaseRequestRepositoryInterface $purchaseRequests,
        private readonly ProductRepositoryInterface $products,
        private readonly InventoryLocationRepositoryInterface $locations,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly BranchContext $branchContext,
        private readonly InventoryActivityLogService $activityLogger,
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
        $result = DB::transaction(function () use ($data, $user) {
            $branchId = $this->branchContext->requireId();

            // Compatibility layer: a header supplier_id (legacy single-vendor
            // callers) defaults any item that omits its own supplier; the form
            // always sends per-item supplier + arrival, which take precedence.
            $defaultSupplierId = isset($data['supplier_id']) && $data['supplier_id'] !== '' && $data['supplier_id'] !== null
                ? (int) $data['supplier_id']
                : null;
            $items = $this->applyItemDefaults($data['items'], $defaultSupplierId, $data['order_date']);

            $normalizedItems = $this->normalizeAndValidateItems($branchId, $items, $data['order_date']);
            $headerSupplier = $this->deriveHeaderSupplier($normalizedItems);

            $purchaseOrder = $this->purchaseOrders->create([
                'branch_id' => $branchId,
                'purchase_order_number' => $this->generatePurchaseOrderNumber($branchId, $data['order_date']),
                'order_date' => $data['order_date'],
                'status' => PurchaseOrder::STATUS_DRAFT,
                'supplier_id' => $headerSupplier['supplier_id'],
                'supplier_snapshot_name' => $headerSupplier['supplier_snapshot_name'],
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

        $this->logPurchaseOrderActivity(InventoryActivityAction::PURCHASE_ORDER_CREATED, $result, null, $user);

        return $result;
    }

    /**
     * @param  array{supplier_id: int, supplier_reference_number?: string|null, currency?: string|null, expected_delivery_date?: string|null, notes?: string|null, items?: array<int, array{product_id: int, inventory_location_id?: int|null, purchase_request_item_id?: int|null, quantity_ordered: float, unit_price?: float|null, notes?: string|null}>}  $data
     */
    public function createDraftFromPurchaseRequest(PurchaseRequest $purchaseRequest, array $data, User $user): PurchaseOrder
    {
        $result = DB::transaction(function () use ($purchaseRequest, $data, $user) {
            $branchId = $this->branchContext->requireId();
            $this->assertPurchaseRequestEligible($purchaseRequest, $branchId);
            $this->assertNoActivePurchaseOrderForPurchaseRequest($purchaseRequest);

            $orderDate = $data['order_date'] ?? now()->toDateString();
            $items = $this->resolveItemsFromPurchaseRequest($purchaseRequest, $data['items'] ?? null);

            // PR → PO convenience: purchase requests carry no supplier and no
            // per-item arrival date, so a header supplier_id (single-vendor PO)
            // and the order date are applied as defaults to any item that does
            // not already declare its own. The multi-vendor form always sends
            // per-item supplier + arrival, which take precedence over these.
            $defaultSupplierId = isset($data['supplier_id']) && $data['supplier_id'] !== '' && $data['supplier_id'] !== null
                ? (int) $data['supplier_id']
                : null;
            $items = $this->applyItemDefaults($items, $defaultSupplierId, $orderDate);

            $normalizedItems = $this->normalizeAndValidateItems($branchId, $items, $orderDate, $purchaseRequest);
            $headerSupplier = $this->deriveHeaderSupplier($normalizedItems);

            $purchaseOrder = $this->purchaseOrders->create([
                'branch_id' => $branchId,
                'purchase_order_number' => $this->generatePurchaseOrderNumber($branchId, $orderDate),
                'order_date' => $orderDate,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'supplier_id' => $headerSupplier['supplier_id'],
                'supplier_snapshot_name' => $headerSupplier['supplier_snapshot_name'],
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

        $this->logPurchaseOrderActivity(InventoryActivityAction::PURCHASE_ORDER_CREATED, $result, null, $user);

        return $result;
    }

    /**
     * @param  array{order_date?: string, supplier_id?: int|null, supplier_reference_number?: string|null, currency?: string|null, expected_delivery_date?: string|null, notes?: string|null, items: array<int, array{product_id: int, inventory_location_id?: int|null, purchase_request_item_id?: int|null, quantity_ordered: float, unit_price?: float|null, notes?: string|null}>}  $data
     */
    public function updateDraft(PurchaseOrder $purchaseOrder, array $data, User $user): PurchaseOrder
    {
        $statusFrom = $purchaseOrder->status;

        $result = DB::transaction(function () use ($purchaseOrder, $data) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->lockPurchaseOrderInBranch($branchId, $purchaseOrder->id);

            $this->assertStatus($locked, PurchaseOrder::STATUS_DRAFT);

            $orderDate = $data['order_date'] ?? $locked->order_date->toDateString();

            $purchaseRequest = $locked->purchase_request_id !== null
                ? $this->purchaseRequests->findById($branchId, (int) $locked->purchase_request_id)
                : null;

            $defaultSupplierId = isset($data['supplier_id']) && $data['supplier_id'] !== '' && $data['supplier_id'] !== null
                ? (int) $data['supplier_id']
                : null;
            $items = $this->applyItemDefaults($data['items'], $defaultSupplierId, $orderDate);

            $normalizedItems = $this->normalizeAndValidateItems($branchId, $items, $orderDate, $purchaseRequest);
            $headerSupplier = $this->deriveHeaderSupplier($normalizedItems);

            $updateData = [
                'order_date' => $orderDate,
                'supplier_reference_number' => $data['supplier_reference_number'] ?? $locked->supplier_reference_number,
                'currency' => $data['currency'] ?? $locked->currency,
                'expected_delivery_date' => array_key_exists('expected_delivery_date', $data)
                    ? $data['expected_delivery_date']
                    : $locked->expected_delivery_date?->toDateString(),
                'notes' => $data['notes'] ?? $locked->notes,
                // Deprecated header supplier snapshot re-derived from canonical item suppliers.
                'supplier_id' => $headerSupplier['supplier_id'],
                'supplier_snapshot_name' => $headerSupplier['supplier_snapshot_name'],
            ];

            $updated = $this->purchaseOrders->update($locked, $updateData);
            $this->purchaseOrders->replaceItems($updated, $normalizedItems);

            return $this->loadDetails($updated->refresh());
        });

        $this->logPurchaseOrderActivity(InventoryActivityAction::PURCHASE_ORDER_UPDATED, $result, $statusFrom, $user);

        return $result;
    }

    public function submit(PurchaseOrder $purchaseOrder, User $user): PurchaseOrder
    {
        $statusFrom = $purchaseOrder->status;

        $result = DB::transaction(function () use ($purchaseOrder, $user) {
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

        $this->logPurchaseOrderActivity(InventoryActivityAction::PURCHASE_ORDER_SUBMITTED, $result, $statusFrom, $user);

        return $result;
    }

    public function approve(PurchaseOrder $purchaseOrder, User $user): PurchaseOrder
    {
        $statusFrom = $purchaseOrder->status;

        $result = DB::transaction(function () use ($purchaseOrder, $user) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->lockPurchaseOrderInBranch($branchId, $purchaseOrder->id);

            $this->assertStatus($locked, PurchaseOrder::STATUS_SUBMITTED);
            $this->assertAllItemSuppliersActive($branchId, $locked);

            return $this->purchaseOrders->update($locked, [
                'status' => PurchaseOrder::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);
        });

        $this->logPurchaseOrderActivity(InventoryActivityAction::PURCHASE_ORDER_APPROVED, $result, $statusFrom, $user);

        return $result;
    }

    public function markAsSent(PurchaseOrder $purchaseOrder, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $user) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->lockPurchaseOrderInBranch($branchId, $purchaseOrder->id);

            $this->assertStatus($locked, PurchaseOrder::STATUS_APPROVED);
            $this->assertAllItemSuppliersActive($branchId, $locked);

            return $this->purchaseOrders->update($locked, [
                'status' => PurchaseOrder::STATUS_SENT,
                'sent_by' => $user->id,
                'sent_at' => now(),
            ]);
        });
    }

    public function cancel(PurchaseOrder $purchaseOrder, User $user): PurchaseOrder
    {
        $statusFrom = $purchaseOrder->status;

        $result = DB::transaction(function () use ($purchaseOrder) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->lockPurchaseOrderInBranch($branchId, $purchaseOrder->id);

            $this->assertStatus($locked, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SUBMITTED]);

            return $this->purchaseOrders->update($locked, [
                'status' => PurchaseOrder::STATUS_CANCELLED,
            ]);
        });

        $this->logPurchaseOrderActivity(InventoryActivityAction::PURCHASE_ORDER_CANCELLED, $result, $statusFrom, $user);

        return $result;
    }

    private function logPurchaseOrderActivity(string $action, PurchaseOrder $purchaseOrder, ?string $statusFrom, User $user): void
    {
        $purchaseOrder->loadMissing('items');

        $supplierIds = $purchaseOrder->items
            ->pluck('supplier_id')
            ->filter(fn ($id): bool => $id !== null)
            ->unique()
            ->values();

        $metadata = [
            'document_number' => $purchaseOrder->purchase_order_number,
            'branch_id' => $purchaseOrder->branch_id,
            'status_to' => $purchaseOrder->status,
            'item_count' => $purchaseOrder->items->count(),
            'supplier_count' => $supplierIds->count(),
        ];

        if ($statusFrom !== null) {
            $metadata['status_from'] = $statusFrom;
        }

        if ($purchaseOrder->supplier_id !== null) {
            $metadata['supplier_id'] = $purchaseOrder->supplier_id;
        }

        if ($purchaseOrder->purchase_request_id !== null) {
            $metadata['purchase_request_id'] = $purchaseOrder->purchase_request_id;
        }

        $this->logActivity($action, $purchaseOrder, $metadata, null, $user);
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
     * @param  array<int, array{product_id: int, supplier_id?: int|null, inventory_location_id?: int|null, purchase_request_item_id?: int|null, quantity_ordered: float, unit_price?: float|null, estimated_arrival_date?: string|null, notes?: string|null}>  $items
     * @return array<int, array{product_id: int, supplier_id: int, inventory_location_id: int|null, purchase_request_item_id: int|null, quantity_ordered: float, unit_price: float|null, estimated_arrival_date: string, notes: string|null}>
     */
    private function normalizeAndValidateItems(int $branchId, array $items, Carbon|string $orderDate, ?PurchaseRequest $purchaseRequest = null): array
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'Minimal satu item diperlukan.',
            ]);
        }

        $orderDateValue = $orderDate instanceof Carbon
            ? $orderDate->copy()->startOfDay()
            : Carbon::parse($orderDate)->startOfDay();

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

            $supplierId = isset($item['supplier_id']) && $item['supplier_id'] !== '' && $item['supplier_id'] !== null
                ? (int) $item['supplier_id']
                : null;

            if ($supplierId === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.supplier_id" => 'Supplier wajib dipilih untuk setiap item.',
                ]);
            }

            $this->assertActiveSupplierInBranchForItem($branchId, $supplierId, $index);

            $estimatedArrival = $this->resolveEstimatedArrivalDate($item['estimated_arrival_date'] ?? null, $orderDateValue, $index);

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
                'supplier_id' => $supplierId,
                'inventory_location_id' => $locationId,
                'purchase_request_item_id' => $purchaseRequestItemId,
                'quantity_ordered' => $quantity,
                'unit_price' => $unitPrice,
                'estimated_arrival_date' => $estimatedArrival,
                'notes' => $item['notes'] ?? null,
            ];
        }

        return $normalized;
    }

    private function resolveEstimatedArrivalDate(mixed $value, Carbon $orderDate, int $index): string
    {
        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                "items.{$index}.estimated_arrival_date" => 'Estimasi tanggal barang datang wajib diisi untuk setiap item.',
            ]);
        }

        try {
            $arrival = $value instanceof Carbon
                ? $value->copy()->startOfDay()
                : Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                "items.{$index}.estimated_arrival_date" => 'Estimasi tanggal barang datang tidak valid.',
            ]);
        }

        if ($arrival->lt($orderDate)) {
            throw ValidationException::withMessages([
                "items.{$index}.estimated_arrival_date" => 'Estimasi tanggal barang datang tidak boleh sebelum tanggal pesanan.',
            ]);
        }

        return $arrival->toDateString();
    }

    private function assertActiveSupplierInBranchForItem(int $branchId, int $supplierId, int $index): Supplier
    {
        $supplier = $this->suppliers->findInBranch($branchId, $supplierId);

        if ($supplier === null) {
            throw ValidationException::withMessages([
                "items.{$index}.supplier_id" => 'Supplier tidak ditemukan di cabang aktif.',
            ]);
        }

        if (! $supplier->is_active) {
            throw ValidationException::withMessages([
                "items.{$index}.supplier_id" => 'Supplier tidak aktif.',
            ]);
        }

        return $supplier;
    }

    /**
     * Derive the deprecated header supplier snapshot from the canonical item
     * suppliers: the sole distinct supplier for a single-vendor PO, or NULL for
     * a multi-vendor PO. Kept only for legacy search/report compatibility.
     *
     * @param  array<int, array{supplier_id: int}>  $normalizedItems
     * @return array{supplier_id: int|null, supplier_snapshot_name: string|null}
     */
    private function deriveHeaderSupplier(array $normalizedItems): array
    {
        $supplierIds = collect($normalizedItems)
            ->pluck('supplier_id')
            ->filter(fn ($id): bool => $id !== null)
            ->unique()
            ->values();

        if ($supplierIds->count() !== 1) {
            return ['supplier_id' => null, 'supplier_snapshot_name' => null];
        }

        $supplierId = (int) $supplierIds->first();
        $supplier = $this->suppliers->findInBranch($this->branchContext->requireId(), $supplierId);

        return [
            'supplier_id' => $supplierId,
            'supplier_snapshot_name' => $supplier?->name,
        ];
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

        foreach ($purchaseOrder->items as $index => $item) {
            $this->assertActiveProductInBranch($branchId, (int) $item->product_id, $index);

            if ($item->supplier_id === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.supplier_id" => 'Supplier wajib dipilih untuk setiap item sebelum pesanan pembelian diajukan.',
                ]);
            }

            $this->assertActiveSupplierInBranchForItem($branchId, (int) $item->supplier_id, $index);

            if ($item->inventory_location_id !== null) {
                $this->assertActiveLocationInBranch($branchId, (int) $item->inventory_location_id, $index);
            }
        }
    }

    /**
     * Every canonical item supplier must still exist, belong to the branch, and
     * be active before a PO advances to approved / sent.
     */
    private function assertAllItemSuppliersActive(int $branchId, PurchaseOrder $purchaseOrder): void
    {
        $purchaseOrder->loadMissing('items');

        if ($purchaseOrder->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Pesanan pembelian harus memiliki minimal satu item.',
            ]);
        }

        foreach ($purchaseOrder->items as $index => $item) {
            if ($item->supplier_id === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.supplier_id" => 'Supplier wajib dipilih untuk setiap item.',
                ]);
            }

            $this->assertActiveSupplierInBranchForItem($branchId, (int) $item->supplier_id, $index);
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
                // Supplier is chosen per item at PO time — the PR carries no supplier.
                'supplier_id' => null,
                'inventory_location_id' => $purchaseRequestItem->inventory_location_id,
                'purchase_request_item_id' => $purchaseRequestItem->id,
                'quantity_ordered' => (float) $purchaseRequestItem->quantity_requested,
                'unit_price' => $purchaseRequestItem->estimated_unit_price !== null
                    ? (float) $purchaseRequestItem->estimated_unit_price
                    : null,
                'estimated_arrival_date' => null,
                'notes' => $purchaseRequestItem->notes,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function applyItemDefaults(array $items, ?int $defaultSupplierId, Carbon|string $orderDate): array
    {
        $orderDateString = $orderDate instanceof Carbon ? $orderDate->toDateString() : (string) $orderDate;

        return array_map(function (array $item) use ($defaultSupplierId, $orderDateString): array {
            $hasSupplier = isset($item['supplier_id']) && $item['supplier_id'] !== '' && $item['supplier_id'] !== null;
            if (! $hasSupplier && $defaultSupplierId !== null) {
                $item['supplier_id'] = $defaultSupplierId;
            }

            $hasArrival = isset($item['estimated_arrival_date']) && $item['estimated_arrival_date'] !== '' && $item['estimated_arrival_date'] !== null;
            if (! $hasArrival) {
                $item['estimated_arrival_date'] = $orderDateString;
            }

            return $item;
        }, $items);
    }

    /**
     * Build the vendor-scoped dataset for a supplier's copy of a purchase order.
     *
     * Server-side supplier membership is the security boundary: only lines that
     * canonically belong to $supplier are returned, so the PDF can never leak
     * another supplier's items, prices, or existence.
     *
     * @return array{purchase_order: PurchaseOrder, supplier: Supplier, items: Collection<int, PurchaseOrderItem>, subtotal: float, item_count: int}
     */
    public function buildSupplierPdfData(PurchaseOrder $purchaseOrder, Supplier $supplier): array
    {
        $branchId = $this->branchContext->requireId();

        if ($purchaseOrder->branch_id !== $branchId || $supplier->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'supplier' => 'Supplier tidak ditemukan di cabang aktif.',
            ]);
        }

        $purchaseOrder->loadMissing('items.product', 'items.supplier', 'branch', 'createdBy', 'approvedBy');

        $items = $purchaseOrder->items
            ->filter(fn (PurchaseOrderItem $item): bool => (int) $item->supplier_id === (int) $supplier->id)
            ->values();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'supplier' => 'Supplier ini tidak memiliki item pada pesanan pembelian tersebut.',
            ]);
        }

        return [
            'purchase_order' => $purchaseOrder,
            'supplier' => $supplier,
            'items' => $items,
            'subtotal' => (float) $items->sum(fn (PurchaseOrderItem $item): float => $item->lineTotal()),
            'item_count' => $items->count(),
        ];
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
                'items.supplier',
                'items.inventoryLocation',
                'items.purchaseRequestItem',
            ]);
    }
}
