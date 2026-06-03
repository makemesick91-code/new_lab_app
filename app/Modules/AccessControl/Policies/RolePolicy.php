<?php

namespace App\Modules\AccessControl\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Authorization for role management. Gated by the "manage roles" permission.
 * Super Admin bypasses all checks via Gate::before.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage roles');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('manage roles');
    }

    public function create(User $user): bool
    {
        return $user->can('manage roles');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('manage roles');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('manage roles');
    }
}
