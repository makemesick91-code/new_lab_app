<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Policies\Concerns\ChecksInventoryAccess;

class StockTransferPolicy
{
    use ChecksInventoryAccess;

    public function viewAny(User $user): bool
    {
        return $this->canViewInventory($user);
    }

    public function view(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->canViewInventory($user)
            && $this->belongsToActiveBranch($stockTransfer->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManageInventory($user);
    }

    public function update(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($stockTransfer->branch_id);
    }

    public function delete(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($stockTransfer->branch_id);
    }

    public function submit(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($stockTransfer->branch_id);
    }

    public function complete(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($stockTransfer->branch_id);
    }

    public function cancel(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($stockTransfer->branch_id);
    }
}
