<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Services\DoctorAccessSubjectGuard;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the person an approval is ABOUT,
 * proven to be decidable.
 *
 * THE WHOLE POINT OF THIS TYPE IS THAT IT CANNOT BE CONSTRUCTED FROM A DOCTOR
 * ALONE. Owner ruling P6: a Doctor-role account with `mst_doctors.user_id` NULL
 * binds nobody — there is no session to invalidate, no presence to read and no
 * room to free, so an approval against it would report success while doing
 * nothing at all. Holding a value of this type is proof that the doctor exists,
 * is not soft-deleted, is active, and resolves to a real `users` row.
 *
 * It is also proof that the doctor row was read UNDER A LOCK, because
 * {@see DoctorAccessSubjectGuard} is the only
 * place that mints one and it always reads through
 * `DoctorRepositoryInterface::findForUpdate()`. That lock is what serialises
 * every decision about one doctor, and — since no index on either engine can
 * express a range predicate — it is the ONLY thing preventing two approvers
 * granting overlapping covers.
 *
 * It carries no branch and no permission. Which branch a doctor may work from is
 * the effective-branch resolver's answer, and who may decide is the policy's.
 */
final class DoctorAccessSubject
{
    public function __construct(
        public readonly Doctor $doctor,
        public readonly User $user,
    ) {}

    public function doctorId(): int
    {
        return (int) $this->doctor->id;
    }

    public function userId(): int
    {
        return (int) $this->user->id;
    }

    /**
     * Is this account the one making the decision?
     *
     * THE SUBJECT-IS-APPROVER COMPARISON, AND IT IS NOT THE ONE A REVIEWER
     * EXPECTS. The familiar self-approval check compares the REQUESTER with the
     * approver, and the subject of a lock or a cover need never have filed
     * anything — they are identified by `mst_doctors.user_id`. An approver who
     * is themself the subject doctor therefore sails straight through the
     * requester comparison. This is the second comparison, and both of them run
     * inside the transaction, after the doctor row is locked, because the single
     * global `Gate::before` grants a Super Admin every ability BEFORE any policy
     * method executes — so a clause written only in a policy never runs for the
     * one actor who could plausibly be both parties.
     */
    public function isActedOnBy(User $actor): bool
    {
        return $this->userId() === (int) $actor->id;
    }
}
