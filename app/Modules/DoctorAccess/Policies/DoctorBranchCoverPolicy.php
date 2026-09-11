<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Policies;

use App\Models\User;
use App\Modules\Doctor\Services\DoctorIdentityResolver;
use App\Modules\DoctorAccess\Models\DoctorBranchCover;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — who may see, file, withdraw and
 * decide a temporary branch cover.
 *
 * ── A DOCTOR MAY NEVER FILE COVER FOR THEMSELF ────────────────────────────
 *
 * Section Q. {@see self::create()} therefore requires
 * `manage_doctor_branch_locks` and offers no self-service branch, which is the
 * one structural difference from the sibling lock-request policy — a doctor may
 * ask for their own permanent branch to be set, and may never grant themself
 * temporary authority somewhere else.
 *
 * THAT CLAUSE ALONE DOES NOT MAKE THE RULE TRUE. The subject of a cover is
 * identified by `mst_doctors.user_id`, not by the actor, so an administrator who
 * happens to be a linked doctor could file against their own record and pass
 * every clause here. The service refuses `doctor->user_id === actor->id` in both
 * `request()` and `approve()`, and that is the boundary.
 *
 * ── THE REQUESTER MAY NEVER APPROVE THEIR OWN COVER ───────────────────────
 *
 * OWNER DECISION, 2026-09-11, reversing the earlier shape. Super Admin and
 * Supervisor RME may BOTH file a cover and BOTH decide one, so the separation of
 * duties cannot come from which permission a role holds — it is ACTOR-based:
 *
 *     requester_user_id MUST NEVER equal the deciding user id
 *
 * The deadlock worry that licensed self-approval is answered by the same
 * decision: with `manage_` and `approve_` now in both tiers, any two distinct
 * accounts can complete the workflow in either order.
 *
 * {@see self::decide()} carries the readable half. THE BINDING HALF IS IN
 * `DoctorBranchCoverApprovalService::lockPendingCover()`, inside the transaction,
 * because of the Gate::before bypass described below.
 *
 * ── DEFENCE IN DEPTH, NOT THE BOUNDARY ────────────────────────────────────
 *
 * The single global `Gate::before` returns true for a Super Admin before any
 * method here runs, so for that actor every clause below is skipped. The
 * decisions that must hold for everyone — requester-is-decider,
 * subject-is-approver, the home-lock prerequisite, period bounds and overlap —
 * live in
 * `DoctorBranchCoverApprovalService`, inside the transaction, after the doctor
 * row is locked.
 *
 * THERE IS DELIBERATELY NO before() HOOK HERE, for the same reason as its
 * sibling: it would shadow the single global one and become a second bypass.
 */
class DoctorBranchCoverPolicy
{
    public function __construct(
        private readonly DoctorIdentityResolver $identities,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->canAny([
            'view_doctor_branch_locks',
            'approve_doctor_branch_locks',
            'manage_doctor_branch_locks',
        ]);
    }

    /**
     * One cover. The approver tier, whoever filed it, and the doctor it is
     * about — a doctor must be able to see the temporary authority they are
     * working under, and when it ends.
     */
    public function view(User $user, DoctorBranchCover $cover): bool
    {
        return $this->viewAny($user)
            || (int) $cover->requester_user_id === (int) $user->id
            || $this->isSubjectDoctor($user, $cover);
    }

    /** File a cover on a doctor's behalf. There is no self-service path. */
    public function create(User $user): bool
    {
        return $user->can('manage_doctor_branch_locks');
    }

    /**
     * Approve or reject.
     *
     * The requester comparison here is the readable half of the rule and is
     * repeated verbatim in the service, which is where it actually holds — see
     * the class docblock for why this method cannot be the boundary.
     */
    public function decide(User $user, DoctorBranchCover $cover): bool
    {
        if ((int) $cover->requester_user_id === (int) $user->id) {
            return false;
        }

        return $user->can('approve_doctor_branch_locks');
    }

    /**
     * Withdraw a cover.
     *
     * An approver may cancel at any point, including a cover currently in force
     * — that is the escape hatch that stops a long cover jamming the permanent
     * transfer workflow, and the transfer refusal names it explicitly.
     *
     * The requester may withdraw their own cover only while it is still PENDING;
     * once granted, ending it is a decision, not a withdrawal.
     */
    public function cancel(User $user, DoctorBranchCover $cover): bool
    {
        if ($user->can('approve_doctor_branch_locks')) {
            return true;
        }

        return (int) $cover->requester_user_id === (int) $user->id && $cover->isPending();
    }

    /**
     * Manually release the subject doctor's session (ruling P17).
     *
     * ITS OWN PERMISSION, because it is a different kind of authority: it ends
     * somebody's working session now, without deciding anything about their
     * branch. It ends a LOGIN SESSION only — no device, no authorization and no
     * WebAuthn credential is revoked — and the service refuses an unlinked
     * subject and an approver acting on their own account.
     */
    public function releaseSession(User $user): bool
    {
        return $user->can('release_doctor_session_leases');
    }

    /**
     * Is this account the doctor the cover is about?
     *
     * Identity comes from `mst_doctors.user_id` and nothing else — never a name
     * or an email address, which are mutable and can legitimately collide.
     */
    private function isSubjectDoctor(User $user, DoctorBranchCover $cover): bool
    {
        $doctor = $this->identities->resolveForUser($user);

        return $doctor !== null && (int) $doctor->id === (int) $cover->doctor_id;
    }
}
