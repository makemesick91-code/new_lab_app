<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\ProductCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ProductCategoryRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function listActive(int $branchId): Collection;

    public function findInBranch(int $branchId, int $id): ?ProductCategory;

    public function create(array $data): ProductCategory;

    public function update(ProductCategory $category, array $data): ProductCategory;

    public function deactivate(ProductCategory $category): ProductCategory;
}
