<?php

namespace App\Modules\User\Policies;

use App\Models\User;

/**
 * Authorization for user management. Gated by the "manage users" permission.
 * Super Admin bypasses all checks via Gate::before (see RepositoryServiceProvider).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage users');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('manage users');
    }

    public function create(User $user): bool
    {
        return $user->can('manage users');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('manage users');
    }

    public function delete(User $user, User $model): bool
    {
        // A user may not delete their own account from the admin panel.
        return $user->can('manage users') && $user->id !== $model->id;
    }
}
