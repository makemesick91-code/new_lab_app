<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Policies\Concerns\ChecksInventoryAccess;

class StockOpnamePolicy
{
    use ChecksInventoryAccess;

    public function viewAny(User $user): bool
    {
        return $this->canViewStockOpname($user);
    }

    public function view(User $user, StockOpname $stockOpname): bool
    {
        return $this->canViewStockOpname($user)
            && $this->belongsToActiveBranch($stockOpname->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManageStockOpname($user);
    }

    public function update(User $user, StockOpname $stockOpname): bool
    {
        return $this->canManageStockOpname($user)
            && $this->belongsToActiveBranch($stockOpname->branch_id);
    }

    public function delete(User $user, StockOpname $stockOpname): bool
    {
        return $this->canManageStockOpname($user)
            && $this->belongsToActiveBranch($stockOpname->branch_id);
    }

    public function review(User $user, StockOpname $stockOpname): bool
    {
        return $this->canManageStockOpname($user)
            && $this->belongsToActiveBranch($stockOpname->branch_id);
    }

    public function finalize(User $user, StockOpname $stockOpname): bool
    {
        return $this->canManageStockOpname($user)
            && $this->belongsToActiveBranch($stockOpname->branch_id);
    }

    public function cancel(User $user, StockOpname $stockOpname): bool
    {
        return $this->canManageStockOpname($user)
            && $this->belongsToActiveBranch($stockOpname->branch_id);
    }
}
