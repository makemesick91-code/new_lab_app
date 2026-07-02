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
    public function __construct(
        private readonly InventoryBatchRepositoryInterface $batches,
        private readonly ProductRepositoryInterface $products,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly BranchContext $branchContext,
        private readonly BatchExpiryStatusService $expiryStatus,
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
     *     expiryStatus: string,
     *     expiryDaysText: string,
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
            'expiryStatus' => $this->resolveDisplayStatus($batch),
            'expiryDaysText' => $this->expiryDaysText($batch),
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
        return $this->expiryStatus->isExpired($batch->expiry_date);
    }

    public function isExpiringSoon(InventoryBatch $batch): bool
    {
        return $this->expiryStatus->isNearExpiry($batch->expiry_date);
    }

    public function resolveDisplayStatus(InventoryBatch $batch): string
    {
        if (! $batch->is_active) {
            return 'inactive';
        }

        return $this->expiryStatus->status($batch->expiry_date);
    }

    public function expiryDaysText(InventoryBatch $batch): string
    {
        return $this->expiryStatus->daysText($batch->expiry_date);
    }
}
