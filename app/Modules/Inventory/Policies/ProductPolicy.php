<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Policies\Concerns\ChecksInventoryAccess;

class ProductPolicy
{
    use ChecksInventoryAccess;

    public function viewAny(User $user): bool
    {
        return $this->canViewInventory($user);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->canViewInventory($user)
            && $this->belongsToActiveBranch($product->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManageInventory($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($product->branch_id);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($product->branch_id);
    }
}
