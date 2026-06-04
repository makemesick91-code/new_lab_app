<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Policies\Concerns\ChecksInventoryAccess;

class InventoryMovementPolicy
{
    use ChecksInventoryAccess;

    public function viewAny(User $user): bool
    {
        return $this->canViewInventory($user);
    }

    public function view(User $user, InventoryMovement $inventoryMovement): bool
    {
        return $this->canViewInventory($user)
            && $this->belongsToActiveBranch($inventoryMovement->branch_id)
            && $this->belongsToActiveBranch($inventoryMovement->inventoryLocation?->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManageInventory($user);
    }
}
