<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Policies\Concerns\ChecksInventoryAccess;

class SupplierPolicy
{
    use ChecksInventoryAccess;

    public function viewAny(User $user): bool
    {
        return $this->canViewInventory($user);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->canViewInventory($user)
            && $this->belongsToActiveBranch($supplier->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManageInventory($user);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($supplier->branch_id);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($supplier->branch_id);
    }
}
