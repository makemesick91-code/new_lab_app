<?php

namespace App\Modules\Branch\Policies;

use App\Models\User;
use App\Modules\Branch\Models\Branch;

/**
 * Sprint 9 — Multi Branch Foundation (SKELETON).
 *
 * Authorization surface for branch administration. Abilities gate on a
 * `manage branches` permission (to be added in a future sprint's PermissionSeeder).
 * Super Admin bypasses every ability via Gate::before in RepositoryServiceProvider.
 *
 * NOTE: This is foundation only. No route currently authorizes against it, so
 * registering it changes no runtime behavior. Branch-scoped data visibility
 * (a user only seeing their branch's records) is a SEPARATE future concern —
 * see the branch-scoping TODOs in the transaction repositories.
 */
class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage branches');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->can('manage branches');
    }

    public function create(User $user): bool
    {
        return $user->can('manage branches');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->can('manage branches');
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->can('manage branches');
    }
}
