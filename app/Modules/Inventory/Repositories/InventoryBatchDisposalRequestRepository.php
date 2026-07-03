<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\InventoryBatchDisposalRequestRepositoryInterface;
use App\Modules\Inventory\Models\InventoryBatchDisposalRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class InventoryBatchDisposalRequestRepository implements InventoryBatchDisposalRequestRepositoryInterface
{
    public function create(array $data): InventoryBatchDisposalRequest
    {
        return InventoryBatchDisposalRequest::create($data);
    }

    public function findForBranch(int $branchId, int $id): ?InventoryBatchDisposalRequest
    {
        return InventoryBatchDisposalRequest::query()
            ->where('branch_id', $branchId)
            ->whereKey($id)
            ->first();
    }

    public function findForBranchOrFail(int $branchId, int $id): InventoryBatchDisposalRequest
    {
        return InventoryBatchDisposalRequest::query()
            ->where('branch_id', $branchId)
            ->whereKey($id)
            ->firstOrFail();
    }

    public function update(InventoryBatchDisposalRequest $request, array $data): InventoryBatchDisposalRequest
    {
        $request->update($data);

        return $request->fresh();
    }

    public function forBatch(int $branchId, int $batchId, int $limit = 20): Collection
    {
        return InventoryBatchDisposalRequest::query()
            ->with(['location', 'submittedBy', 'approvedBy', 'finalizedBy', 'movement'])
            ->where('branch_id', $branchId)
            ->where('inventory_batch_id', $batchId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function latestForBatch(int $branchId, int $batchId): ?InventoryBatchDisposalRequest
    {
        return InventoryBatchDisposalRequest::query()
            ->where('branch_id', $branchId)
            ->where('inventory_batch_id', $batchId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    public function paginateForBranch(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryBatchDisposalRequest::query()
            ->with(['batch', 'product', 'location', 'submittedBy', 'approvedBy', 'finalizedBy'])
            ->where('branch_id', $branchId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['request_type'])) {
            $query->where('request_type', $filters['request_type']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.mb_strtolower((string) $filters['search']).'%';
            $query->where(function ($q) use ($search) {
                $q->whereHas('batch', fn ($b) => $b->whereRaw('LOWER(batch_number) LIKE ?', [$search]))
                    ->orWhereHas('product', fn ($p) => $p
                        ->whereRaw('LOWER(name) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$search]));
            });
        }

        return $query->paginate($perPage)->withQueryString();
    }
}
