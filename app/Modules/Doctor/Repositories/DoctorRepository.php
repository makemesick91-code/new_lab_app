<?php

namespace App\Modules\Doctor\Repositories;

use App\Models\User;
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
            ->with(['branches', 'branch', 'clinic'])
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                });
            })
            ->when($branchId, fn ($query, $branchId) => $query->whereHas(
                'branches',
                fn ($branchQuery) => $branchQuery->where('mst_branches.id', $branchId),
            ))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listAll(?int $branchId = null): Collection
    {
        return Doctor::query()
            ->where('is_active', true)
            ->when($branchId, fn ($query, $branchId) => $query->whereHas(
                'branches',
                fn ($branchQuery) => $branchQuery->where('mst_branches.id', $branchId),
            ))
            ->orderBy('name')
            ->get();
    }

    public function findById(int $id): ?Doctor
    {
        return Doctor::with(['branches', 'branch', 'clinic'])->find($id);
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

    public function syncAllowedBranches(Doctor $doctor, array $branchIds): void
    {
        $doctor->branches()->sync(
            collect($branchIds)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all(),
        );
    }

    /**
     * FEATURE-DOCTOR-ACCOUNT-PERFORMANCE-INCOME-LINKAGE-1
     *
     * The doctor list for the account-link screen. `user` is eager loaded so the
     * table can render the linked account without an N+1.
     */
    public function paginateWithLinkedAccount(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;
        $linkStatus = $filters['link_status'] ?? null;

        return Doctor::query()
            ->with(['user'])
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                });
            })
            ->when($linkStatus === 'linked', fn ($query) => $query->whereNotNull('user_id'))
            ->when($linkStatus === 'unlinked', fn ($query) => $query->whereNull('user_id'))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForUpdate(int $id): ?Doctor
    {
        return Doctor::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function findLinkedByUserId(int $userId, ?int $excludeDoctorId = null): ?Doctor
    {
        return Doctor::query()
            ->where('user_id', $userId)
            ->when($excludeDoctorId, fn ($query, $excludeDoctorId) => $query->whereKeyNot($excludeDoctorId))
            ->first();
    }

    public function setLinkedUser(Doctor $doctor, ?int $userId): Doctor
    {
        $doctor->user_id = $userId;
        $doctor->save();

        return $doctor->fresh(['user']);
    }

    /**
     * Candidate accounts for linking. The `mst_doctors.user_id` column is owned
     * by this module, so the "already linked" exclusion is resolved here rather
     * than leaking doctor-linkage knowledge into the User module.
     */
    public function linkableUserCandidates(string $role): Collection
    {
        $linkedUserIds = Doctor::query()
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return User::query()
            ->where('is_active', true)
            ->whereNotIn('id', $linkedUserIds)
            ->whereHas('roles', fn ($query) => $query->where('name', $role))
            ->orderBy('name')
            ->get();
    }
}
