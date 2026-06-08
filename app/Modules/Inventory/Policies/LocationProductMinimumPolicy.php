<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\LocationProductMinimum;
use App\Modules\Inventory\Policies\Concerns\ChecksInventoryAccess;

class LocationProductMinimumPolicy
{
    use ChecksInventoryAccess;

    public function viewAny(User $user): bool
    {
        return $this->canViewInventory($user);
    }

    public function view(User $user, LocationProductMinimum $locationProductMinimum): bool
    {
        return $this->canViewInventory($user)
            && $this->belongsToActiveBranch($locationProductMinimum->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManageInventory($user);
    }

    public function update(User $user, LocationProductMinimum $locationProductMinimum): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($locationProductMinimum->branch_id);
    }

    public function delete(User $user, LocationProductMinimum $locationProductMinimum): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($locationProductMinimum->branch_id);
    }
}
