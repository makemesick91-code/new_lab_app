<?php

namespace App\Modules\Inventory\Policies\Concerns;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;

trait ChecksInventoryAccess
{
    protected function canViewInventory(User $user): bool
    {
        return $user->canAny(['view_inventory', 'manage_inventory', 'manage master data']);
    }

    protected function canManageInventory(User $user): bool
    {
        return $user->canAny(['manage_inventory', 'manage master data']);
    }

    protected function activeBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

    protected function belongsToActiveBranch(?int $branchId): bool
    {
        $activeBranchId = $this->activeBranchId();

        return $activeBranchId !== null && $branchId === $activeBranchId;
    }
}
