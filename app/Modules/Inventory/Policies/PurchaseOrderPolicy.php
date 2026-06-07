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
        return $this->canViewPurchaseOrder($user);
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canViewPurchaseOrder($user)
            && $this->belongsToActiveBranch($purchaseOrder->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManagePurchaseOrder($user);
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canManagePurchaseOrder($user)
            && $this->belongsToActiveBranch($purchaseOrder->branch_id)
            && $purchaseOrder->isDraft();
    }

    public function submit(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canManagePurchaseOrder($user)
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
        return $this->canManagePurchaseOrder($user)
            && $this->belongsToActiveBranch($purchaseOrder->branch_id)
            && $purchaseOrder->isApproved();
    }

    public function cancel(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canManagePurchaseOrder($user)
            && $this->belongsToActiveBranch($purchaseOrder->branch_id)
            && ($purchaseOrder->isDraft() || $purchaseOrder->isSubmitted());
    }

    public function receive(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canManagePurchaseOrder($user)
            && $this->belongsToActiveBranch($purchaseOrder->branch_id)
            && in_array($purchaseOrder->status, [
                PurchaseOrder::STATUS_APPROVED,
                PurchaseOrder::STATUS_SENT,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ], true);
    }
}
