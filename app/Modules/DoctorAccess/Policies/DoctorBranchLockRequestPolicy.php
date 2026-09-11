<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Policies;

use App\Models\User;
use App\Modules\Doctor\Services\DoctorIdentityResolver;
use App\Modules\DoctorAccess\Models\DoctorBranchLockRequest;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — who may see, file, withdraw and
 * decide a home-branch assignment or transfer request.
 *
 * ── AUTHORITY IS A PERMISSION, NEVER A ROLE NAME ──────────────────────────
 *
 * The product asks for Super Admin and Supervisor RME, and both arrive the
 * ordinary way: Super Admin through the single global `Gate::before` bypass,
 * Supervisor RME because the seeder grants it these permissions and nothing
 * else. Writing `hasRole('Supervisor RME')` would work today and become quietly
 * wrong the first time the estate needs a third approver — the reasoning
 * `DoctorDeviceAuthorizationPolicy` already records.
 *
 * FILING AND DECIDING ARE SEPARATE PERMISSIONS ON PURPOSE.
 * `manage_doctor_branch_locks` files; `approve_doctor_branch_locks` decides.
 * Supervisor RME holds the second and deliberately not the first, so the
 * approver of a branch move is not also the person who proposed it.
 *
 * ── THIS POLICY IS DEFENCE IN DEPTH, NOT THE SELF-APPROVAL BOUNDARY ───────
 *
 * TWO REASONS, AND BOTH MATTER.
 *
 * FIRST: the single global `Gate::before` returns true for a Super Admin BEFORE
 * any method in this class executes. Every clause below is therefore skipped
 * entirely for the one actor most likely to be both parties to a decision.
 *
 * SECOND, AND SPECIFIC TO THIS SPRINT: the requester/approver comparison in
 * {@see self::decide()} does NOT cover the case that matters most. The SUBJECT
 * of a branch lock is a DOCTOR, identified by `mst_doctors.user_id`, and they
 * need never have filed the request — so an approver who IS the subject doctor
 * passes that comparison untouched. It is a different comparison, against a row
 * this class does not load.
 *
 * BOTH comparisons are enforced inside
 * `DoctorBranchLockApprovalService::approve()`, in the transaction, after the
 * doctor row is locked. That is the boundary. This class exists so a
 * non-Super-Admin without the permission never reaches the surface at all.
 *
 * THERE IS DELIBERATELY NO before() HOOK HERE. A policy-level `before()` would
 * shadow the single global one and become a second, easily-missed bypass.
 */
class DoctorBranchLockRequestPolicy
{
    public function __construct(
        private readonly DoctorIdentityResolver $identities,
    ) {}

    /** The approver queue and the current-locks table. */
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            'view_doctor_branch_locks',
            'approve_doctor_branch_locks',
            'manage_doctor_branch_locks',
        ]);
    }

    /**
     * One request. Visible to the approver tier, to whoever filed it, and to the
     * doctor it is about — their own branch is their own working context, not an
     * estate disclosure.
     */
    public function view(User $user, DoctorBranchLockRequest $request): bool
    {
        return $this->viewAny($user)
            || (int) $request->requester_user_id === (int) $user->id
            || $this->isSubjectDoctor($user, $request);
    }

    /**
     * File a request.
     *
     * Either an administrator filing on a doctor's behalf, or a doctor filing
     * for themself. The second case is what keeps the workflow usable while the
     * estate is still UNSET: a doctor can ask, and an approver decides.
     *
     * `resolveForUser()` returns null for an unlinked account and excludes
     * soft-deleted records through the model's global scope, so neither can file
     * — which matches the service, where an unlinked subject is refused outright
     * because it binds nobody (ruling P6).
     *
     * WHICH DOCTOR the request is about is NOT decided here. The controller
     * reads `doctor_id` from the payload only when the actor holds
     * `manage_doctor_branch_locks`; for a doctor filing for themself it is
     * discarded and replaced with their own linked id.
     */
    public function create(User $user): bool
    {
        if ($user->can('manage_doctor_branch_locks')) {
            return true;
        }

        $doctor = $this->identities->resolveForUser($user);

        return $doctor !== null && $doctor->is_active === true;
    }

    /**
     * Withdraw a request you filed, while it is still undecided. Nobody else
     * may withdraw it — an approver rejects instead, which records a decision.
     */
    public function cancel(User $user, DoctorBranchLockRequest $request): bool
    {
        return (int) $request->requester_user_id === (int) $user->id && $request->isPending();
    }

    /**
     * Approve or reject.
     *
     * The requester comparison here is the readable half of the rule and is
     * repeated verbatim in the service, which is where it actually holds. See
     * the class docblock for why this method cannot be the boundary.
     */
    public function decide(User $user, DoctorBranchLockRequest $request): bool
    {
        if ((int) $request->requester_user_id === (int) $user->id) {
            return false;
        }

        return $user->can('approve_doctor_branch_locks');
    }

    /**
     * Is this account the doctor the request is about?
     *
     * Identity comes from the one explicit, persisted, unique column that joins
     * an account to a clinician — `mst_doctors.user_id` — and never from a name
     * or an email address.
     */
    private function isSubjectDoctor(User $user, DoctorBranchLockRequest $request): bool
    {
        $doctor = $this->identities->resolveForUser($user);

        return $doctor !== null && (int) $doctor->id === (int) $request->doctor_id;
    }
}
