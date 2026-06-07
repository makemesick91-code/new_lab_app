<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Policies\Concerns\ChecksInventoryAccess;

class ProductCategoryPolicy
{
    use ChecksInventoryAccess;

    public function viewAny(User $user): bool
    {
        return $this->canViewInventory($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageInventory($user);
    }

    public function update(User $user, ProductCategory $productCategory): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($productCategory->branch_id);
    }

    public function delete(User $user, ProductCategory $productCategory): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($productCategory->branch_id);
    }
}
