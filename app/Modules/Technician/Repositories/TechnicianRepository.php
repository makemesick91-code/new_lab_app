<?php

namespace App\Modules\Technician\Repositories;

use App\Modules\Technician\Interfaces\TechnicianRepositoryInterface;
use App\Modules\Technician\Models\Technician;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TechnicianRepository implements TechnicianRepositoryInterface
{
    public function listAll(): Collection
    {
        return Technician::query()->where('is_active', true)->orderBy('name')->get();
    }

    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return Technician::query()
            ->with('user')
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(specialization) LIKE ?', [$term]);
                });
            })
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findById(int $id): ?Technician
    {
        return Technician::with('user')->find($id);
    }

    public function create(array $data): Technician
    {
        return Technician::create($data);
    }

    public function update(Technician $technician, array $data): Technician
    {
        $technician->update($data);

        return $technician->refresh();
    }

    public function delete(Technician $technician): bool
    {
        return (bool) $technician->delete();
    }

    public function setActiveStatus(Technician $technician, bool $isActive): Technician
    {
        $technician->update(['is_active' => $isActive]);

        return $technician->refresh();
    }
}
