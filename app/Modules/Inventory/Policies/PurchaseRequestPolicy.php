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
        return $this->canViewPurchaseRequest($user);
    }

    public function view(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->canViewPurchaseRequest($user)
            && $this->belongsToActiveBranch($purchaseRequest->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManagePurchaseRequest($user);
    }

    public function update(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->canManagePurchaseRequest($user)
            && $this->belongsToActiveBranch($purchaseRequest->branch_id)
            && $purchaseRequest->isDraft();
    }

    public function submit(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->canManagePurchaseRequest($user)
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
        return $this->canManagePurchaseRequest($user)
            && $this->belongsToActiveBranch($purchaseRequest->branch_id)
            && ($purchaseRequest->isDraft() || $purchaseRequest->isSubmitted());
    }
}
