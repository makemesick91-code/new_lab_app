<?php

namespace App\Modules\AccessControl\Repositories;

use App\Modules\AccessControl\Interfaces\RoleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Data-access only for roles (PROJECT_RULES §9).
 */
class RoleRepository implements RoleRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return Role::query()
            ->withCount('permissions')
            ->with('permissions')
            ->when($search, fn ($query, $search) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listAll(): Collection
    {
        return Role::orderBy('name')->get();
    }

    public function findById(int $id): ?Role
    {
        return Role::with('permissions')->find($id);
    }

    public function create(array $data): Role
    {
        return Role::create([
            'name' => $data['name'],
            'guard_name' => $data['guard_name'] ?? 'web',
        ]);
    }

    public function update(Role $role, array $data): Role
    {
        $role->update(['name' => $data['name']]);

        return $role->refresh();
    }

    public function delete(Role $role): bool
    {
        return (bool) $role->delete();
    }

    public function syncPermissions(Role $role, array $permissionNames): Role
    {
        $role->syncPermissions($permissionNames);

        return $role->refresh();
    }
}
