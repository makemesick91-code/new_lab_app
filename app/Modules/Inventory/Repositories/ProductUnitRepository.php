<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\ProductUnitRepositoryInterface;
use App\Modules\Inventory\Models\ProductUnit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProductUnitRepository implements ProductUnitRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return ProductUnit::query()
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(symbol) LIKE ?', [$term]);
                });
            })
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listActive(): Collection
    {
        return ProductUnit::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): ?ProductUnit
    {
        return ProductUnit::find($id);
    }

    public function create(array $data): ProductUnit
    {
        return ProductUnit::create($data);
    }

    public function update(ProductUnit $unit, array $data): ProductUnit
    {
        $unit->update($data);

        return $unit->refresh();
    }

    public function deactivate(ProductUnit $unit): ProductUnit
    {
        $unit->update(['is_active' => false]);

        return $unit->refresh();
    }
}
