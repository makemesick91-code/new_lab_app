<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\InventoryBatchRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\SupplierRepositoryInterface;
use App\Modules\Inventory\Models\InventoryBatch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class InventoryBatchService
{
    public const EXPIRING_SOON_DAYS = 30;

    public function __construct(
        private readonly InventoryBatchRepositoryInterface $batches,
        private readonly ProductRepositoryInterface $products,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly BranchContext $branchContext,
    ) {}

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->batches->paginateForBranch(
            $this->branchContext->requireId(),
            $filters,
            $perPage,
        );
    }

    public function findForShow(int $batchId): ?InventoryBatch
    {
        return $this->batches->findForBranch($this->branchContext->requireId(), $batchId);
    }

    /**
     * @return array{
     *     batch: InventoryBatch,
     *     totalStock: float,
     *     stockByLocation: Collection,
     *     movements: Collection,
     *     transferReferences: Collection,
     *     isExpired: bool,
     *     isExpiringSoon: bool,
     * }
     */
    public function showData(InventoryBatch $batch): array
    {
        $branchId = $this->branchContext->requireId();

        return [
            'batch' => $batch,
            'totalStock' => $this->batches->totalStockForBatch($branchId, $batch->id),
            'stockByLocation' => $this->batches->stockByLocation($branchId, $batch->id),
            'movements' => $this->batches->movementsForBatch($branchId, $batch->id),
            'transferReferences' => $this->batches->transferReferencesForBatch($branchId, $batch->id),
            'isExpired' => $this->isExpired($batch),
            'isExpiringSoon' => $this->isExpiringSoon($batch),
        ];
    }

    public function listActiveProducts(): Collection
    {
        return $this->products->listActive($this->branchContext->requireId());
    }

    public function listActiveSuppliers(): Collection
    {
        return $this->suppliers->listActive($this->branchContext->requireId());
    }

    public function isExpired(InventoryBatch $batch): bool
    {
        return $batch->expiry_date !== null
            && $batch->expiry_date->toDateString() < now()->toDateString();
    }

    public function isExpiringSoon(InventoryBatch $batch): bool
    {
        if ($batch->expiry_date === null || $this->isExpired($batch)) {
            return false;
        }

        $today = now()->startOfDay();
        $threshold = now()->addDays(self::EXPIRING_SOON_DAYS)->endOfDay();

        return $batch->expiry_date->betweenIncluded($today, $threshold);
    }

    public function resolveDisplayStatus(InventoryBatch $batch): string
    {
        if (! $batch->is_active) {
            return 'inactive';
        }

        if ($this->isExpired($batch)) {
            return 'expired';
        }

        if ($this->isExpiringSoon($batch)) {
            return 'expiring_soon';
        }

        return 'active';
    }
}
