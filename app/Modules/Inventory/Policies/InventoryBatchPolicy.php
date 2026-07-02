<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Policies\Concerns\ChecksInventoryAccess;

class InventoryBatchPolicy
{
    use ChecksInventoryAccess;

    public function viewAny(User $user): bool
    {
        return $this->canViewInventoryBatchLot($user);
    }

    public function view(User $user, InventoryBatch $inventoryBatch): bool
    {
        return $this->canViewInventoryBatchLot($user)
            && $this->belongsToActiveBranch($inventoryBatch->branch_id);
    }

    public function recordAction(User $user, InventoryBatch $inventoryBatch): bool
    {
        return $this->canManageInventoryBatchLot($user)
            && $this->belongsToActiveBranch($inventoryBatch->branch_id);
    }
}
