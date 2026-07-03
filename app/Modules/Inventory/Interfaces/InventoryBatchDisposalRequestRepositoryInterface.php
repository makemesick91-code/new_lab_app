<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\InventoryBatchDisposalRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface InventoryBatchDisposalRequestRepositoryInterface
{
    public function create(array $data): InventoryBatchDisposalRequest;

    public function findForBranch(int $branchId, int $id): ?InventoryBatchDisposalRequest;

    public function findForBranchOrFail(int $branchId, int $id): InventoryBatchDisposalRequest;

    public function update(InventoryBatchDisposalRequest $request, array $data): InventoryBatchDisposalRequest;

    /**
     * @return Collection<int, InventoryBatchDisposalRequest>
     */
    public function forBatch(int $branchId, int $batchId, int $limit = 20): Collection;

    public function latestForBatch(int $branchId, int $batchId): ?InventoryBatchDisposalRequest;

    public function paginateForBranch(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;
}
