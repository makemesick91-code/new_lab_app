<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Central guard: batch-tracked source-document items require inventory_batch_id
 * before finalize / movement creation. DQ-3 backfill may bypass via explicit flag.
 */
class SourceDocumentBatchGuard
{
    public static bool $bypassForGovernanceBackfill = false;

    public static function assertItem(
        Product $product,
        ?int $inventoryBatchId,
        string $field = 'inventory_batch_id',
    ): void {
        if (self::$bypassForGovernanceBackfill) {
            return;
        }

        if (! $product->requires_batch_tracking) {
            return;
        }

        if ($inventoryBatchId !== null && $inventoryBatchId > 0) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'Batch wajib untuk produk dengan pelacakan batch sebelum dokumen sumber difinalisasi.',
        ]);
    }

    public static function assertBatchMatchesProduct(
        int $branchId,
        int $productId,
        int $inventoryBatchId,
        string $field = 'inventory_batch_id',
    ): void {
        $batch = InventoryBatch::query()
            ->where('branch_id', $branchId)
            ->whereKey($inventoryBatchId)
            ->first();

        if ($batch === null || ! $batch->is_active) {
            throw ValidationException::withMessages([
                $field => 'Batch tidak valid untuk cabang aktif.',
            ]);
        }

        if ((int) $batch->product_id !== $productId) {
            throw ValidationException::withMessages([
                $field => 'Batch tidak sesuai dengan produk pada baris ini.',
            ]);
        }
    }
}
