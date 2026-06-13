<?php

namespace App\Modules\RmeInvoice\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\RmeInvoice\Models\RmeInvoice;

class RmeInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, RmeInvoice $invoice): bool
    {
        return $this->canManage($user) && $this->belongsToActiveRmeBranch($invoice->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function pay(User $user, RmeInvoice $invoice): bool
    {
        return $this->canManage($user) && $this->belongsToActiveRmeBranch($invoice->branch_id);
    }

    public function viewReceipt(User $user, RmeInvoice $invoice): bool
    {
        return $this->canManage($user) && $this->belongsToActiveRmeBranch($invoice->branch_id);
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage_rme_billing');
    }

    /**
     * RME billing is scoped to the operational "Cabang RME" set (active
     * RME-enabled branches), mirroring ClinicVisitPolicy. This avoids the single
     * BranchContext/MAIN fallback that would otherwise deny every RME invoice in
     * the pilot (MAIN is not RME-enabled). Sprint 23 Phase 23.10.
     */
    private function belongsToActiveRmeBranch(?int $branchId): bool
    {
        if ($branchId === null) {
            return false;
        }

        return in_array($branchId, app(BranchService::class)->rmeEnabledIds(), true);
    }
}
