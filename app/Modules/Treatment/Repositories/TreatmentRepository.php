<?php

namespace App\Modules\Treatment\Repositories;

use App\Modules\Treatment\Interfaces\TreatmentRepositoryInterface;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TreatmentRepository implements TreatmentRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return Treatment::query()
            ->with('category')
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                });
            })
            ->when($filters['treatment_category_id'] ?? null, fn ($q, $v) => $q->where('treatment_category_id', $v))
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null,
                fn ($q) => $q->where('is_active', $filters['is_active']))
            ->when(array_key_exists('requires_lab', $filters) && $filters['requires_lab'] !== null,
                fn ($q) => $q->where('requires_lab', $filters['requires_lab']))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listActive(): Collection
    {
        return Treatment::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function create(array $data): Treatment
    {
        return Treatment::create($data);
    }

    public function update(Treatment $treatment, array $data): Treatment
    {
        $treatment->update($data);

        return $treatment->refresh();
    }

    public function delete(Treatment $treatment): bool
    {
        return (bool) $treatment->delete();
    }
}
