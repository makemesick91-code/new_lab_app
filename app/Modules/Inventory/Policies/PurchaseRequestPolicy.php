<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Policies\Concerns\ChecksInventoryAccess;

class PurchaseRequestPolicy
{
    use ChecksInventoryAccess;

    public function viewAny(User $user): bool
    {
        return $this->canViewInventory($user);
    }

    public function view(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->canViewInventory($user)
            && $this->belongsToActiveBranch($purchaseRequest->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManageInventory($user);
    }

    public function update(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($purchaseRequest->branch_id)
            && $purchaseRequest->isDraft();
    }

    public function submit(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($purchaseRequest->branch_id)
            && $purchaseRequest->isDraft();
    }

    public function approve(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->canApprovePurchaseRequest($user)
            && $this->belongsToActiveBranch($purchaseRequest->branch_id)
            && $purchaseRequest->isSubmitted();
    }

    public function reject(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->canApprovePurchaseRequest($user)
            && $this->belongsToActiveBranch($purchaseRequest->branch_id)
            && $purchaseRequest->isSubmitted();
    }

    public function cancel(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($purchaseRequest->branch_id)
            && ($purchaseRequest->isDraft() || $purchaseRequest->isSubmitted());
    }

    protected function canApprovePurchaseRequest(User $user): bool
    {
        return $user->canAny(['approve_inventory_purchase_request', 'manage_inventory', 'manage master data']);
    }
}
