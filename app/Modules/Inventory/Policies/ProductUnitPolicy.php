<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Policies\Concerns\ChecksInventoryAccess;

class ProductUnitPolicy
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

    public function update(User $user, ProductUnit $productUnit): bool
    {
        return $this->canManageInventory($user);
    }

    public function delete(User $user, ProductUnit $productUnit): bool
    {
        return $this->canManageInventory($user);
    }
}
