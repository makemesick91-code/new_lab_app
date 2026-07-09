<?php

namespace App\Modules\Inventory\Requests\Concerns;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\AutoBatchNumberService;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesGoodsReceiptBatchInput
{
    /**
     * @return array<string, mixed>
     */
    protected function goodsReceiptBatchItemRules(): array
    {
        return [
            'items.*.batch_mode' => ['nullable', 'string', 'in:existing,new'],
            'items.*.auto_batch' => ['nullable', 'boolean'],
            'items.*.inventory_batch_id' => ['nullable', 'integer', 'exists:inv_inventory_batches,id'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.lot_number' => ['nullable', 'string', 'max:100'],
            'items.*.batch_received_date' => ['nullable', 'date', 'before_or_equal:today'],
            'items.*.expiry_date' => ['nullable', 'date'],
        ];
    }

    /**
     * FIX-PRE-68-45 Scope E — expand the GR header-level default batch/lot into each
     * batch-tracked item that has no item-level batch of its own. Called from
     * prepareForValidation so the existing per-item batch rules validate the merged
     * values. The same default batch_number yields a DISTINCT batch row per product
     * at post time (found-or-create key = branch_id + product_id + batch_number +
     * lot_number) — never one shared batch across products. Item-level batch always
     * overrides; non-batch-tracked products keep their batch empty.
     */
    protected function applyDefaultBatchToItems(): void
    {
        if (! $this->boolean('apply_default_batch_to_all')) {
            return;
        }

        $defaultBatch = $this->input('default_batch_number');
        $defaultExpiry = $this->input('default_expiry_date');

        // Nothing to expand — the per-item rules will still block a batch-tracked
        // item that has neither its own batch nor a usable default.
        if (! filled($defaultBatch) && ! filled($defaultExpiry)) {
            return;
        }

        $items = $this->input('items');

        if (! is_array($items) || $items === []) {
            return;
        }

        try {
            $branchId = app(BranchContext::class)->requireId();
        } catch (\Throwable) {
            return;
        }

        $productIds = collect($items)
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        if ($productIds === []) {
            return;
        }

        $batchTrackedIds = Product::query()
            ->where('branch_id', $branchId)
            ->whereIn('id', $productIds)
            ->where('requires_batch_tracking', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($batchTrackedIds === []) {
            return;
        }

        $defaultLot = $this->input('default_lot_number');
        $defaultReceived = $this->input('default_batch_received_date') ?: $this->input('receipt_date');

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            $acceptedQty = (float) ($item['accepted_qty'] ?? 0);

            if ($acceptedQty <= 0 || ! in_array($productId, $batchTrackedIds, true)) {
                continue;
            }

            // Item-level batch always wins — never overwrite an EXPLICIT selection
            // (an existing batch, an entered batch number, or an explicit auto-batch
            // request). A blank item receives the header default (the default takes
            // precedence over the implicit auto-batch fallback).
            $explicitAutoBatch = array_key_exists('auto_batch', $item)
                && filter_var($item['auto_batch'], FILTER_VALIDATE_BOOLEAN);

            $hasExplicitBatch = filled($item['inventory_batch_id'] ?? null)
                || ($item['batch_mode'] ?? null) === 'existing'
                || filled($item['batch_number'] ?? null)
                || $explicitAutoBatch;

            if ($hasExplicitBatch) {
                continue;
            }

            $item['batch_mode'] = 'new';

            if (filled($defaultBatch)) {
                $item['batch_number'] = $defaultBatch;
            }

            if (filled($defaultLot)) {
                $item['lot_number'] = $defaultLot;
            }

            if (filled($defaultExpiry)) {
                $item['expiry_date'] = $defaultExpiry;
            }

            if (filled($defaultReceived)) {
                $item['batch_received_date'] = $defaultReceived;
            }

            $items[$index] = $item;
        }

        $this->merge(['items' => $items]);
    }

    protected function validateGoodsReceiptBatchItems(Validator $validator, int $branchId): void
    {
        if (! $this->has('items')) {
            return;
        }

        foreach ($this->input('items', []) as $index => $item) {
            $acceptedQty = (float) ($item['accepted_qty'] ?? 0);

            if ($acceptedQty <= 0) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            $product = Product::query()
                ->where('branch_id', $branchId)
                ->whereKey($productId)
                ->first();

            if ($product === null || ! $product->requires_batch_tracking) {
                continue;
            }

            $batchMode = $item['batch_mode'] ?? null;
            $inventoryBatchId = $item['inventory_batch_id'] ?? null;

            if ($batchMode === 'existing' || ($batchMode === null && filled($inventoryBatchId))) {
                if (! filled($inventoryBatchId)) {
                    $validator->errors()->add(
                        "items.{$index}.inventory_batch_id",
                        'Pilih batch yang ada atau buat batch baru.',
                    );

                    continue;
                }

                $this->validateExistingBatchForProduct(
                    $validator,
                    $branchId,
                    $productId,
                    (int) $inventoryBatchId,
                    "items.{$index}.inventory_batch_id",
                );

                continue;
            }

            $autoBatch = AutoBatchNumberService::isAutoBatchRequest($item);

            if ($autoBatch) {
                if (! filled($item['expiry_date'] ?? null)) {
                    $validator->errors()->add(
                        "items.{$index}.expiry_date",
                        'Tanggal kedaluwarsa wajib diisi untuk produk dengan pelacakan batch.',
                    );
                }

                continue;
            }

            if (! filled($item['batch_number'] ?? null)) {
                $validator->errors()->add(
                    "items.{$index}.batch_number",
                    'Nomor batch wajib diisi untuk produk dengan pelacakan batch.',
                );
            }

            $batchReceivedDate = $item['batch_received_date'] ?? $this->input('receipt_date');

            if (! filled($batchReceivedDate)) {
                $validator->errors()->add(
                    "items.{$index}.batch_received_date",
                    'Tanggal terima batch wajib diisi untuk produk dengan pelacakan batch.',
                );
            }

            if (! filled($item['expiry_date'] ?? null)) {
                $validator->errors()->add(
                    "items.{$index}.expiry_date",
                    'Tanggal kedaluwarsa wajib diisi untuk produk dengan pelacakan batch.',
                );
            }

            if (filled($item['expiry_date'] ?? null) && filled($batchReceivedDate)) {
                if ($item['expiry_date'] < $batchReceivedDate) {
                    $validator->errors()->add(
                        "items.{$index}.expiry_date",
                        'Tanggal kedaluwarsa tidak boleh sebelum tanggal terima batch.',
                    );
                }
            }
        }
    }

    protected function validateExistingBatchForProduct(
        Validator $validator,
        int $branchId,
        int $productId,
        int $batchId,
        string $field,
    ): void {
        $batch = InventoryBatch::query()
            ->where('branch_id', $branchId)
            ->whereKey($batchId)
            ->first();

        if ($batch === null || ! $batch->is_active) {
            $validator->errors()->add($field, 'Batch tidak valid untuk cabang aktif.');

            return;
        }

        if ($batch->product_id !== $productId) {
            $validator->errors()->add($field, 'Batch tidak sesuai dengan produk pada baris ini.');
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    protected function normalizeGoodsReceiptBatchFields(array $item, ?string $receiptDate = null): ?array
    {
        $batchMode = $item['batch_mode'] ?? null;

        if ($batchMode === 'existing' || ($batchMode === null && filled($item['inventory_batch_id'] ?? null))) {
            return filled($item['inventory_batch_id'] ?? null)
                ? ['inventory_batch_id' => (int) $item['inventory_batch_id']]
                : null;
        }

        if (AutoBatchNumberService::isAutoBatchRequest($item)) {
            if (! filled($item['expiry_date'] ?? null)) {
                return null;
            }

            return [
                'inventory_batch_id' => null,
                'batch_number' => null,
                'lot_number' => null,
                'batch_received_date' => $item['batch_received_date'] ?? $receiptDate,
                'expiry_date' => $item['expiry_date'],
            ];
        }

        if (! filled($item['batch_number'] ?? null)) {
            return null;
        }

        return [
            'inventory_batch_id' => null,
            'batch_number' => trim((string) $item['batch_number']),
            'lot_number' => filled($item['lot_number'] ?? null) ? trim((string) $item['lot_number']) : null,
            'batch_received_date' => $item['batch_received_date'] ?? $receiptDate,
            'expiry_date' => $item['expiry_date'] ?? null,
        ];
    }
}
