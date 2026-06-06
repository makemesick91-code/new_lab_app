<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Policies\Concerns\ChecksInventoryAccess;

class GoodsReceiptPolicy
{
    use ChecksInventoryAccess;

    public function viewAny(User $user): bool
    {
        return $this->canViewInventory($user)
            && $this->activeBranchId() !== null;
    }

    public function view(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $this->canViewInventory($user)
            && $this->belongsToActiveBranch($goodsReceipt->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManageInventory($user)
            && $this->activeBranchId() !== null;
    }

    public function update(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($goodsReceipt->branch_id)
            && $goodsReceipt->isDraft();
    }

    public function submit(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($goodsReceipt->branch_id)
            && $goodsReceipt->isDraft();
    }

    public function post(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($goodsReceipt->branch_id)
            && $goodsReceipt->posted_at === null
            && ! $goodsReceipt->isPosted()
            && ! $goodsReceipt->isCancelled()
            && $goodsReceipt->canBePosted();
    }

    public function cancel(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($goodsReceipt->branch_id)
            && $goodsReceipt->canBeCancelled()
            && ! $goodsReceipt->isPosted()
            && ! $goodsReceipt->isVoid()
            && ! $goodsReceipt->isCancelled();
    }

    public function void(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $this->canManageInventory($user)
            && $this->belongsToActiveBranch($goodsReceipt->branch_id)
            && $goodsReceipt->canBeVoided()
            && ! $goodsReceipt->isVoid()
            && ! $goodsReceipt->isCancelled();
    }
}
