<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Central guard: batch-tracked products require inventory_batch_id on new movements.
 * DQ-2 backfill may bypass via explicit internal flag only.
 */
class InventoryMovementBatchGuard
{
    public static bool $bypassForGovernanceBackfill = false;

    /**
     * @param  array<string, mixed>  $data
     */
    public static function assert(array $data): void
    {
        if (self::$bypassForGovernanceBackfill) {
            return;
        }

        $productId = isset($data['product_id']) ? (int) $data['product_id'] : 0;
        $batchId = $data['inventory_batch_id'] ?? null;
        $qtyIn = (float) ($data['quantity_in'] ?? 0);
        $qtyOut = (float) ($data['quantity_out'] ?? 0);

        if ($productId <= 0 || ($qtyIn <= 0 && $qtyOut <= 0)) {
            return;
        }

        if ($batchId !== null && $batchId !== '') {
            return;
        }

        $requiresBatch = Product::query()
            ->whereKey($productId)
            ->value('requires_batch_tracking');

        if ($requiresBatch) {
            throw ValidationException::withMessages([
                'inventory_batch_id' => 'Batch wajib untuk produk dengan pelacakan batch.',
            ]);
        }
    }
}
