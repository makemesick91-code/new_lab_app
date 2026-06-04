<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\StockOpnameRepositoryInterface;
use App\Modules\Inventory\Models\StockOpname;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence + query composition only — no business rules (PROJECT_RULES §9).
 * Branch-safe: list/find/finalize reads are always constrained to one branch.
 */
class StockOpnameRepository implements StockOpnameRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return StockOpname::query()
            ->with(['inventoryLocation', 'countedBy'])
            ->withCount('items')
            ->where('branch_id', $branchId)
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(opname_number) LIKE ?', [$term])
                        ->orWhereHas('inventoryLocation', fn ($l) => $l->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->when($filters['inventory_location_id'] ?? null, fn ($q, $v) => $q->where('inventory_location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('opname_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('opname_date', '<=', $v))
            ->orderByDesc('opname_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(array $data): StockOpname
    {
        return StockOpname::create($data);
    }

    public function update(StockOpname $opname, array $data): StockOpname
    {
        $opname->update($data);

        return $opname->refresh();
    }

    public function findById(int $branchId, int $id): ?StockOpname
    {
        return StockOpname::query()
            ->with(['inventoryLocation', 'countedBy', 'createdBy'])
            ->where('branch_id', $branchId)
            ->find($id);
    }

    public function loadItems(StockOpname $opname): StockOpname
    {
        return $opname->load(['items.product.unit']);
    }

    public function finalizeLookup(int $branchId, int $id): ?StockOpname
    {
        return StockOpname::query()
            ->with(['inventoryLocation', 'items.product.unit', 'items.product.category'])
            ->where('branch_id', $branchId)
            ->find($id);
    }
}
