<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Policies;

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionPermission;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — operational correction
 * authorization (the cashier / approver surfaces).
 *
 * This policy is the SECOND of three layers (route middleware → policy →
 * service). It is not the control for maker != checker or for the lifecycle:
 * Gate::before lets Super Admin pass every ability here, so the service repeats
 * permission, branch scope and maker != checker under the invoice row lock. The
 * self-decision clauses below exist so a non-Super-Admin maker never even sees
 * an approve/reject button on their own request.
 *
 * Branch scope is the canonical RmeWorkingBranchScope (context-bound roles are
 * pinned to their working branch and fail closed without one). The read-only
 * oversight roles use ClinicOversightScope through the oversight module instead.
 */
class RmeInvoiceCorrectionPolicy
{
    public function __construct(private readonly RmeWorkingBranchScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $this->holdsAny($user);
    }

    public function view(User $user, RmeInvoiceCorrection $correction): bool
    {
        return $this->holdsAny($user) && $this->scope->allows($user, (int) $correction->branch_id);
    }

    public function create(User $user, RmeInvoice $invoice): bool
    {
        return $user->can(RmeInvoiceCorrectionPermission::REQUEST)
            && $this->scope->allows($user, (int) $invoice->branch_id);
    }

    public function approve(User $user, RmeInvoiceCorrection $correction): bool
    {
        return $this->decides($user, $correction);
    }

    public function reject(User $user, RmeInvoiceCorrection $correction): bool
    {
        return $this->decides($user, $correction);
    }

    public function cancel(User $user, RmeInvoiceCorrection $correction): bool
    {
        return $user->can(RmeInvoiceCorrectionPermission::REQUEST)
            && $this->scope->allows($user, (int) $correction->branch_id)
            && $correction->isRequestedBy($user);
    }

    public function apply(User $user, RmeInvoiceCorrection $correction): bool
    {
        return $user->can(RmeInvoiceCorrectionPermission::APPLY)
            && $this->scope->allows($user, (int) $correction->branch_id);
    }

    public function viewDocumentVersion(User $user, RmeInvoiceDocumentVersion $version): bool
    {
        return $this->holdsAny($user) && $this->scope->allows($user, (int) $version->branch_id);
    }

    private function decides(User $user, RmeInvoiceCorrection $correction): bool
    {
        return $user->can(RmeInvoiceCorrectionPermission::APPROVE)
            && $this->scope->allows($user, (int) $correction->branch_id)
            && ! $correction->isRequestedBy($user);
    }

    private function holdsAny(User $user): bool
    {
        foreach (RmeInvoiceCorrectionPermission::OPERATIONAL as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
