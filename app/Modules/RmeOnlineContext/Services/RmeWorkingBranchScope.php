<?php

namespace App\Modules\RmeOnlineContext\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;

/**
 * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 — the single canonical answer to
 * "which RME branches may this user's operational workspace read?".
 *
 * One authority, consumed by the clinic-operations surfaces (visit list,
 * patient queue, RME reports) and the cashier financial surfaces (doctor-cashier
 * sync, cashier RME, payment report, receivables). Controllers and repositories
 * MUST NOT re-derive branch scope themselves, and MUST NOT trust a request
 * `branch_id` to widen it.
 *
 * Rules:
 *  - A "context-bound" role works from ONE selected branch at a time: Admin
 *    Klinik, Perawat and Kasir. Their scope is exactly the active online context
 *    branch.
 *  - Fail closed. A context-bound user without a valid active context sees an
 *    EMPTY scope — never the whole estate, never a MAIN/first-branch fallback.
 *  - Governance/cross-branch roles (Owner, Super Admin, Supervisor RME) and the
 *    reporting roles keep the full active RME-enabled set.
 *  - Doctor is deliberately NOT context-bound here. The doctor clinical branch
 *    model (practice branches + DoctorClinicalBranchResolver) is a separate
 *    domain and must not be regressed by this scope.
 */
class RmeWorkingBranchScope
{
    public function __construct(
        private readonly UserOnlineContextService $onlineContext,
        private readonly BranchService $branches,
    ) {}

    /**
     * True when this user's workspace is pinned to one selected working branch.
     */
    public function isContextBound(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->onlineContext->requiresAdminClinicContext($user)
            || $this->onlineContext->requiresPerawatContext($user)
            || $this->onlineContext->requiresKasirContext($user);
    }

    /**
     * The active working branch for a context-bound user, or null.
     */
    public function activeBranchId(?User $user): ?int
    {
        if ($user === null || ! $this->isContextBound($user)) {
            return null;
        }

        return $this->onlineContext->activeContextBranchId($user);
    }

    /**
     * The RME branch ids this user's operational workspace may read.
     *
     * @return array<int, int>
     */
    public function branchIdsFor(?User $user): array
    {
        if ($user !== null && $this->isContextBound($user)) {
            $branchId = $this->onlineContext->activeContextBranchId($user);

            // Fail closed: no valid working context => no data at all.
            return $branchId === null ? [] : [$branchId];
        }

        return $this->branches->rmeEnabledIds();
    }

    /**
     * Apply an optional user-supplied branch filter. A filter may only NARROW
     * an already-authorised scope; a value outside the scope is ignored and the
     * authorised scope is returned unchanged. It can never widen access.
     *
     * @param  array<int, int>  $scopeIds
     * @return array<int, int>
     */
    public function narrow(array $scopeIds, ?int $requestedBranchId): array
    {
        if ($requestedBranchId !== null && in_array($requestedBranchId, $scopeIds, true)) {
            return [$requestedBranchId];
        }

        return $scopeIds;
    }

    /**
     * Convenience: the authorised scope for a user, already narrowed by an
     * optional request filter.
     *
     * @return array<int, int>
     */
    public function resolve(?User $user, ?int $requestedBranchId = null): array
    {
        return $this->narrow($this->branchIdsFor($user), $requestedBranchId);
    }

    /**
     * True when the given branch is inside this user's authorised scope. The
     * server-side boundary for every branch-owned record (IDOR guard).
     */
    public function allows(?User $user, ?int $branchId): bool
    {
        if ($branchId === null) {
            return false;
        }

        return in_array((int) $branchId, $this->branchIdsFor($user), true);
    }
}
