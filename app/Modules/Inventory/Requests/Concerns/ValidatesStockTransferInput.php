<?php

namespace App\Modules\Inventory\Requests\Concerns;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
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
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
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
            $sourceLocationId = (int) $this->input('source_inventory_location_id');
            $movements = app(InventoryMovementRepositoryInterface::class);

            $this->validateActiveLocationInBranch(
                $validator,
                $branchId,
                'source_inventory_location_id',
                $sourceLocationId,
            );

            $this->validateActiveLocationInBranch(
                $validator,
                $branchId,
                'destination_inventory_location_id',
                $this->input('destination_inventory_location_id'),
            );

            foreach ($this->input('items', []) as $index => $item) {
                $productId = $item['product_id'] ?? null;

                $this->validateActiveProductInBranch(
                    $validator,
                    $branchId,
                    "items.{$index}.product_id",
                    $productId,
                );

                if (! is_numeric($productId)) {
                    continue;
                }

                $product = Product::query()
                    ->where('branch_id', $branchId)
                    ->whereKey((int) $productId)
                    ->first();

                if ($product === null) {
                    continue;
                }

                $batchId = $item['inventory_batch_id'] ?? null;
                $quantity = (float) ($item['quantity'] ?? 0);

                if ($product->requires_batch_tracking) {
                    if (! is_numeric($batchId)) {
                        $validator->errors()->add(
                            "items.{$index}.inventory_batch_id",
                            'Batch wajib dipilih untuk produk dengan pelacakan batch.',
                        );

                        continue;
                    }

                    $this->validateBatchForTransferItem(
                        $validator,
                        $branchId,
                        "items.{$index}.inventory_batch_id",
                        $batchId,
                        $productId,
                    );

                    if ($validator->errors()->has("items.{$index}.inventory_batch_id")) {
                        continue;
                    }

                    $available = $movements->currentStockByBatch(
                        $branchId,
                        (int) $productId,
                        $sourceLocationId,
                        (int) $batchId,
                    );

                    if ($available <= 0) {
                        $validator->errors()->add(
                            "items.{$index}.inventory_batch_id",
                            'Batch tidak memiliki stok tersedia di lokasi asal.',
                        );

                        continue;
                    }

                    if ($quantity > $available) {
                        $validator->errors()->add(
                            "items.{$index}.quantity",
                            'Jumlah transfer melebihi stok batch yang tersedia di lokasi asal.',
                        );
                    }
                } elseif (is_numeric($batchId)) {
                    $validator->errors()->add(
                        "items.{$index}.inventory_batch_id",
                        'Batch tidak diperlukan untuk produk tanpa pelacakan batch.',
                    );
                }
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
