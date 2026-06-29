<?php

namespace App\Modules\Doctor\Repositories;

use App\Modules\Doctor\Interfaces\DoctorRepositoryInterface;
use App\Modules\Doctor\Models\Doctor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class DoctorRepository implements DoctorRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;
        $branchId = $filters['branch_id'] ?? null;

        return Doctor::query()
            ->with(['branch', 'clinic'])
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                });
            })
            ->when($branchId, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listAll(?int $branchId = null): Collection
    {
        return Doctor::query()
            ->where('is_active', true)
            ->when($branchId, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->orderBy('name')
            ->get();
    }

    public function findById(int $id): ?Doctor
    {
        return Doctor::with(['branch', 'clinic'])->find($id);
    }

    public function create(array $data): Doctor
    {
        return Doctor::create($data);
    }

    public function update(Doctor $doctor, array $data): Doctor
    {
        $doctor->update($data);

        return $doctor->refresh();
    }

    public function delete(Doctor $doctor): bool
    {
        return (bool) $doctor->delete();
    }

    public function setActiveStatus(Doctor $doctor, bool $isActive): Doctor
    {
        $doctor->update(['is_active' => $isActive]);

        return $doctor->refresh();
    }
}
