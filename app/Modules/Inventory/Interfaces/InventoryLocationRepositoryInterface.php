<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\InventoryLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface InventoryLocationRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function listActive(int $branchId): Collection;

    public function findInBranch(int $branchId, int $id): ?InventoryLocation;

    public function create(array $data): InventoryLocation;

    public function update(InventoryLocation $location, array $data): InventoryLocation;

    public function deactivate(InventoryLocation $location): InventoryLocation;
}
