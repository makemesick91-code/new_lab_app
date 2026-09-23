<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Services;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\DoctorAccess\Interfaces\DoctorBranchCoverRepositoryInterface;
use App\Modules\DoctorAccess\Interfaces\DoctorBranchLockRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorBranchCover;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use App\Modules\DoctorAccess\Support\DoctorAccessSubject;
use App\Modules\DoctorAccess\Support\DoctorBranchCoverPeriod;
use App\Modules\DoctorAccess\Support\DoctorBranchCoverState;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — TEMPORARY BRANCH COVER, the
 * whole authority: request(), approve(), reject(), cancel().
 *
 * One row is both the request and, once approved, the grant. A cover NEVER
 * changes `mst_doctor_branch_locks`; it is time-boxed authority to work
 * somewhere else, and when it stops being current the effective branch resolves
 * back to home.
 *
 * ── THE INVARIANT THE DATABASE DOES NOT ENFORCE ───────────────────────────
 *
 * NO TWO APPROVED COVERS FOR ONE DOCTOR MAY OVERLAP, and no index on either
 * engine can say so. `trx_doctor_branch_covers_pending_uq` stops a second
 * PENDING cover; `trx_doctor_branch_covers_period_uq` stops an IDENTICAL
 * approved period being inserted twice. Neither expresses overlap, because a
 * unique index compares VALUES and overlap is a RANGE predicate. That was
 * proven by attempting the write, not assumed: identical periods were rejected,
 * merely overlapping ones were ACCEPTED.
 *
 * So the non-overlap invariant rests ENTIRELY on this service, and specifically
 * on this order inside {@see self::approve()}:
 *
 *   1. lock the cover row
 *   2. lock the DOCTOR row  (DoctorAccessSubjectGuard)
 *   3. lock the doctor's `mst_doctor_branch_locks` row
 *   4. ONLY THEN evaluate overlap
 *   5. write
 *
 * Evaluating overlap before step 3 would let two approvers each read a clean
 * result and each commit, and no constraint would stop them. On SQLite
 * `lockForUpdate()` compiles to an empty string, so the local suite proves the
 * logic and only the PostgreSQL critical gate proves the concurrency.
 *
 * ── A COVER REQUIRES A HOME LOCK, AND THAT IS A HARD PREREQUISITE ─────────
 *
 * Two independent reasons, and either alone would be sufficient:
 *
 *  - `trx_doctor_branch_covers.source_home_branch_id` is NOT NULL, so the row
 *    is unrepresentable for an UNSET doctor.
 *  - An UNSET doctor has no `mst_doctor_branch_locks` row, so step 3 above has
 *    nothing to lock and the overlap invariant would have no serialisation
 *    point at all. Inventing a placeholder row to lock is forbidden by owner
 *    decision O1: it would write a branch nobody approved.
 *
 * There is also a product reason. Cover is authority RELATIVE to a permanent
 * home; with no home there is nothing to revert to, and expiry would silently
 * WIDEN an UNSET doctor back to every RME branch — a widening on a timer, the
 * exact inverse of a lock.
 *
 * ── EXPIRY IS DERIVED, AND MUST NOT BE 'COMPLETED' WITH A CRON ────────────
 *
 * There is no scheduled command here, no `expireStale()`, no ACTIVE or EXPIRED
 * column. ACTIVE and EXPIRED are computed from `starts_at`/`ends_at` against the
 * evaluation instant, on every read. The SESSION consequence of expiry is
 * delivered by the lease middleware, which recomputes the effective branch from
 * current timestamps on every protected request and compares it with the value
 * the session was established under. That is what makes activation, expiry and
 * transfer behave identically with no scheduler in the correctness path. A cron
 * added here would become the security boundary the owner decision forbids, and
 * a late run would leave an expired cover effective.
 */
class DoctorBranchCoverApprovalService
{
    public const ENTITY_TYPE = 'trx_doctor_branch_covers';

    /**
     * ITS OWN AUDIT ACTIONS (ruling P8) — never the device action.
     */
    public const ACTION_REQUESTED = 'DOCTOR_BRANCH_COVER_REQUESTED';

    public const ACTION_APPROVED = 'DOCTOR_BRANCH_COVER_APPROVED';

    public const ACTION_REJECTED = 'DOCTOR_BRANCH_COVER_REJECTED';

    public const ACTION_CANCELLED = 'DOCTOR_BRANCH_COVER_CANCELLED';

    public function __construct(
        private readonly DoctorBranchCoverRepositoryInterface $covers,
        private readonly DoctorBranchLockRepositoryInterface $locks,
        private readonly BranchRepositoryInterface $branches,
        private readonly DoctorAccessSubjectGuard $subjects,
        private readonly AuditLogService $audit,
        private readonly ClinicalClock $clock,
    ) {}

    /**
     * File a cover request on a doctor's behalf.
     *
     * A DOCTOR MAY NEVER FILE COVER FOR THEMSELF (section Q). The policy cannot
     * make that guarantee on its own, because the subject is identified by
     * `mst_doctors.user_id` rather than by the actor, so the comparison is here.
     *
     * @param  string  $startsAtInput  a local datetime, read in the CLINICAL zone
     * @param  string  $endsAtInput  a local datetime, read in the CLINICAL zone
     *
     * @throws ValidationException
     */
    public function request(
        User $actor,
        int $doctorId,
        int $targetBranchId,
        string $startsAtInput,
        string $endsAtInput,
        string $reason,
    ): DoctorBranchCover {
        $subject = $this->subjects->assertRequestableSubject($doctorId);

        if ($subject->isActedOnBy($actor)) {
            throw ValidationException::withMessages([
                'doctor_id' => 'Anda tidak dapat mengajukan cover cabang untuk diri sendiri.',
            ]);
        }

        $homeBranchId = $this->requireHomeBranchId($subject->doctorId());

        if ($homeBranchId === $targetBranchId) {
            throw ValidationException::withMessages([
                'target_branch_id' => 'Cabang cover sama dengan cabang tetap dokter.',
            ]);
        }

        $this->assertEligibleTarget($targetBranchId);

        $period = DoctorBranchCoverPeriod::fromOperatorInput($this->clock, $startsAtInput, $endsAtInput);
        $period->assertUsableAt($this->clock->now(), $this->clock);

        // ADVISORY ONLY. The enforced overlap check runs in approve(), under the
        // locks. Refusing here as well spares the approver a request that was
        // never grantable, but a clean answer here guarantees nothing: two
        // requests filed a millisecond apart both read clean.
        $this->assertNoOverlapAdvisory($subject->doctorId(), $period);

        try {
            // NESTED TRANSACTION -> SAVEPOINT, catch OUTSIDE it. On PostgreSQL a
            // failed statement aborts the whole transaction, so catching the
            // unique violation and continuing would poison the connection on
            // exactly the race this handler exists for — and would pass on
            // SQLite, which is how that shape survives review.
            $cover = DB::transaction(fn (): DoctorBranchCover => $this->covers->create([
                'doctor_id' => $subject->doctorId(),
                'requester_user_id' => (int) $actor->id,
                'source_home_branch_id' => $homeBranchId,
                'target_branch_id' => $targetBranchId,
                'starts_at' => $period->starts(),
                'ends_at' => $period->ends(),
                'reason' => $reason,
                'requested_at' => now(),
            ]));
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'doctor_id' => 'Dokter ini sudah memiliki pengajuan cover yang menunggu persetujuan. '
                    .'Putuskan atau batalkan pengajuan tersebut terlebih dahulu.',
            ]);
        }

        $this->audit->log(
            self::ENTITY_TYPE,
            (int) $cover->id,
            self::ACTION_REQUESTED,
            null,
            $this->auditPayload($cover),
            $actor,
        );

        return $cover;
    }

    /**
     * Grant the cover.
     *
     * THE REQUESTER MAY NEVER APPROVE THEIR OWN COVER (owner decision,
     * 2026-09-11), AND NEITHER MAY THE SUBJECT DOCTOR. Two comparisons, both
     * inside this transaction:
     *
     *   requester_user_id !== approver id   {@see self::lockPendingCover()}
     *   subject doctor     !== approver     {@see self::assertNotTheSubject()}
     *
     * This reverses the earlier shape, which allowed the requester to approve on
     * the grounds that a mandatory second administrator would deadlock cover on
     * a thin approver tier. That argument no longer holds: `manage_` and
     * `approve_` are now BOTH granted to Supervisor RME as well as reaching
     * Super Admin through the global bypass, so any two distinct accounts across
     * either tier can complete the workflow, in either order.
     *
     * THE RULE IS ACTOR-BASED, NEVER ROLE-BASED. Nothing here asks which role
     * filed and which role decides — only whether the two user ids differ.
     *
     * @throws ValidationException
     */
    public function approve(
        int $coverId,
        User $approver,
        ?string $decisionNote = null,
        bool $acknowledgeOnlineImpact = false,
    ): DoctorBranchCover {
        return DB::transaction(function () use (
            $coverId,
            $approver,
            $decisionNote,
            $acknowledgeOnlineImpact,
        ): DoctorBranchCover {
            // 1. THE COVER ROW.
            $cover = $this->lockPendingCover($coverId, $approver);

            // 2. THE DOCTOR ROW.
            $subject = $this->subjects->lockDecidableSubject((int) $cover->doctor_id);
            $this->assertNotTheSubject($subject, $approver);

            // 3. THE HOME LOCK ROW — the serialisation point for overlap, and
            //    the prerequisite that makes a cover coherent at all. An UNSET
            //    doctor has no row here, so there is nothing to lock and nothing
            //    to revert to; refuse rather than proceed unserialised.
            $homeBranchId = $this->lockHomeBranchId($subject->doctorId());

            // STALE-SOURCE GUARD. The home branch must still be where the
            // request said it was; a cover approved against a home that moved
            // underneath it would be measured from a starting point the approver
            // never saw, and could point at the doctor's own new home branch.
            if ($homeBranchId !== (int) $cover->source_home_branch_id) {
                throw ValidationException::withMessages([
                    'cover' => 'Pengajuan tidak lagi valid karena cabang tetap dokter telah berubah. '
                        .'Minta pengaju mengajukan ulang.',
                ]);
            }

            $targetBranchId = (int) $cover->target_branch_id;

            if ($targetBranchId === $homeBranchId) {
                throw ValidationException::withMessages([
                    'target_branch_id' => 'Cabang cover sama dengan cabang tetap dokter.',
                ]);
            }

            $this->assertEligibleTarget($targetBranchId);
            $this->assertWithinPracticeBranches($subject, $targetBranchId);

            $now = $this->clock->now();

            // PERIOD RE-ASSERTED AT DECISION TIME, against config read now. A
            // window filed when the maximum was 365 days must not be approvable
            // after an operator lowers it to 90, and a window that finished
            // while the request waited must not be approved into effect.
            $period = DoctorBranchCoverPeriod::fromCover($cover);
            $period->assertUsableAt($now, $this->clock);

            // 4. OVERLAP, EVALUATED UNDER THE LOCKS TAKEN IN STEPS 2 AND 3.
            //    This check is correct ONLY because of that ordering. There is
            //    no database backstop behind it.
            $this->assertNoOverlapUnderLock($subject->doctorId(), $period, (int) $cover->id);

            $online = $this->subjects->isOnline($subject);
            $this->subjects->assertOnlineImpactAcknowledged($online, $acknowledgeOnlineImpact);

            $before = $this->auditPayload($cover) + [
                'live_home_branch_id' => $homeBranchId,
                'doctor_online_at_decision' => $online,
            ];

            // 5. WRITE.
            $approved = $this->covers->update($cover, [
                'status' => DoctorBranchCover::STATUS_APPROVED,
                'decided_by_user_id' => (int) $approver->id,
                'decided_at' => now(),
                'decision_note' => $decisionNote,
            ]);

            // SECTION Q: cover activation invalidates the doctor's session, and
            // it does so UNCONDITIONALLY — including for a future-dated cover
            // that is not effective yet.
            //
            // Releasing only when the effective branch changes RIGHT NOW would
            // turn 'approval invalidates the session' into a case analysis, and
            // the case that gets it wrong is the one where a doctor is mid-shift
            // when a cover starting in an hour is approved. Releasing always
            // costs a scheduled cover's subject one extra login and makes the
            // rule true without exceptions.
            //
            // Room first, then lease. No device, authorization or WebAuthn row
            // is touched.
            $this->subjects->endWorkingSession(
                $subject,
                DoctorSessionLease::RELEASE_EFFECTIVE_BRANCH_CHANGED,
                $approver,
            );

            $this->audit->log(
                self::ENTITY_TYPE,
                (int) $approved->id,
                self::ACTION_APPROVED,
                $before,
                $this->auditPayload($approved) + [
                    'live_home_branch_id' => $homeBranchId,
                    'state_at_decision' => DoctorBranchCoverState::for($approved, $now),
                    // RULING P11, RECORDED WITHOUT A COLUMN. This table has no
                    // `doctor_online_at_decision` field and this sprint adds no
                    // migration for one, so the fact that a working doctor was
                    // evicted — and that the approver acknowledged it — is
                    // persisted here, where an incident can reconstruct it.
                    'doctor_online_at_decision' => $online,
                    'online_impact_acknowledged' => $acknowledgeOnlineImpact,
                    'session_released' => true,
                ],
                $approver,
            );

            return $approved;
        });
    }

    /**
     * Refuse the cover. Nothing is granted, so no session is released.
     *
     * @throws ValidationException
     */
    public function reject(
        int $coverId,
        User $approver,
        ?string $decisionNote = null,
    ): DoctorBranchCover {
        // A REJECTION REQUIRES A REASON, and an APPROVAL does not — the same
        // asymmetry, and the same minimum, as the cancellation path directly
        // below. A refused cover leaves a doctor's rota unresolved, and the
        // requester who has to file it again is the one person who cannot see
        // why it was refused.
        //
        // Enforced BEFORE the transaction opens, so a refusal costs no row lock.
        $decisionNote = $this->requireRejectionReason($decisionNote);

        return DB::transaction(function () use ($coverId, $approver, $decisionNote): DoctorBranchCover {
            $cover = $this->lockPendingCover($coverId, $approver);

            $subject = $this->subjects->lockDecidableSubject((int) $cover->doctor_id);
            $this->assertNotTheSubject($subject, $approver);

            $before = $this->auditPayload($cover);

            $rejected = $this->covers->update($cover, [
                'status' => DoctorBranchCover::STATUS_REJECTED,
                'decided_by_user_id' => (int) $approver->id,
                'decided_at' => now(),
                'decision_note' => $decisionNote,
            ]);

            $this->audit->log(
                self::ENTITY_TYPE,
                (int) $rejected->id,
                self::ACTION_REJECTED,
                $before,
                $this->auditPayload($rejected) + ['session_released' => false],
                $approver,
            );

            return $rejected;
        });
    }

    /**
     * Withdraw a cover — the escape hatch that keeps a long cover from jamming
     * the permanent-transfer workflow.
     *
     * WHO MAY: an approver, at any point up to and including a cover that is
     * currently in force; or the row's own requester, but only while it is still
     * PENDING. The caller has already been through the policy; the requester
     * comparison is repeated here because the policy is not the boundary for a
     * Super Admin.
     *
     * THE STATUS AND THE TIMESTAMP ARE BOTH WRITTEN, and that is not
     * belt-and-braces. `trx_doctor_branch_covers_period_uq` covers
     * `(doctor_id, starts_at, ends_at) WHERE status = 'approved'`, so leaving
     * `status` on 'approved' while only stamping `cancelled_at` would let a
     * cancelled row keep occupying that slot and would refuse a legitimate
     * re-grant of the identical period.
     *
     * THE EVICTION IS CONDITIONAL, unlike approval's. A cover cancelled while it
     * was ACTIVE changes the doctor's effective branch back to home right now,
     * so the session must end. A cover cancelled while merely SCHEDULED was
     * never effective, so the session in front of the doctor is already correct
     * and ending it would be a gratuitous logout.
     *
     * @throws ValidationException
     */
    public function cancel(int $coverId, User $actor, string $reason, bool $isApprover): DoctorBranchCover
    {
        $reason = $this->requireReason($reason);

        return DB::transaction(function () use ($coverId, $actor, $reason, $isApprover): DoctorBranchCover {
            $cover = $this->covers->lockById($coverId);

            if ($cover === null) {
                throw ValidationException::withMessages([
                    'cover' => 'Pengajuan cover tidak ditemukan.',
                ]);
            }

            if ($cover->cancelled_at !== null
                || $cover->status === DoctorBranchCover::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'cover' => 'Cover ini sudah dibatalkan.',
                ]);
            }

            if (! $isApprover) {
                if (! $cover->isPending()) {
                    throw ValidationException::withMessages([
                        'cover' => 'Cover ini sudah diputuskan dan hanya dapat dibatalkan oleh penyetuju.',
                    ]);
                }

                if ((int) $cover->requester_user_id !== (int) $actor->id) {
                    throw ValidationException::withMessages([
                        'cover' => 'Pengajuan cover tidak ditemukan.',
                    ]);
                }
            }

            if ($cover->status === DoctorBranchCover::STATUS_REJECTED) {
                throw ValidationException::withMessages([
                    'cover' => 'Cover ini sudah ditolak dan tidak perlu dibatalkan.',
                ]);
            }

            $now = $this->clock->now();
            $wasActive = $cover->coversInstant($now);

            // Locked so a concurrent transfer approval — which reads exactly
            // this predicate to decide whether it is blocked — serialises
            // against the cancellation rather than racing it. Non-throwing on
            // purpose: see DoctorAccessSubjectGuard::tryLockDecidableSubject().
            // A cancellation must never be blockable, or an active cover on a
            // deactivated doctor would jam the transfer workflow permanently.
            $subject = $this->subjects->tryLockDecidableSubject((int) $cover->doctor_id);

            $before = $this->auditPayload($cover) + ['state_at_decision' => $cover->state($now)];

            $cancelled = $this->covers->update($cover, [
                'status' => DoctorBranchCover::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => (int) $actor->id,
                'cancelled_reason' => $reason,
            ]);

            $released = false;

            if ($wasActive && $subject !== null) {
                // The effective branch just reverted to home mid-session, which
                // section Q says must not happen silently inside an
                // already-authenticated session.
                $this->subjects->endWorkingSession(
                    $subject,
                    DoctorSessionLease::RELEASE_EFFECTIVE_BRANCH_CHANGED,
                    $actor,
                );

                $released = true;
            }

            $this->audit->log(
                self::ENTITY_TYPE,
                (int) $cancelled->id,
                self::ACTION_CANCELLED,
                $before,
                $this->auditPayload($cancelled) + [
                    'was_active_at_cancellation' => $wasActive,
                    'session_released' => $released,
                    'subject_decidable' => $subject !== null,
                ],
                $actor,
            );

            return $cancelled;
        });
    }

    /**
     * SELF-APPROVAL BOUNDARY, PART ONE OF TWO — the REQUESTER compared with the
     * decider, per the owner decision of 2026-09-11.
     *
     * IT LIVES HERE, IN THE SERVICE, INSIDE THE TRANSACTION, AND NOT ONLY IN
     * `DoctorBranchCoverPolicy::decide()`,
     * because the single global `Gate::before` returns true for a Super Admin
     * BEFORE any policy method executes. A clause written only in the policy
     * would never run for the one actor most likely to be both parties, which is
     * precisely the actor the rule exists to bind. The permission grants in
     * RoleSeeder cannot express it either: both tiers now hold `manage_` and
     * `approve_`, so the only thing that separates maker from checker is this
     * comparison of two user ids.
     *
     * Ordered BEFORE the PENDING re-assert so a self-approval and an
     * already-decided row each get their own message.
     *
     * Part two, {@see self::assertNotTheSubject()}, compares the SUBJECT DOCTOR
     * with the decider and cannot be moved up here — it needs the locked doctor
     * row. An approver who is the subject doctor but filed nothing passes this
     * comparison untouched.
     *
     * Reached from `approve()` and `reject()` only. `cancel()` takes its own row
     * lock and is deliberately NOT bound by this rule: withdrawing your own
     * pending cover is the requester's own remedy, not a decision.
     *
     * @throws ValidationException
     */
    private function lockPendingCover(int $coverId, User $decider): DoctorBranchCover
    {
        $cover = $this->covers->lockById($coverId);

        if ($cover === null) {
            throw ValidationException::withMessages([
                'cover' => 'Pengajuan cover tidak ditemukan.',
            ]);
        }

        if ($cover->requester_user_id !== null
            && (int) $cover->requester_user_id === (int) $decider->id) {
            throw ValidationException::withMessages([
                'cover' => 'Anda tidak dapat memutuskan pengajuan cover cabang yang Anda ajukan sendiri. '
                    .'Minta Super Admin atau Supervisor RME lain untuk memutuskannya.',
            ]);
        }

        // RE-ASSERTED AFTER THE LOCK. Two approvers deciding the same cover
        // serialise here and the second reads a row that is no longer PENDING.
        if (! $cover->isPending()) {
            throw ValidationException::withMessages([
                'cover' => 'Pengajuan cover ini sudah diputuskan.',
            ]);
        }

        return $cover;
    }

    /**
     * SELF-APPROVAL BOUNDARY, PART TWO OF TWO — the SUBJECT DOCTOR compared with
     * the approver. Part one is the requester comparison in
     * {@see self::lockPendingCover()}.
     *
     * It lives in the service, inside the transaction, after the doctor row is
     * locked, because the single global `Gate::before` returns true for a Super
     * Admin before any policy method runs — so this clause written only in a
     * policy would never execute for the one actor who could be both parties.
     *
     * @throws ValidationException
     */
    private function assertNotTheSubject(DoctorAccessSubject $subject, User $approver): void
    {
        if ($subject->isActedOnBy($approver)) {
            throw ValidationException::withMessages([
                'cover' => 'Anda tidak dapat menyetujui cover cabang untuk akun dokter Anda sendiri.',
            ]);
        }
    }

    /**
     * The home branch, read WITHOUT a lock, for the filing path.
     *
     * @throws ValidationException
     */
    private function requireHomeBranchId(int $doctorId): int
    {
        $homeBranchId = $this->locks->lockedBranchIdFor($doctorId);

        if ($homeBranchId === null) {
            throw ValidationException::withMessages([
                'doctor_id' => $this->homeLockRequiredMessage(),
            ]);
        }

        return $homeBranchId;
    }

    /**
     * The home branch, read UNDER a lock, for the decision path.
     *
     * Returning null here is not a missing-data error to repair: it is the UNSET
     * state, which owner decision O1 makes the ABSENCE of a row. A placeholder
     * row must never be invented to give this transaction something to lock —
     * that would write a branch nobody approved.
     *
     * @throws ValidationException
     */
    private function lockHomeBranchId(int $doctorId): int
    {
        $lock = $this->locks->lockForDoctor($doctorId);
        $homeBranchId = $lock?->home_branch_id === null ? null : (int) $lock->home_branch_id;

        if ($homeBranchId === null) {
            throw ValidationException::withMessages([
                'doctor_id' => $this->homeLockRequiredMessage(),
            ]);
        }

        return $homeBranchId;
    }

    private function homeLockRequiredMessage(): string
    {
        return 'Dokter ini belum memiliki cabang tetap. Tetapkan cabang tetap terlebih dahulu '
            .'sebelum memberikan cover sementara.';
    }

    /**
     * The advisory pre-check, run before the row exists.
     *
     * @throws ValidationException
     */
    private function assertNoOverlapAdvisory(int $doctorId, DoctorBranchCoverPeriod $period): void
    {
        $this->refuseOverlap($doctorId, $period, null);
    }

    /**
     * The ENFORCED check, run after the doctor row and the home lock row are
     * held. `$excludeId` is the cover being approved: its own PENDING row is not
     * approved yet and so cannot appear, but excluding it makes the predicate
     * correct for any future re-decision path as well.
     *
     * @throws ValidationException
     */
    private function assertNoOverlapUnderLock(
        int $doctorId,
        DoctorBranchCoverPeriod $period,
        int $excludeId,
    ): void {
        $this->refuseOverlap($doctorId, $period, $excludeId);
    }

    /**
     * @throws ValidationException
     */
    private function refuseOverlap(
        int $doctorId,
        DoctorBranchCoverPeriod $period,
        ?int $excludeId,
    ): void {
        $collision = $this->covers
            ->overlappingApproved($doctorId, $period->starts(), $period->ends(), $excludeId)
            ->first();

        if ($collision === null) {
            return;
        }

        $window = DoctorBranchCoverPeriod::fromCover($collision)->describeInClinicalZone($this->clock);

        throw ValidationException::withMessages([
            'starts_at' => 'Periode cover bertabrakan dengan cover lain yang sudah disetujui'
                .($window === null ? '' : ' ('.$window.')')
                .'. Batalkan cover tersebut atau pilih periode lain.',
        ]);
    }

    /**
     * A cover may only target an ACTIVE, RME-enabled branch, re-checked at
     * decision time: an approval never confers access to a branch the doctor
     * could not otherwise work in, and never resurrects a deactivated one.
     *
     * @throws ValidationException
     */
    private function assertEligibleTarget(int $branchId): void
    {
        $branch = $this->branches->findById($branchId);

        if ($branch === null || ! $branch->is_active || ! $branch->is_rme_enabled) {
            throw ValidationException::withMessages([
                'target_branch_id' => 'Cabang cover harus cabang RME yang aktif.',
            ]);
        }
    }

    /**
     * THE STRANDING GUARD (open item V1).
     *
     * `UserOnlineContextService::startDoctorSession()` refuses a branch that is
     * not in the doctor's `mst_doctor_branches` practice pivot, and that refusal
     * runs BEFORE the locked-branch assert. An ACTIVE cover replaces the doctor's
     * effective branch outright, so a cover naming a branch they do not practise
     * at does not narrow them — it STOPS them: the covered branch is outside
     * their practice pivot, and every branch inside it is outside their cover.
     * They cannot go online anywhere for the whole cover period.
     *
     * THE FIX BELONGS HERE, NOT THERE. Relaxing the practice-branch check would
     * turn an approved cover into a way to reach a branch the doctor was never
     * entitled to work in, which is exactly the widening this sprint refuses. So
     * the approval refuses instead, and names the remedy.
     *
     * Evaluated inside the transaction, under the doctor and home-lock rows
     * already locked by the caller, so the pivot cannot change between this read
     * and the write.
     *
     * Latent rather than live in production today, where every doctor is pivoted
     * to every RME branch. It becomes live the first time anyone narrows a
     * practice pivot.
     *
     * @throws ValidationException
     */
    private function assertWithinPracticeBranches(DoctorAccessSubject $subject, int $branchId): void
    {
        $doctor = $subject->doctor;
        $doctor->loadMissing('branches');

        if ($doctor->branches->contains('id', $branchId)) {
            return;
        }

        throw ValidationException::withMessages([
            'target_branch_id' => 'Cabang cover belum termasuk Cabang Praktik dokter ini, '
                .'sehingga dokter tidak akan dapat online di cabang mana pun selama periode cover. '
                .'Tambahkan cabang tersebut ke Cabang Praktik dokter di Master Data Dokter '
                .'terlebih dahulu, lalu setujui ulang pengajuan ini.',
        ]);
    }

    /**
     * A rejection nobody can explain is not one.
     *
     * The same rule, config key and minimum as {@see self::requireReason()}
     * below, which has said this about a cancellation since the cover workflow
     * shipped; a refusal is the other decision a requester has to act on and
     * cannot see the reason for. Enforced in the service rather than as a
     * conditional FormRequest rule, so an HTTP caller and a console caller
     * cannot diverge on whether the reason was required — the FormRequest
     * mirrors it only to produce a better message on the form.
     *
     * The APPROVAL note stays optional, and stays `?string`.
     *
     * @throws ValidationException
     */
    private function requireRejectionReason(?string $decisionNote): string
    {
        $reason = trim($decisionNote ?? '');
        $min = max(1, (int) config('doctor_access.reason.min_length', 10));

        if (mb_strlen($reason) < $min) {
            throw ValidationException::withMessages([
                'decision_note' => 'Alasan penolakan wajib diisi, minimal '.$min.' karakter.',
            ]);
        }

        return $reason;
    }

    /**
     * A cancellation nobody can explain is not one — and see
     * {@see self::requireRejectionReason()} above, which now says the same about
     * a refusal, against this same config key and this same minimum.
     *
     * Enforced in the service rather than as a conditional FormRequest rule, so
     * an HTTP caller and a console caller cannot diverge on whether the reason
     * was required.
     *
     * @throws ValidationException
     */
    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        $min = max(1, (int) config('doctor_access.reason.min_length', 10));

        if (mb_strlen($reason) < $min) {
            throw ValidationException::withMessages([
                'cancellation_reason' => 'Alasan pembatalan wajib diisi, minimal '.$min.' karakter.',
            ]);
        }

        return $reason;
    }

    /**
     * Audit payload — ids, branches, the period, status and the decision.
     *
     * NO patient data, no doctor name, no KTP/NIK, no device name, no IP and no
     * session id. Timestamps are ISO-8601 UTC instants, which is what the
     * columns hold; a reader who wants the clinic wall clock converts once,
     * rather than the trail storing a zone-ambiguous string.
     *
     * @return array<string, mixed>
     */
    private function auditPayload(DoctorBranchCover $cover): array
    {
        return [
            'cover_id' => (int) $cover->id,
            'doctor_id' => (int) $cover->doctor_id,
            'requester_user_id' => $cover->requester_user_id === null
                ? null
                : (int) $cover->requester_user_id,
            'source_home_branch_id' => (int) $cover->source_home_branch_id,
            'target_branch_id' => (int) $cover->target_branch_id,
            'starts_at' => $cover->starts_at?->toIso8601String(),
            'ends_at' => $cover->ends_at?->toIso8601String(),
            'status' => (string) $cover->status,
            'decided_by_user_id' => $cover->decided_by_user_id === null
                ? null
                : (int) $cover->decided_by_user_id,
            'decided_at' => $cover->decided_at?->toIso8601String(),
            'cancelled_by_user_id' => $cover->cancelled_by_user_id === null
                ? null
                : (int) $cover->cancelled_by_user_id,
            'cancelled_at' => $cover->cancelled_at?->toIso8601String(),
        ];
    }

    /**
     * Recognise a unique-constraint violation across both drivers the project
     * runs on: PostgreSQL in production, SQLite in the suite.
     *
     * Copied verbatim rather than shared, which is this codebase's convention.
     * SQLSTATE 23000 is deliberately NOT tested as a code: it is the generic
     * integrity-constraint class, so accepting it would misread a foreign-key or
     * not-null violation as a lost race and swallow a real bug.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        if ($exception->getCode() === '23505') {  // PostgreSQL unique_violation
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique constraint')
            || str_contains($message, 'unique violation')
            || str_contains($message, 'duplicate key');
    }

    /**
     * The evaluation instant every guard in one decision shares.
     *
     * Exposed so a surface can render the state it is about to act on with the
     * same instant the service will judge it by, instead of calling now() again
     * a few milliseconds later.
     */
    public function evaluationInstant(): CarbonInterface
    {
        return $this->clock->now();
    }
}
