<?php

declare(strict_types=1);

namespace App\Modules\RmeOnlineContext\Policies;

use App\Models\User;
use App\Modules\RmeOnlineContext\Models\BranchChangeRequest;
use App\Modules\RmeOnlineContext\Services\BranchChangeApprovalService;
use App\Modules\RmeOnlineContext\Services\DailyBranchContextService;

/**
 * FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — who may see, file and decide a
 * working-branch change request.
 *
 * NO NEW PERMISSION IS INTRODUCED. Approval authority is the canonical Super
 * Admin role, checked explicitly here and mirrored by the
 * `branch-change-request.approve` gate that the route group and the sidebar both
 * consume — the same shape as the existing `satusehat.access` gate, so the menu
 * and the server-side boundary cannot drift apart.
 *
 * The explicit `hasRole('Super Admin')` is not redundant with `Gate::before`.
 * `Gate::before` would let a Super Admin through a policy that returned false,
 * but it would say nothing about WHY, and a reader would have to know about the
 * bypass to understand the rule. Stating the rule makes it reviewable.
 */
class BranchChangeRequestPolicy
{
    /**
     * The approver queue. Super Admin only.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('Super Admin');
    }

    /**
     * A requester may read their own request; an approver may read any.
     */
    public function view(User $user, BranchChangeRequest $request): bool
    {
        return $user->hasRole('Super Admin')
            || (int) $request->requester_user_id === (int) $user->id;
    }

    /**
     * Only a user whose own working branch is subject to the daily lock has
     * anything to request. Everyone else has no lock to be released from.
     *
     * Note this is decided from the user's LIVE daily context, not from a role
     * name alone: the lock and the right to ask for an exception to it are the
     * same fact.
     */
    public function create(User $user): bool
    {
        $context = app(DailyBranchContextService::class)->currentFor($user);

        return $context !== null
            && DailyBranchContextService::isLockedRoleContext((string) $context->role_context);
    }

    /**
     * Withdraw one's own pending request. Never someone else's.
     */
    public function cancel(User $user, BranchChangeRequest $request): bool
    {
        return (int) $request->requester_user_id === (int) $user->id
            && $request->isPending();
    }

    /**
     * Super Admin, and never the requester.
     *
     * ── THIS METHOD IS NOT THE SELF-APPROVAL BOUNDARY ─────────────────────
     *
     * `Gate::before` grants a Super Admin EVERY ability before any policy runs,
     * so for the one actor who could conceivably be both requester and approver
     * this clause never executes. Writing it here and calling it done would be a
     * self-approval hole with a comment claiming otherwise.
     *
     * The enforced boundary is in
     * {@see BranchChangeApprovalService},
     * which compares requester and approver on every decision and cannot be
     * short-circuited by a gate. This clause is the defence-in-depth layer that
     * stops a NON-Super-Admin from ever reaching the approval path, and it
     * documents the intent at the authorization surface.
     */
    public function decide(User $user, BranchChangeRequest $request): bool
    {
        if ((int) $request->requester_user_id === (int) $user->id) {
            return false;
        }

        return $user->hasRole('Super Admin');
    }
}
