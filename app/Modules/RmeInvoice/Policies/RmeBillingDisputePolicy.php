<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Policies;

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Support\RmeBillingDisputePermission;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;

/**
 * Rule 203 — billing dispute authorization at the HTTP door.
 *
 * The SECOND of three layers (route middleware → policy → service). It decides
 * what a page shows and which out-of-scope requests 404 before touching data.
 * It is never the guarantee: Gate::before lets Super Admin pass every ability
 * here, so RmeBillingDisputeService repeats usability, permission, branch scope
 * and self-resolution under the invoice row lock for every account.
 *
 * Branch scope is the canonical RmeWorkingBranchScope, always against the
 * dispute's / invoice's OWN branch — never a request field. Context-bound roles
 * (Front Office, Kasir, Admin Klinik, Perawat) are pinned to their working
 * branch and fail closed without one.
 */
class RmeBillingDisputePolicy
{
    public function __construct(private readonly RmeWorkingBranchScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $this->holdsAny($user, RmeBillingDisputePermission::READ);
    }

    public function view(User $user, RmeBillingDispute $dispute): bool
    {
        return $this->holdsAny($user, RmeBillingDisputePermission::READ)
            && $this->scope->allows($user, (int) $dispute->branch_id);
    }

    public function create(User $user, RmeInvoice $invoice): bool
    {
        return $user->can(RmeBillingDisputePermission::REPORT)
            && $this->scope->allows($user, (int) $invoice->branch_id);
    }

    public function addNote(User $user, RmeBillingDispute $dispute): bool
    {
        return $dispute->isActive()
            && $this->holdsAny($user, RmeBillingDisputePermission::NOTE)
            && $this->scope->allows($user, (int) $dispute->branch_id);
    }

    public function review(User $user, RmeBillingDispute $dispute): bool
    {
        return $dispute->isActive()
            && $user->can(RmeBillingDisputePermission::REVIEW)
            && $this->scope->allows($user, (int) $dispute->branch_id);
    }

    public function escalate(User $user, RmeBillingDispute $dispute): bool
    {
        return $this->review($user, $dispute);
    }

    public function resolve(User $user, RmeBillingDispute $dispute): bool
    {
        return $this->review($user, $dispute);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function holdsAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
