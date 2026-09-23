<?php

namespace App\Modules\RmeOnlineContext\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\DoctorAccess\Services\DoctorEffectiveBranchResolver;

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
 *
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 made that last rule CONDITIONAL
 * rather than absolute (owner decision O2): a doctor with an effective locked
 * branch works from that one branch, and a doctor with none keeps the legacy
 * estate-wide behaviour byte for byte. The lock is applied in exactly ONE place
 * in this class — {@see operationalBranchIdsFor()} — and it is asked of exactly
 * one authority, {@see DoctorEffectiveBranchResolver}. The predicate is never
 * re-implemented here.
 *
 * WHY IT IS A SEPARATE METHOD, AND NOT branchIdsFor(). Two consumers of
 * branchIdsFor() must NOT narrow, and both were verified by reading them:
 *
 *  - {@see allows()} is the per-record IDOR guard behind ClinicVisitPolicy,
 *    RmeInvoicePolicy and RmeVisitConsentPolicy. The patient-centric record
 *    workspace anchors on a patient's EARLIEST visit, so narrowing per-record
 *    access would stop a doctor opening the record of the patient standing in
 *    front of them at their own locked branch whenever that patient was first
 *    seen elsewhere. That is a patient-safety defect, not an access decision.
 *  - OdontogramService::patientHistoryForVisit() reads a patient's odontogram
 *    history. Owner decision O3 keeps archive reads cross-branch.
 *
 * So the lock hooks the LIST scope ({@see resolve()}, which every visit list,
 * queue, worklist and count widget funnels through) and the WRITE chokepoint
 * (ClinicVisitService::resolveBranchId), and nothing else.
 */
class RmeWorkingBranchScope
{
    public function __construct(
        private readonly UserOnlineContextService $onlineContext,
        private readonly BranchService $branches,
        private readonly DoctorEffectiveBranchResolver $doctorBranches,
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
     * The RME branch ids this user's workspace may read, WITHOUT the doctor
     * branch lock.
     *
     * The pre-sprint answer, unchanged. It still backs {@see allows()} (the
     * per-record IDOR guard), the branch-filter selectors and the odontogram
     * patient history, all three of which must stay cross-branch for a doctor.
     * A list scope wants {@see operationalBranchIdsFor()} instead.
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
     * The RME branch ids this user's operational LISTS may read.
     *
     * {@see branchIdsFor()} plus the doctor branch lock. Four states, and only
     * the first one narrows anything:
     *
     *   LOCKED (home or an active cover)  ->  exactly that branch
     *   UNSET / unlinked / not-applicable ->  the legacy answer, verbatim
     *   DEGRADED (the locked branch lost
     *   is_active or is_rme_enabled)      ->  the legacy answer, verbatim
     *
     * Expressed as an INTERSECTION rather than a replacement, deliberately. It
     * can then only ever NARROW the legacy answer, so it is structurally
     * incapable of granting a branch the pre-sprint rules withheld:
     *
     *  - a locked branch that is not an active RME branch yields an EMPTY scope
     *    rather than an unreadable one (the resolver already degrades this case
     *    to null, so this is belt and braces, not the primary guard);
     *  - an account that is BOTH a locked doctor and a context-bound role gets
     *    the INTERSECTION, and therefore an EMPTY scope when the two disagree.
     *    THAT IS A DELIBERATE FAIL-CLOSED CHOICE, NOT AN OVERSIGHT (open item
     *    V5): such a hybrid account sees nothing until the disagreement is
     *    resolved — either the online context is re-selected at the locked
     *    branch, or the lock is moved — rather than a lock silently widening a
     *    pinned working context or a pinned context silently overriding a lock.
     *    Empty can only ever be narrower than either input, so the choice is
     *    incapable of granting anything.
     *
     * The physical device, the tablet's branch, a request `branch_id` and the
     * session all have ZERO influence here: the resolver takes a User and
     * nothing else.
     *
     * @return array<int, int>
     */
    public function operationalBranchIdsFor(?User $user): array
    {
        $scopeIds = $this->branchIdsFor($user);

        // ONE question, to the ONE authority. Null means "do not narrow" for
        // every reason it can be null, which is exactly owner decision O1: an
        // UNSET doctor keeps the behaviour they had before this sprint.
        $effectiveBranchId = $this->doctorBranches->branchIdFor($user);

        if ($effectiveBranchId === null) {
            return $scopeIds;
        }

        return array_values(array_intersect($scopeIds, [$effectiveBranchId]));
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
     * THE LIST SCOPE: the authorised scope for a user's operational lists,
     * already narrowed by an optional request filter.
     *
     * Daftar Kunjungan, the patient queue, the room worklist and every count
     * widget funnel through ClinicVisitService::scopeBranchIds(), which calls
     * this. It resolves through {@see operationalBranchIdsFor()}, so a locked
     * doctor's lists follow the LOCKED branch and never the tablet they happen
     * to be holding.
     *
     * @return array<int, int>
     */
    public function resolve(?User $user, ?int $requestedBranchId = null): array
    {
        return $this->narrow($this->operationalBranchIdsFor($user), $requestedBranchId);
    }

    /**
     * True when the given branch is inside this user's authorised scope. The
     * server-side boundary for every branch-owned record (IDOR guard).
     *
     * DELIBERATELY on {@see branchIdsFor()} and NOT on
     * {@see operationalBranchIdsFor()}. This is per-record authorization, and
     * the doctor branch lock is forbidden from reaching it: a doctor must still
     * be able to open the record of a patient whose earliest visit happened at
     * another branch. Do not "align" this with resolve().
     */
    public function allows(?User $user, ?int $branchId): bool
    {
        if ($branchId === null) {
            return false;
        }

        return in_array((int) $branchId, $this->branchIdsFor($user), true);
    }
}
