<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Policies;

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeReviewerService;
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
 *
 * Rule 204 (OPS-OQ-1a): an ACTIVE branch reviewer designation for the dispute's
 * OWN branch authorizes view, note, review, escalate and resolve ALONGSIDE the
 * permissions — always ANDed with the working scope. It never authorizes
 * reporting (a cashier act) or any other branch. viewAny / noteAny / reviewAny
 * are the instance-free doors the route middleware asks.
 */
class RmeBillingDisputePolicy
{
    public function __construct(
        private readonly RmeWorkingBranchScope $scope,
        private readonly RmeBillingDisputeReviewerService $reviewers,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->holdsAny($user, RmeBillingDisputePermission::READ)
            || $this->reviewers->hasAnyActiveDesignation($user);
    }

    /** Route door of the note endpoint. */
    public function noteAny(User $user): bool
    {
        return $this->holdsAny($user, RmeBillingDisputePermission::NOTE)
            || $this->reviewers->hasAnyActiveDesignation($user);
    }

    /** Route door of review / escalate / resolve. */
    public function reviewAny(User $user): bool
    {
        return $user->can(RmeBillingDisputePermission::REVIEW)
            || $this->reviewers->hasAnyActiveDesignation($user);
    }

    public function view(User $user, RmeBillingDispute $dispute): bool
    {
        return $this->scope->allows($user, (int) $dispute->branch_id)
            && ($this->holdsAny($user, RmeBillingDisputePermission::READ)
                || $this->designatedFor($user, $dispute));
    }

    public function create(User $user, RmeInvoice $invoice): bool
    {
        return $user->can(RmeBillingDisputePermission::REPORT)
            && $this->scope->allows($user, (int) $invoice->branch_id);
    }

    public function addNote(User $user, RmeBillingDispute $dispute): bool
    {
        return $dispute->isActive()
            && $this->scope->allows($user, (int) $dispute->branch_id)
            && ($this->holdsAny($user, RmeBillingDisputePermission::NOTE)
                || $this->designatedFor($user, $dispute));
    }

    public function review(User $user, RmeBillingDispute $dispute): bool
    {
        return $dispute->isActive()
            && $this->scope->allows($user, (int) $dispute->branch_id)
            && ($user->can(RmeBillingDisputePermission::REVIEW)
                || $this->designatedFor($user, $dispute));
    }

    public function escalate(User $user, RmeBillingDispute $dispute): bool
    {
        return $this->review($user, $dispute);
    }

    public function resolve(User $user, RmeBillingDispute $dispute): bool
    {
        return $this->review($user, $dispute);
    }

    private function designatedFor(User $user, RmeBillingDispute $dispute): bool
    {
        return $this->reviewers->isDesignatedFor($user, (int) $dispute->branch_id);
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
