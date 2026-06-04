<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Policies\Concerns\ChecksInventoryAccess;

class InventoryLocationPolicy
{
    use ChecksInventoryAccess;

    public function viewAny(User $user): bool
    {
        return $this->canViewInventory($user);
    }

    public function view(User $user, InventoryLocation $inventoryLocation): bool
    {
        return $this->canViewInventory($user)
            && $this->belongsToActiveBranch($inventoryLocation->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManageInventory($user);
    }

    public function update(User $user, InventoryLocation $inventoryLocation): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($inventoryLocation->branch_id);
    }

    public function delete(User $user, InventoryLocation $inventoryLocation): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($inventoryLocation->branch_id);
    }
}
