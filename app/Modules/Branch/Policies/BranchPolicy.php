<?php

namespace App\Modules\Branch\Policies;

use App\Models\User;
use App\Modules\Branch\Models\Branch;

/**
 * Authorization surface for Master Data Cabang (Sprint 23 Phase 23.7).
 *
 * Read abilities gate on `view_branch_master_data`; write abilities require
 * `manage_branch_master_data`. Super Admin bypasses every ability via
 * Gate::before in RepositoryServiceProvider.
 *
 * Master Data Cabang serves the multi-branch modules (RME + Inventory) only.
 * Laboratory is single / global and is not represented here.
 */
class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Branch $branch): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Branch $branch): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $this->canManage($user);
    }

    private function canView(User $user): bool
    {
        return $user->canAny([
            'view_branch_master_data',
            'manage_branch_master_data',
        ]);
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage_branch_master_data');
    }
}
