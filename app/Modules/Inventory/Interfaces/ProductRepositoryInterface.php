<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ProductRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function listActive(int $branchId): Collection;

    public function findInBranch(int $branchId, int $id): ?Product;

    public function create(array $data): Product;

    public function update(Product $product, array $data): Product;

    public function deactivate(Product $product): Product;

    public function hasMovements(int $branchId, int $productId): bool;
}
