<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Models\InventoryLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class InventoryLocationRepository implements InventoryLocationRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return InventoryLocation::query()
            ->where('branch_id', $branchId)
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                });
            })
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listActive(int $branchId): Collection
    {
        return InventoryLocation::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function findInBranch(int $branchId, int $id): ?InventoryLocation
    {
        return InventoryLocation::query()
            ->where('branch_id', $branchId)
            ->find($id);
    }

    public function create(array $data): InventoryLocation
    {
        return InventoryLocation::create($data);
    }

    public function update(InventoryLocation $location, array $data): InventoryLocation
    {
        $location->update($data);

        return $location->refresh();
    }

    public function deactivate(InventoryLocation $location): InventoryLocation
    {
        $location->update(['is_active' => false]);

        return $location->refresh();
    }
}
