<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BatchStockOptionService
{
    public function __construct(
        private readonly InventoryMovementRepositoryInterface $movements,
        private readonly ProductRepositoryInterface $products,
        private readonly BranchContext $branchContext,
        private readonly BatchExpiryStatusService $expiryStatus,
    ) {}

    /**
     * @return Collection<int, array{
     *     batch_id: int,
     *     batch_number: string,
     *     lot_number: string|null,
     *     expiry_date: string|null,
     *     expiry_label: string|null,
     *     available_qty: float,
     *     label: string
     * }>
     */
    public function availableForProductLocation(int $productId, int $branchId, int $locationId): Collection
    {
        $this->assertProductInBranch($branchId, $productId);
        $this->assertLocationInBranch($branchId, $locationId);

        $batchStock = DB::table('trx_inventory_movements')
            ->select('inventory_batch_id')
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as available_qty')
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('inventory_location_id', $locationId)
            ->whereNotNull('inventory_batch_id')
            ->groupBy('inventory_batch_id')
            ->havingRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) > 0');

        $rows = InventoryBatch::query()
            ->joinSub($batchStock, 'batch_stock', function ($join) {
                $join->on('batch_stock.inventory_batch_id', '=', 'inv_inventory_batches.id');
            })
            ->where('inv_inventory_batches.branch_id', $branchId)
            ->where('inv_inventory_batches.product_id', $productId)
            ->where('inv_inventory_batches.is_active', true)
            ->select([
                'inv_inventory_batches.id',
                'inv_inventory_batches.batch_number',
                'inv_inventory_batches.lot_number',
                'inv_inventory_batches.expiry_date',
                'batch_stock.available_qty',
            ])
            ->orderByRaw('inv_inventory_batches.expiry_date ASC NULLS LAST')
            ->orderBy('inv_inventory_batches.batch_number')
            ->get();

        return $rows->map(function ($row, int $index) {
            $availableQty = round((float) $row->available_qty, 4);
            $expiryLabel = $row->expiry_date !== null
                ? $this->formatExpiryLabel($row->expiry_date)
                : null;
            $isRecommended = $index === 0;

            return [
                'batch_id' => (int) $row->id,
                'batch_number' => (string) $row->batch_number,
                'lot_number' => $row->lot_number !== null ? (string) $row->lot_number : null,
                'expiry_date' => $row->expiry_date,
                'expiry_label' => $expiryLabel,
                'available_qty' => $availableQty,
                'is_fefo_recommended' => $isRecommended,
                'is_expired' => $this->expiryStatus->isExpired($row->expiry_date),
                'label' => $this->buildOptionLabel(
                    (string) $row->batch_number,
                    $expiryLabel,
                    $availableQty,
                    $isRecommended,
                    $row->expiry_date,
                ),
            ];
        })->values();
    }

    /**
     * @return array<int, array<int, array<int, array{id: int, batch_number: string, lot_number: string|null, expiry_date: string|null, expiry_label: string|null, stock: float, label: string}>>>
     */
    public function batchOptionsMatrixForTransfer(int $branchId): array
    {
        $matrix = [];

        foreach ($this->products->listActive($branchId) as $product) {
            if (! $product->requires_batch_tracking) {
                continue;
            }

            foreach (app(InventoryLocationRepositoryInterface::class)->listActive($branchId) as $location) {
                $options = $this->availableForProductLocation($product->id, $branchId, $location->id);

                if ($options->isNotEmpty()) {
                    $matrix[$product->id][$location->id] = $options
                        ->map(fn (array $option) => [
                            'id' => $option['batch_id'],
                            'batch_number' => $option['batch_number'],
                            'lot_number' => $option['lot_number'],
                            'expiry_date' => $option['expiry_date'],
                            'expiry_label' => $option['expiry_label'],
                            'stock' => $option['available_qty'],
                            'label' => $option['label'],
                        ])
                        ->all();
                }
            }
        }

        return $matrix;
    }

    public function assertBatchAvailableForProductLocation(
        int $branchId,
        int $productId,
        int $locationId,
        int $batchId,
        float $requestedQty,
        string $batchField = 'inventory_batch_id',
        string $quantityField = 'quantity',
    ): void {
        $available = $this->movements->currentStockByBatch($branchId, $productId, $locationId, $batchId);

        if ($available <= 0) {
            throw ValidationException::withMessages([
                $batchField => 'Batch tidak memiliki stok tersedia di lokasi asal.',
            ]);
        }

        if ($requestedQty > $available) {
            throw ValidationException::withMessages([
                $quantityField => 'Jumlah transfer melebihi stok batch yang tersedia di lokasi asal.',
            ]);
        }
    }

    private function buildOptionLabel(
        string $batchNumber,
        ?string $expiryLabel,
        float $availableQty,
        bool $isRecommended = false,
        mixed $expiryDate = null,
    ): string {
        $label = $batchNumber;

        if ($expiryLabel !== null) {
            $label .= ' — Exp '.$expiryLabel;
        }

        $label .= ' — Stok '.format_quantity_id($availableQty);

        if ($isRecommended) {
            $label .= ' — Disarankan FEFO';
        }

        if ($this->expiryStatus->isExpired($expiryDate)) {
            $label .= ' — Kedaluwarsa';
        }

        return $label;
    }

    private function formatExpiryLabel(mixed $expiryDate): string
    {
        $date = parse_display_date($expiryDate);

        if (! $date) {
            return '-';
        }

        $month = substr(month_name_id((int) $date->format('n')), 0, 3);

        return $date->format('j').' '.$month.' '.$date->format('Y');
    }

    private function assertProductInBranch(int $branchId, int $productId): Product
    {
        $product = $this->products->findInBranch($branchId, $productId);

        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => 'Produk tidak valid untuk cabang aktif.',
            ]);
        }

        return $product;
    }

    private function assertLocationInBranch(int $branchId, int $locationId): InventoryLocation
    {
        $location = app(InventoryLocationRepositoryInterface::class)->findInBranch($branchId, $locationId);

        if (! $location || ! $location->is_active) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Lokasi persediaan tidak valid untuk cabang aktif.',
            ]);
        }

        return $location;
    }
}
