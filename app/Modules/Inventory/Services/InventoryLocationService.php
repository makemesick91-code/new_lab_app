<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Models\InventoryLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryLocationService
{
    public function __construct(
        private readonly InventoryLocationRepositoryInterface $locations,
        private readonly BranchContext $branchContext,
    ) {}

    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->locations->paginate($this->branchContext->requireId(), $filters, $perPage);
    }

    public function listActive(): Collection
    {
        return $this->locations->listActive($this->branchContext->requireId());
    }

    public function find(int $id): ?InventoryLocation
    {
        return $this->locations->findInBranch($this->branchContext->requireId(), $id);
    }

    public function create(array $data): InventoryLocation
    {
        return DB::transaction(function () use ($data) {
            return $this->locations->create(array_merge($data, [
                'branch_id' => $this->branchContext->requireId(),
            ]));
        });
    }

    public function update(InventoryLocation $location, array $data): InventoryLocation
    {
        return DB::transaction(fn () => $this->locations->update($location, $data));
    }

    public function deactivate(InventoryLocation $location): InventoryLocation
    {
        return DB::transaction(fn () => $this->locations->deactivate($location));
    }
}
