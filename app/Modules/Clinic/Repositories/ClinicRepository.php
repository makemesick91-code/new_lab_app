<?php

namespace App\Modules\Clinic\Repositories;

use App\Modules\Clinic\Interfaces\ClinicRepositoryInterface;
use App\Modules\Clinic\Models\Clinic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ClinicRepository implements ClinicRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return Clinic::query()
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(city) LIKE ?', [$term]);
                });
            })
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listAll(): Collection
    {
        return Clinic::where('is_active', true)->orderBy('name')->get();
    }

    public function findById(int $id): ?Clinic
    {
        return Clinic::find($id);
    }

    public function create(array $data): Clinic
    {
        return Clinic::create($data);
    }

    public function update(Clinic $clinic, array $data): Clinic
    {
        $clinic->update($data);

        return $clinic->refresh();
    }

    public function delete(Clinic $clinic): bool
    {
        return (bool) $clinic->delete();
    }

    public function setActiveStatus(Clinic $clinic, bool $isActive): Clinic
    {
        $clinic->update(['is_active' => $isActive]);

        return $clinic->refresh();
    }
}
