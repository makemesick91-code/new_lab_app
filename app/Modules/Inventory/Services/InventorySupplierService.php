<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\SupplierRepositoryInterface;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventorySupplierService
{
    public function __construct(
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly BranchContext $branchContext,
    ) {}

    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->suppliers->paginate($this->branchContext->requireId(), $filters, $perPage);
    }

    public function listActive(): Collection
    {
        return $this->suppliers->listActive($this->branchContext->requireId());
    }

    public function find(int $id): ?Supplier
    {
        return $this->suppliers->findInBranch($this->branchContext->requireId(), $id);
    }

    public function create(array $data): Supplier
    {
        return DB::transaction(function () use ($data) {
            return $this->suppliers->create(array_merge($data, [
                'branch_id' => $this->branchContext->requireId(),
            ]));
        });
    }

    public function update(Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(fn () => $this->suppliers->update($supplier, $data));
    }

    public function deactivate(Supplier $supplier): Supplier
    {
        return DB::transaction(fn () => $this->suppliers->deactivate($supplier));
    }
}
