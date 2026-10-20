<?php

namespace App\Modules\RmeInvoice\Policies;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
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

    /**
     * REVISION-RME-MANUAL-PRICING-AUTHORITY-1 (security review F1) — issuing an
     * invoice is bounded by the working branch exactly like view / pay /
     * viewReceipt. Before this, `create` checked only the permission, so a Kasir
     * working at branch A could open and issue the invoice of a branch-B visit at
     * prices they typed — and under manual pricing the typed price IS the
     * authoritative financial snapshot. The service re-checks the same scope on
     * the locked visit; this is the HTTP door.
     */
    public function create(User $user, ?ClinicVisit $visit = null): bool
    {
        if (! $this->canManage($user)) {
            return false;
        }

        return $visit === null || $this->withinWorkingBranchScope($user, $visit->branch_id);
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
