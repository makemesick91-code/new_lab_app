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
        return $this->canViewStockTransfer($user);
    }

    public function view(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->canViewStockTransfer($user)
            && $this->belongsToActiveBranch($stockTransfer->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManageStockTransfer($user);
    }

    public function update(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->canManageStockTransfer($user)
            && $this->belongsToActiveBranch($stockTransfer->branch_id);
    }

    public function delete(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->canManageStockTransfer($user)
            && $this->belongsToActiveBranch($stockTransfer->branch_id);
    }

    public function submit(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->canManageStockTransfer($user)
            && $this->belongsToActiveBranch($stockTransfer->branch_id);
    }

    public function ship(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->canManageStockTransfer($user)
            && $this->belongsToActiveBranch($stockTransfer->branch_id);
    }

    public function receive(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->canManageStockTransfer($user)
            && $this->belongsToActiveBranch($stockTransfer->branch_id);
    }

    public function cancel(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->canManageStockTransfer($user)
            && $this->belongsToActiveBranch($stockTransfer->branch_id);
    }
}
