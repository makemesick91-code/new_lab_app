<?php

namespace App\Modules\Tariff\Repositories;

use App\Modules\Tariff\Interfaces\TariffRepositoryInterface;
use App\Modules\Tariff\Models\Tariff;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TariffRepository implements TariffRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return Tariff::query()
            ->with(['treatment.category', 'branch'])
            ->where('branch_id', $branchId)
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->whereHas('treatment', function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                });
            })
            ->when($filters['treatment_id'] ?? null, fn ($q, $v) => $q->where('treatment_id', $v))
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null,
                fn ($q) => $q->where('is_active', $filters['is_active']))
            ->when(array_key_exists('requires_lab', $filters) && $filters['requires_lab'] !== null,
                fn ($q) => $q->whereHas('treatment', fn ($t) => $t->where('requires_lab', $filters['requires_lab'])))
            ->orderByDesc('effective_date')
            ->orderBy('treatment_id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findInBranch(int $branchId, int $id): ?Tariff
    {
        return Tariff::query()
            ->where('branch_id', $branchId)
            ->find($id);
    }

    public function create(array $data): Tariff
    {
        return Tariff::create($data);
    }

    public function update(Tariff $tariff, array $data): Tariff
    {
        $tariff->update($data);

        return $tariff->refresh();
    }

    public function delete(Tariff $tariff): bool
    {
        return (bool) $tariff->delete();
    }
}
