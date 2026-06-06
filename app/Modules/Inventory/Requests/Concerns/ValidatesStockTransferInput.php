<?php

namespace App\Modules\Inventory\Requests\Concerns;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesStockTransferInput
{
    /**
     * @return array<string, mixed>
     */
    protected function stockTransferInputRules(): array
    {
        return [
            'source_inventory_location_id' => ['required', 'integer', 'exists:inv_inventory_locations,id'],
            'destination_inventory_location_id' => [
                'required',
                'integer',
                'exists:inv_inventory_locations,id',
                'different:source_inventory_location_id',
            ],
            'transfer_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:inv_products,id'],
            'items.*.inventory_batch_id' => ['nullable', 'integer', 'exists:inv_inventory_batches,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $branchId = app(BranchContext::class)->requireId();

            $this->validateActiveLocationInBranch(
                $validator,
                $branchId,
                'source_inventory_location_id',
                $this->input('source_inventory_location_id'),
            );

            $this->validateActiveLocationInBranch(
                $validator,
                $branchId,
                'destination_inventory_location_id',
                $this->input('destination_inventory_location_id'),
            );

            foreach ($this->input('items', []) as $index => $item) {
                $this->validateActiveProductInBranch(
                    $validator,
                    $branchId,
                    "items.{$index}.product_id",
                    $item['product_id'] ?? null,
                );

                $this->validateBatchForTransferItem(
                    $validator,
                    $branchId,
                    "items.{$index}.inventory_batch_id",
                    $item['inventory_batch_id'] ?? null,
                    $item['product_id'] ?? null,
                );
            }
        });
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
            $validator->errors()->add($field, 'Lokasi persediaan tidak valid untuk cabang aktif.');
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

    private function validateBatchForTransferItem(
        Validator $validator,
        int $branchId,
        string $field,
        mixed $batchId,
        mixed $productId,
    ): void {
        if (! is_numeric($batchId)) {
            return;
        }

        $batch = InventoryBatch::query()
            ->where('branch_id', $branchId)
            ->whereKey((int) $batchId)
            ->first();

        if (! $batch) {
            $validator->errors()->add($field, 'Batch tidak valid untuk cabang aktif.');

            return;
        }

        if (is_numeric($productId) && $batch->product_id !== (int) $productId) {
            $validator->errors()->add($field, 'Batch tidak sesuai dengan produk yang dipilih.');
        }

        if (! $batch->is_active) {
            $validator->errors()->add($field, 'Batch tidak aktif dan tidak dapat digunakan.');
        }
    }
}
