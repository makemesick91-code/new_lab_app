<?php

namespace App\Modules\Inventory\Requests\Concerns;

use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\Product;
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
            'items.*.inventory_batch_id' => ['nullable', 'integer', 'exists:inv_inventory_batches,id'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.lot_number' => ['nullable', 'string', 'max:100'],
            'items.*.batch_received_date' => ['nullable', 'date', 'before_or_equal:today'],
            'items.*.expiry_date' => ['nullable', 'date'],
        ];
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
