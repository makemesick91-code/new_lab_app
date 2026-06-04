<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProductRepository implements ProductRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return Product::query()
            ->with(['category', 'unit'])
            ->where('branch_id', $branchId)
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term])
                        ->orWhereHas('category', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->when($filters['product_category_id'] ?? null, fn ($q, $v) => $q->where('product_category_id', $v))
            ->when($filters['product_unit_id'] ?? null, fn ($q, $v) => $q->where('product_unit_id', $v))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listActive(int $branchId): Collection
    {
        return Product::query()
            ->with(['category', 'unit'])
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function findInBranch(int $branchId, int $id): ?Product
    {
        return Product::query()
            ->with(['category', 'unit'])
            ->where('branch_id', $branchId)
            ->find($id);
    }

    public function create(array $data): Product
    {
        return Product::create($data);
    }

    public function update(Product $product, array $data): Product
    {
        $product->update($data);

        return $product->refresh();
    }

    public function deactivate(Product $product): Product
    {
        $product->update(['is_active' => false]);

        return $product->refresh();
    }

    public function hasMovements(int $branchId, int $productId): bool
    {
        return InventoryMovement::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->exists();
    }
}
