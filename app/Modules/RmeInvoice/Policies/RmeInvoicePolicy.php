<?php

namespace App\Modules\RmeInvoice\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\RmeInvoice\Models\RmeInvoice;

class RmeInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, RmeInvoice $invoice): bool
    {
        return $this->canManage($user) && $this->belongsToActiveBranch($invoice->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function pay(User $user, RmeInvoice $invoice): bool
    {
        return $this->canManage($user) && $this->belongsToActiveBranch($invoice->branch_id);
    }

    public function viewReceipt(User $user, RmeInvoice $invoice): bool
    {
        return $this->canManage($user) && $this->belongsToActiveBranch($invoice->branch_id);
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage_rme_billing');
    }

    private function belongsToActiveBranch(int $branchId): bool
    {
        $activeBranchId = app(BranchContext::class)->id();

        return $activeBranchId !== null && $activeBranchId === $branchId;
    }
}
