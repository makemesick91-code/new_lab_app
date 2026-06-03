<?php

namespace App\Modules\AccessControl\Services;

use App\Modules\AccessControl\Interfaces\RoleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Business logic for role & permission-to-role management (PROJECT_RULES §8).
 */
class RoleService
{
    /**
     * Roles that must never be deleted to keep the system operable.
     */
    private const PROTECTED_ROLES = ['Super Admin'];

    public function __construct(
        private readonly RoleRepositoryInterface $roles,
    ) {}

    public function list(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->roles->paginate($filters, $perPage);
    }

    public function listAll(): Collection
    {
        return $this->roles->listAll();
    }

    public function find(int $id): ?Role
    {
        return $this->roles->findById($id);
    }

    /**
     * @param  array<string, mixed>  $data  validated, may contain "permissions" (array of names)
     */
    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $role = $this->roles->create($data);

            if (! empty($data['permissions'])) {
                $this->roles->syncPermissions($role, $data['permissions']);
            }

            return $role;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated, may contain "permissions" (array of names)
     */
    public function update(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data) {
            $role = $this->roles->update($role, $data);

            // "permissions" key present (even if empty) means "sync to this set".
            if (array_key_exists('permissions', $data)) {
                $this->roles->syncPermissions($role, $data['permissions'] ?? []);
            }

            return $role;
        });
    }

    public function delete(Role $role): bool
    {
        if (in_array($role->name, self::PROTECTED_ROLES, true)) {
            throw ValidationException::withMessages([
                'role' => "The {$role->name} role cannot be deleted.",
            ]);
        }

        return DB::transaction(fn () => $this->roles->delete($role));
    }

    /**
     * Assign / sync a set of permissions to a role (TASK-0103).
     *
     * @param  array<int, string>  $permissionNames
     */
    public function syncPermissions(Role $role, array $permissionNames): Role
    {
        return DB::transaction(fn () => $this->roles->syncPermissions($role, $permissionNames));
    }
}
