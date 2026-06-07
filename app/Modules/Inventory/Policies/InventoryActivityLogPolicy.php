<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryActivityLog;
use App\Modules\Inventory\Policies\Concerns\ChecksInventoryAccess;

class InventoryActivityLogPolicy
{
    use ChecksInventoryAccess;

    public function viewAny(User $user): bool
    {
        return $this->canViewInventoryActivityLog($user);
    }

    public function view(User $user, InventoryActivityLog $inventoryActivityLog): bool
    {
        return $this->canViewInventoryActivityLog($user)
            && $this->belongsToActiveBranch($inventoryActivityLog->branch_id);
    }

    protected function canViewInventoryActivityLog(User $user): bool
    {
        return $user->canAny([
            'view_inventory_activity_log',
            'view_inventory',
            'manage_inventory',
            'view_inventory_analytics',
            'manage master data',
        ]);
    }
}
