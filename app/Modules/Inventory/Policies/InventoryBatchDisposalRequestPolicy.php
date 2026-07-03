<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryBatchDisposalRequest;
use App\Modules\Inventory\Policies\Concerns\ChecksInventoryAccess;

class InventoryBatchDisposalRequestPolicy
{
    use ChecksInventoryAccess;

    public function viewAny(User $user): bool
    {
        return $this->canViewInventoryBatchLot($user);
    }

    public function view(User $user, InventoryBatchDisposalRequest $request): bool
    {
        return $this->canViewInventoryBatchLot($user)
            && $this->belongsToActiveBranch($request->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManageInventoryBatchLot($user);
    }

    public function createForBatch(User $user, InventoryBatch $batch): bool
    {
        return $this->canManageInventoryBatchLot($user)
            && $this->belongsToActiveBranch($batch->branch_id);
    }

    public function approve(User $user, InventoryBatchDisposalRequest $request): bool
    {
        return $this->canApproveInventoryAdjustment($user)
            && $this->belongsToActiveBranch($request->branch_id)
            && $request->canApprove();
    }

    public function reject(User $user, InventoryBatchDisposalRequest $request): bool
    {
        return $this->canApproveInventoryAdjustment($user)
            && $this->belongsToActiveBranch($request->branch_id)
            && $request->canReject();
    }

    public function finalizeAdjustment(User $user, InventoryBatchDisposalRequest $request): bool
    {
        return $this->canApproveInventoryAdjustment($user)
            && $this->belongsToActiveBranch($request->branch_id)
            && $request->canFinalize();
    }

    public function cancel(User $user, InventoryBatchDisposalRequest $request): bool
    {
        return $this->canManageInventoryBatchLot($user)
            && $this->belongsToActiveBranch($request->branch_id)
            && $request->canCancel();
    }
}
