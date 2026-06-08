<?php

namespace App\Modules\TreatmentCategory\Repositories;

use App\Modules\TreatmentCategory\Interfaces\TreatmentCategoryRepositoryInterface;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TreatmentCategoryRepository implements TreatmentCategoryRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return TreatmentCategory::query()
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                });
            })
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null,
                fn ($q) => $q->where('is_active', $filters['is_active']))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listActive(): Collection
    {
        return TreatmentCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function create(array $data): TreatmentCategory
    {
        return TreatmentCategory::create($data);
    }

    public function update(TreatmentCategory $category, array $data): TreatmentCategory
    {
        $category->update($data);

        return $category->refresh();
    }

    public function delete(TreatmentCategory $category): bool
    {
        return (bool) $category->delete();
    }
}
