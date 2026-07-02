<?php

namespace App\Modules\Inventory\Requests\Concerns;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesGoodsReceiptInput
{
    use ValidatesGoodsReceiptBatchInput;

    /**
     * @var array<int, string>
     */
    private const RECEIVABLE_PO_STATUSES = [
        PurchaseOrder::STATUS_APPROVED,
        PurchaseOrder::STATUS_SENT,
        PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
    ];

    /**
     * @var array<int, string>
     */
    private const EXCLUDED_FIELDS = [
        'quantity_received',
        'inventory_movement_id',
        'reversal_movement_id',
        'posted_at',
        'posted_by',
        'voided_at',
        'voided_by',
        'cancellation_reason',
        'status',
        'unit_cost',
        'line_total',
        'ordered_qty',
        'previously_received_qty',
    ];

    protected function prepareForValidation(): void
    {
        $this->replace($this->stripExcludedFields($this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    protected function goodsReceiptStoreRules(): array
    {
        return array_merge($this->goodsReceiptHeaderRules(requirePurchaseOrder: true), $this->goodsReceiptItemRules());
    }

    /**
     * @return array<string, mixed>
     */
    protected function goodsReceiptUpdateRules(): array
    {
        return array_merge($this->goodsReceiptHeaderRules(requirePurchaseOrder: false), $this->goodsReceiptItemRules());
    }

    /**
     * @return array<string, mixed>
     */
    protected function goodsReceiptHeaderRules(bool $requirePurchaseOrder): array
    {
        $rules = [
            'receipt_date' => ['required', 'date', 'before_or_equal:today'],
            'supplier_delivery_number' => ['nullable', 'string', 'max:100'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
        ];

        if ($requirePurchaseOrder) {
            $rules['purchase_order_id'] = ['required', 'integer', 'exists:trx_purchase_orders,id'];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    protected function goodsReceiptItemRules(): array
    {
        return array_merge([
            'items.*.purchase_order_item_id' => ['required', 'integer', 'exists:trx_purchase_order_items,id'],
            'items.*.product_id' => ['required', 'integer', 'exists:inv_products,id'],
            'items.*.inventory_location_id' => ['required', 'integer', 'exists:inv_inventory_locations,id'],
            'items.*.received_qty' => ['required', 'numeric', 'min:0'],
            'items.*.accepted_qty' => ['required', 'numeric', 'min:0'],
            'items.*.rejected_qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ], $this->goodsReceiptBatchItemRules());
    }

    /**
     * @return array<string, string>
     */
    protected function goodsReceiptInputMessages(): array
    {
        return [
            'purchase_order_id.required' => 'Pesanan pembelian wajib dipilih.',
            'purchase_order_id.exists' => 'Pesanan pembelian tidak ditemukan atau tidak dapat diterima.',
            'receipt_date.required' => 'Tanggal penerimaan wajib diisi.',
            'receipt_date.date' => 'Tanggal penerimaan tidak valid.',
            'receipt_date.before_or_equal' => 'Tanggal penerimaan tidak boleh di masa depan.',
            'supplier_delivery_number.max' => 'Nomor surat jalan tidak boleh lebih dari 100 karakter.',
            'supplier_invoice_number.max' => 'Nomor faktur supplier tidak boleh lebih dari 100 karakter.',
            'notes.max' => 'Catatan tidak boleh lebih dari 2000 karakter.',
            'items.required' => 'Minimal satu item diperlukan.',
            'items.min' => 'Minimal satu item diperlukan.',
            'items.*.purchase_order_item_id.required' => 'Item pesanan pembelian wajib dipilih.',
            'items.*.purchase_order_item_id.exists' => 'Item pesanan pembelian tidak ditemukan.',
            'items.*.product_id.required' => 'Produk wajib dipilih.',
            'items.*.product_id.exists' => 'Produk tidak ditemukan.',
            'items.*.inventory_location_id.required' => 'Lokasi persediaan wajib dipilih dan harus aktif.',
            'items.*.inventory_location_id.exists' => 'Lokasi persediaan tidak ditemukan.',
            'items.*.received_qty.required' => 'Jumlah diterima wajib diisi.',
            'items.*.received_qty.min' => 'Jumlah diterima harus nol atau lebih.',
            'items.*.accepted_qty.required' => 'Jumlah diterima baik wajib diisi.',
            'items.*.accepted_qty.min' => 'Jumlah diterima baik harus nol atau lebih.',
            'items.*.rejected_qty.min' => 'Jumlah ditolak harus nol atau lebih.',
            'items.*.notes.max' => 'Catatan item tidak boleh lebih dari 500 karakter.',
            'items.*.batch_number.max' => 'Nomor batch tidak boleh lebih dari 100 karakter.',
            'items.*.lot_number.max' => 'Nomor lot tidak boleh lebih dari 100 karakter.',
            'items.*.batch_received_date.date' => 'Tanggal terima batch tidak valid.',
            'items.*.batch_received_date.before_or_equal' => 'Tanggal terima batch tidak boleh di masa depan.',
            'items.*.expiry_date.date' => 'Tanggal kedaluwarsa tidak valid.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $branchId = app(BranchContext::class)->requireId();

            $this->validateStoreOrUpdateItems($validator, $branchId);
            $this->validateGoodsReceiptBatchItems($validator, $branchId);
            $this->validateBoundGoodsReceiptForMutation($validator, $branchId);
        });
    }

    protected function validateStoreOrUpdateItems(Validator $validator, int $branchId): void
    {
        if (! $this->has('items')) {
            return;
        }

        $purchaseOrder = $this->resolvePurchaseOrderForValidation($branchId);

        if ($purchaseOrder === null && $this->filled('purchase_order_id')) {
            $validator->errors()->add('purchase_order_id', 'Pesanan pembelian tidak ditemukan atau tidak dapat diterima.');

            return;
        }

        if ($purchaseOrder !== null) {
            $this->validateReceivablePurchaseOrder($validator, $purchaseOrder, $branchId);
        }

        $poItems = $purchaseOrder?->items->keyBy('id') ?? collect();
        $seenPoItemIds = [];
        $hasReceivableQuantity = false;

        foreach ($this->input('items', []) as $index => $item) {
            $acceptedQty = (float) ($item['accepted_qty'] ?? 0);
            $rejectedQty = (float) ($item['rejected_qty'] ?? 0);
            $receivedQty = (float) ($item['received_qty'] ?? 0);

            if (round($acceptedQty + $rejectedQty, 4) !== round($receivedQty, 4)) {
                $validator->errors()->add(
                    "items.{$index}.received_qty",
                    'Jumlah diterima harus sama dengan diterima baik ditambah ditolak.',
                );
            }

            if ($acceptedQty > 0 || $receivedQty > 0) {
                $hasReceivableQuantity = true;
            }

            $poItemId = (int) ($item['purchase_order_item_id'] ?? 0);

            if ($poItemId > 0) {
                if (isset($seenPoItemIds[$poItemId])) {
                    $validator->errors()->add(
                        "items.{$index}.purchase_order_item_id",
                        'Item pesanan pembelian duplikat tidak diperbolehkan.',
                    );
                }

                $seenPoItemIds[$poItemId] = true;
            }

            if ($purchaseOrder !== null && $poItemId > 0) {
                /** @var PurchaseOrderItem|null $poItem */
                $poItem = $poItems->get($poItemId);

                if ($poItem === null || (int) $poItem->purchase_order_id !== (int) $purchaseOrder->id) {
                    $validator->errors()->add(
                        "items.{$index}.purchase_order_item_id",
                        'Item pesanan pembelian tidak valid untuk dokumen terkait.',
                    );
                } elseif (isset($item['product_id']) && (int) $item['product_id'] !== (int) $poItem->product_id) {
                    $validator->errors()->add(
                        "items.{$index}.product_id",
                        'Produk tidak sesuai dengan item pesanan pembelian.',
                    );
                } elseif ($acceptedQty > 0 && $poItem !== null) {
                    $previouslyReceived = $this->previouslyReceivedQty($poItemId);
                    $newCumulative = $previouslyReceived + $acceptedQty;

                    if ($newCumulative > (float) $poItem->quantity_ordered) {
                        $validator->errors()->add(
                            "items.{$index}.accepted_qty",
                            'Jumlah diterima baik melebihi sisa pesanan untuk item ini.',
                        );
                    }
                }
            }

            $this->validateActiveLocationInBranch(
                $validator,
                $branchId,
                "items.{$index}.inventory_location_id",
                $item['inventory_location_id'] ?? null,
            );

            $this->validateActiveProductInBranch(
                $validator,
                $branchId,
                "items.{$index}.product_id",
                $item['product_id'] ?? null,
            );
        }

        if (! $hasReceivableQuantity) {
            $validator->errors()->add('items', 'Minimal satu item harus memiliki jumlah diterima lebih dari nol.');
        }
    }

    protected function validateBoundGoodsReceiptForMutation(Validator $validator, int $branchId): void
    {
        $goodsReceipt = $this->resolveBoundGoodsReceipt();

        if ($goodsReceipt === null) {
            return;
        }

        if ($goodsReceipt->branch_id !== $branchId) {
            $validator->errors()->add('goods_receipt', 'Penerimaan barang tidak ditemukan di cabang aktif.');
        }

        if ($this->shouldRejectPostedGoodsReceipt($goodsReceipt)) {
            $validator->errors()->add('goods_receipt', 'Penerimaan barang yang sudah diposting tidak dapat diubah.');
        }

        if ($this->shouldRejectCancelledGoodsReceipt($goodsReceipt)) {
            $validator->errors()->add('goods_receipt', 'Penerimaan barang yang dibatalkan tidak dapat diubah.');
        }
    }

    protected function validateReceivablePurchaseOrder(
        Validator $validator,
        PurchaseOrder $purchaseOrder,
        int $branchId,
    ): void {
        if ($purchaseOrder->branch_id !== $branchId) {
            $validator->errors()->add('purchase_order_id', 'Pesanan pembelian tidak ditemukan atau tidak dapat diterima.');
        }

        if (! in_array($purchaseOrder->status, self::RECEIVABLE_PO_STATUSES, true)) {
            $validator->errors()->add('purchase_order_id', 'Pesanan pembelian tidak ditemukan atau tidak dapat diterima.');
        }
    }

    protected function validatePostableGoodsReceipt(Validator $validator, GoodsReceipt $goodsReceipt, int $branchId): void
    {
        if ($goodsReceipt->branch_id !== $branchId) {
            $validator->errors()->add('goods_receipt', 'Penerimaan barang tidak ditemukan di cabang aktif.');

            return;
        }

        if ($goodsReceipt->posted_at !== null || $goodsReceipt->isPosted()) {
            $validator->errors()->add('goods_receipt', 'Penerimaan barang sudah diposting.');
        }

        if ($goodsReceipt->isCancelled()) {
            $validator->errors()->add('goods_receipt', 'Penerimaan barang yang dibatalkan tidak dapat diposting.');
        }

        if (! $goodsReceipt->canBePosted()) {
            $validator->errors()->add('goods_receipt', 'Status penerimaan barang tidak valid untuk operasi ini.');
        }

        if ($this->hasPostedMovements($goodsReceipt)) {
            $validator->errors()->add('goods_receipt', 'Penerimaan barang sudah memiliki pergerakan stok.');
        }

        $goodsReceipt->loadMissing(['items.purchaseOrderItem', 'purchaseOrder.items']);
        $purchaseOrder = $goodsReceipt->purchaseOrder;

        if ($purchaseOrder === null) {
            $validator->errors()->add('purchase_order_id', 'Pesanan pembelian tidak ditemukan atau tidak dapat diterima.');

            return;
        }

        $this->validateReceivablePurchaseOrder($validator, $purchaseOrder, $branchId);

        $hasAccepted = false;

        foreach ($goodsReceipt->items as $index => $item) {
            $acceptedQty = (float) $item->accepted_qty;

            if ($acceptedQty > 0) {
                $hasAccepted = true;
            }

            $product = Product::query()
                ->where('branch_id', $branchId)
                ->whereKey($item->product_id)
                ->first();

            if ($product !== null && $product->requires_batch_tracking && $acceptedQty > 0) {
                if ($item->inventory_batch_id !== null) {
                    $this->validateExistingBatchForProduct(
                        $validator,
                        $branchId,
                        (int) $item->product_id,
                        (int) $item->inventory_batch_id,
                        "items.{$index}.inventory_batch_id",
                    );
                } elseif (filled($item->batch_number)) {
                    if ($item->batch_received_date === null) {
                        $validator->errors()->add(
                            "items.{$index}.batch_received_date",
                            'Tanggal terima batch wajib diisi untuk produk dengan pelacakan batch.',
                        );
                    }

                    if ($item->expiry_date === null) {
                        $validator->errors()->add(
                            "items.{$index}.expiry_date",
                            'Tanggal kedaluwarsa wajib diisi untuk produk dengan pelacakan batch.',
                        );
                    }
                } elseif ($item->expiry_date === null) {
                    $validator->errors()->add(
                        "items.{$index}.expiry_date",
                        'Tanggal kedaluwarsa wajib diisi untuk produk dengan pelacakan batch.',
                    );
                }
            }

            $this->validateActiveLocationInBranch(
                $validator,
                $branchId,
                "items.{$index}.inventory_location_id",
                $item->inventory_location_id,
            );

            $poItem = $item->purchaseOrderItem;

            if ($poItem === null) {
                $validator->errors()->add(
                    "items.{$index}.purchase_order_item_id",
                    'Item pesanan pembelian tidak valid untuk dokumen terkait.',
                );

                continue;
            }

            if ($acceptedQty > 0) {
                $previouslyReceived = $this->previouslyReceivedQty((int) $poItem->id);
                $newCumulative = $previouslyReceived + $acceptedQty;

                if ($newCumulative > (float) $poItem->quantity_ordered) {
                    $validator->errors()->add(
                        "items.{$index}.accepted_qty",
                        'Jumlah diterima baik melebihi sisa pesanan untuk item ini.',
                    );
                }
            }
        }

        if (! $hasAccepted) {
            $validator->errors()->add('items', 'Minimal satu item harus memiliki jumlah diterima lebih dari nol.');
        }
    }

    protected function resolvePurchaseOrderForValidation(int $branchId): ?PurchaseOrder
    {
        if ($this->filled('purchase_order_id')) {
            return PurchaseOrder::query()
                ->with('items')
                ->where('branch_id', $branchId)
                ->find((int) $this->input('purchase_order_id'));
        }

        $goodsReceipt = $this->resolveBoundGoodsReceipt();

        if ($goodsReceipt === null) {
            return null;
        }

        return PurchaseOrder::query()
            ->with('items')
            ->where('branch_id', $branchId)
            ->find((int) $goodsReceipt->purchase_order_id);
    }

    protected function resolveBoundGoodsReceipt(): ?GoodsReceipt
    {
        $goodsReceipt = $this->route('goods_receipt') ?? $this->route('goodsReceipt');

        return $goodsReceipt instanceof GoodsReceipt ? $goodsReceipt : null;
    }

    protected function shouldRejectPostedGoodsReceipt(GoodsReceipt $goodsReceipt): bool
    {
        return $this->rejectsPostedGoodsReceiptOnUpdate() && $goodsReceipt->isPosted();
    }

    protected function shouldRejectCancelledGoodsReceipt(GoodsReceipt $goodsReceipt): bool
    {
        return $this->rejectsCancelledGoodsReceiptOnUpdate() && $goodsReceipt->isCancelled();
    }

    protected function rejectsPostedGoodsReceiptOnUpdate(): bool
    {
        return true;
    }

    protected function rejectsCancelledGoodsReceiptOnUpdate(): bool
    {
        return true;
    }

    protected function previouslyReceivedQty(int $purchaseOrderItemId): float
    {
        return (float) (PurchaseOrderItem::query()
            ->whereKey($purchaseOrderItemId)
            ->value('quantity_received') ?? 0);
    }

    protected function hasPostedMovements(GoodsReceipt $goodsReceipt): bool
    {
        if ($goodsReceipt->items()->whereNotNull('inventory_movement_id')->exists()) {
            return true;
        }

        return InventoryMovement::query()
            ->where('reference_type', $goodsReceipt->getTable())
            ->where('reference_id', $goodsReceipt->id)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stripExcludedFields(array $payload): array
    {
        foreach (self::EXCLUDED_FIELDS as $field) {
            unset($payload[$field]);
        }

        if (isset($payload['items']) && is_array($payload['items'])) {
            foreach ($payload['items'] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (self::EXCLUDED_FIELDS as $field) {
                    unset($item[$field]);
                }

                if (! isset($item['rejected_qty'])) {
                    $item['rejected_qty'] = 0;
                }

                $payload['items'][$index] = $item;
            }
        }

        return $payload;
    }

    private function validateActiveLocationInBranch(
        Validator $validator,
        int $branchId,
        string $field,
        mixed $locationId,
    ): void {
        if (! is_numeric($locationId)) {
            return;
        }

        $location = InventoryLocation::query()
            ->where('branch_id', $branchId)
            ->whereKey((int) $locationId)
            ->first();

        if (! $location || ! $location->is_active) {
            $validator->errors()->add($field, 'Lokasi persediaan wajib dipilih dan harus aktif.');
        }
    }

    private function validateActiveProductInBranch(
        Validator $validator,
        int $branchId,
        string $field,
        mixed $productId,
    ): void {
        if (! is_numeric($productId)) {
            return;
        }

        $product = Product::query()
            ->where('branch_id', $branchId)
            ->whereKey((int) $productId)
            ->first();

        if (! $product || ! $product->is_active) {
            $validator->errors()->add($field, 'Produk tidak valid untuk cabang aktif.');
        }
    }
}
