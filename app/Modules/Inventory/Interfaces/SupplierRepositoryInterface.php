<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface SupplierRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function listActive(int $branchId): Collection;

    public function findInBranch(int $branchId, int $id): ?Supplier;

    public function create(array $data): Supplier;

    public function update(Supplier $supplier, array $data): Supplier;

    public function deactivate(Supplier $supplier): Supplier;
}
