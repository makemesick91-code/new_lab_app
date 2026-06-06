<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Policies\Concerns\ChecksInventoryAccess;

class PurchaseOrderPolicy
{
    use ChecksInventoryAccess;

    public function viewAny(User $user): bool
    {
        return $this->canViewInventory($user);
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canViewInventory($user)
            && $this->belongsToActiveBranch($purchaseOrder->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManageInventory($user);
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($purchaseOrder->branch_id)
            && $purchaseOrder->isDraft();
    }

    public function submit(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($purchaseOrder->branch_id)
            && $purchaseOrder->isDraft();
    }

    public function approve(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canApprovePurchaseOrder($user)
            && $this->belongsToActiveBranch($purchaseOrder->branch_id)
            && $purchaseOrder->isSubmitted();
    }

    public function send(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($purchaseOrder->branch_id)
            && $purchaseOrder->isApproved();
    }

    public function cancel(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($purchaseOrder->branch_id)
            && ($purchaseOrder->isDraft() || $purchaseOrder->isSubmitted());
    }

    protected function canApprovePurchaseOrder(User $user): bool
    {
        return $user->canAny(['approve_inventory_purchase_order', 'manage_inventory', 'manage master data']);
    }
}
