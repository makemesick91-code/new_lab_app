<?php

namespace App\Modules\RmeInvoice\Policies;

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;

class RmeInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, RmeInvoice $invoice): bool
    {
        return $this->canManage($user) && $this->withinWorkingBranchScope($user, $invoice->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function pay(User $user, RmeInvoice $invoice): bool
    {
        return $this->canManage($user) && $this->withinWorkingBranchScope($user, $invoice->branch_id);
    }

    public function viewReceipt(User $user, RmeInvoice $invoice): bool
    {
        return $this->canManage($user) && $this->withinWorkingBranchScope($user, $invoice->branch_id);
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage_rme_billing');
    }

    /**
     * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 — the server-side branch boundary.
     *
     * Delegated to the canonical {@see RmeWorkingBranchScope}: a context-bound
     * operational role (Admin Klinik, Perawat, Kasir) may only reach records of
     * the branch it is currently working in, and fails closed when it has no
     * valid working context. Governance/cross-branch roles and the Doctor
     * clinical branch model keep the full active RME-enabled set, so this is a
     * narrowing of the previous rule, never a widening. Enforced here rather
     * than in the view, so a crafted URL or direct request is denied too.
     */
    private function withinWorkingBranchScope(User $user, ?int $branchId): bool
    {
        return app(RmeWorkingBranchScope::class)->allows($user, $branchId);
    }
}
