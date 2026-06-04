<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\ProductCategoryRepositoryInterface;
use App\Modules\Inventory\Models\ProductCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProductCategoryRepository implements ProductCategoryRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return ProductCategory::query()
            ->where('branch_id', $branchId)
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                });
            })
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listActive(int $branchId): Collection
    {
        return ProductCategory::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function findInBranch(int $branchId, int $id): ?ProductCategory
    {
        return ProductCategory::query()
            ->where('branch_id', $branchId)
            ->find($id);
    }

    public function create(array $data): ProductCategory
    {
        return ProductCategory::create($data);
    }

    public function update(ProductCategory $category, array $data): ProductCategory
    {
        $category->update($data);

        return $category->refresh();
    }

    public function deactivate(ProductCategory $category): ProductCategory
    {
        $category->update(['is_active' => false]);

        return $category->refresh();
    }
}
