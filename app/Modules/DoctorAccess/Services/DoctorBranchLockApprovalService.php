<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Services;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\DoctorAccess\Interfaces\DoctorBranchCoverRepositoryInterface;
use App\Modules\DoctorAccess\Interfaces\DoctorBranchLockRepositoryInterface;
use App\Modules\DoctorAccess\Interfaces\DoctorBranchLockRequestRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorBranchCover;
use App\Modules\DoctorAccess\Models\DoctorBranchLock;
use App\Modules\DoctorAccess\Models\DoctorBranchLockRequest;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use App\Modules\DoctorAccess\Support\DoctorAccessSubject;
use App\Modules\DoctorAccess\Support\DoctorBranchCoverPeriod;
use App\Modules\DoctorAccess\Support\DoctorBranchLockRequestType;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — owner decision O1's two
 * workflows, and the only writer of `mst_doctor_branch_locks`.
 *
 *   INITIAL ASSIGNMENT   UNSET -> approved home branch
 *   PERMANENT TRANSFER   home branch A -> approved home branch B
 *
 * One table, one transaction shape, told apart by `request_type` — which is
 * DERIVED server-side from the doctor's live lock, never supplied by a client.
 *
 * ── AN APPROVAL IS AN EVENT, NOT A CREDENTIAL ─────────────────────────────
 *
 * The lock is written INSIDE the approval transaction. There is no 'approved'
 * token a requester can carry to a second endpoint and replay, because there is
 * no second endpoint. Once the transaction commits the row is APPROVED with
 * `applied_at` set, and a second attempt finds a row that is no longer PENDING.
 * Single-use is a property of the design, not a counter somebody must remember
 * to decrement.
 *
 * ── EVERY BINDING IS RE-ASSERTED UNDER A LOCK ─────────────────────────────
 *
 *   REQUEST      `lockById()` first, so two approvers serialise and the second
 *                re-reads a decided row
 *   DOCTOR       `findForUpdate()` — active, not soft-deleted, LINKED. This is
 *                also the row lock that serialises cover approval for the same
 *                doctor, so a transfer and a cover cannot interleave
 *   TYPE         re-derived from the LIVE lock. A row that says INITIAL
 *                ASSIGNMENT whose doctor has since acquired a lock is stale
 *   SOURCE       for a transfer, the live lock must STILL sit on
 *                `source_branch_id`. If it moved the approval is refused rather
 *                than applied against a starting point the approver never saw
 *   DESTINATION  re-validated for active + RME at decision time. An approval
 *                never confers access to a branch the doctor could not
 *                otherwise work in, and never resurrects a deactivated one
 *   COVER        a transfer is refused while an ACTIVE cover exists (section Q)
 *   PRESENCE     read live, and an ONLINE doctor requires an acknowledgement
 *
 * THERE IS NO DAY BOUNDARY AND NO `EXPIRED` STATUS. The sibling
 * `trx_branch_change_requests` has one because that row belongs to a clinical
 * date. A permanent lock has no day; its freshness boundary is the stale-source
 * guard above, which is a re-derivation and not a timestamp. A later reader must
 * not 'restore' a state that was never missing.
 *
 * ── WHAT THIS SERVICE DOES NOT DO ─────────────────────────────────────────
 *
 * It touches no device, no `DoctorDeviceAuthorization` and no WebAuthn
 * credential. It ends the doctor's LOGIN SESSION and nothing else.
 *
 * It also does not read the feature flags. Whether the capability is armed
 * decides whether the SURFACE exists — the controllers 404 through
 * `DoctorEffectiveBranchResolver::enabled()`. Gating the service as well would
 * mean the rollout order the owner asked for (arm the flag, then assign one
 * doctor at a time) could not be exercised, and would put the same predicate in
 * two places, which is exactly the drift that predicate exists to prevent.
 */
class DoctorBranchLockApprovalService
{
    public const ENTITY_TYPE = 'trx_doctor_branch_lock_requests';

    /**
     * ITS OWN AUDIT ACTIONS (ruling P8). A branch decision is not a device
     * invalidation, and reusing `DOCTOR_SESSION_DEVICE_INVALIDATED` would make
     * the trail unreadable at exactly the moment somebody is trying to explain
     * to a doctor why their branch moved.
     */
    public const ACTION_REQUESTED = 'DOCTOR_BRANCH_LOCK_REQUESTED';

    public const ACTION_APPROVED = 'DOCTOR_BRANCH_LOCK_APPROVED';

    public const ACTION_REJECTED = 'DOCTOR_BRANCH_LOCK_REJECTED';

    public const ACTION_CANCELLED = 'DOCTOR_BRANCH_LOCK_CANCELLED';

    public function __construct(
        private readonly DoctorBranchLockRequestRepositoryInterface $requests,
        private readonly DoctorBranchLockRepositoryInterface $locks,
        private readonly DoctorBranchCoverRepositoryInterface $covers,
        private readonly BranchRepositoryInterface $branches,
        private readonly DoctorAccessSubjectGuard $subjects,
        private readonly AuditLogService $audit,
        private readonly ClinicalClock $clock,
    ) {}

    /**
     * File a request to set or move a doctor's home branch.
     *
     * The requester supplies three things: which doctor, where, and why.
     * `request_type`, `source_branch_id`, `status`, `requester_user_id` and
     * every decision stamp are derived server-side and have no `$fillable`
     * entry, so a forged value has nowhere to land.
     *
     * @throws ValidationException
     */
    public function request(
        User $actor,
        int $doctorId,
        int $destinationBranchId,
        string $reason,
    ): DoctorBranchLockRequest {
        // Unlocked on purpose: this is the friendly pre-check, and the approval
        // transaction re-asserts every one of these conditions under a lock.
        // Refusing here as well means an unlinked or deactivated doctor is
        // reported at once instead of at decision time (ruling P6).
        $subject = $this->subjects->assertRequestableSubject($doctorId);

        $currentHomeBranchId = $this->locks->lockedBranchIdFor($subject->doctorId());
        $requestType = DoctorBranchLockRequestType::deriveFrom($currentHomeBranchId);

        if ($currentHomeBranchId !== null && $currentHomeBranchId === $destinationBranchId) {
            throw ValidationException::withMessages([
                'destination_branch_id' => 'Cabang tujuan sama dengan cabang tetap dokter saat ini.',
            ]);
        }

        $this->assertEligibleDestination($destinationBranchId);

        try {
            // NESTED TRANSACTION -> the framework emits a SAVEPOINT, and the
            // catch sits OUTSIDE it so the rollback to savepoint has already
            // run by the time we handle the failure. On PostgreSQL a failed
            // statement aborts the WHOLE transaction, so catching a unique
            // violation and then running another query hands the caller a
            // poisoned connection raising 25P02 — on exactly the race this
            // handler exists for. It passes on SQLite, which is why three
            // pre-existing sites in this codebase carry the broken shape
            // undetected.
            $request = DB::transaction(fn (): DoctorBranchLockRequest => $this->requests->create([
                'doctor_id' => $subject->doctorId(),
                'requester_user_id' => (int) $actor->id,
                'request_type' => $requestType,
                'source_branch_id' => $currentHomeBranchId,
                'destination_branch_id' => $destinationBranchId,
                'reason' => $reason,
                'requested_at' => now(),
            ]));
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            // `trx_doctor_branch_lock_req_pending_uq` refused a second PENDING
            // row for this doctor — including the double-submit an
            // application-level check would have raced straight through. The
            // message names what is blocking, because one-pending-per-doctor is
            // otherwise a support call: the existing request has to be decided
            // or cancelled first.
            throw ValidationException::withMessages([
                'doctor_id' => 'Dokter ini sudah memiliki permintaan kunci cabang yang menunggu '
                    .'persetujuan. Putuskan atau batalkan permintaan tersebut terlebih dahulu.',
            ]);
        }

        $this->audit->log(
            self::ENTITY_TYPE,
            (int) $request->id,
            self::ACTION_REQUESTED,
            null,
            $this->auditPayload($request),
            $actor,
        );

        return $request;
    }

    /**
     * Approve the request AND apply the lock, atomically.
     *
     * THE ORDER OF THE GUARDS IS THE DESIGN. Each one is listed in the class
     * docblock; each has its own message because each has its own remedy; and
     * the two self-approval comparisons run FIRST, inside the transaction,
     * before anything is written.
     *
     * @param  bool  $acknowledgeOnlineImpact  the approver has been shown, and
     *                                         accepted, that an online doctor
     *                                         will lose their session
     *
     * @throws ValidationException
     */
    public function approve(
        int $requestId,
        User $approver,
        ?string $decisionNote = null,
        bool $acknowledgeOnlineImpact = false,
    ): DoctorBranchLockRequest {
        return DB::transaction(function () use (
            $requestId,
            $approver,
            $decisionNote,
            $acknowledgeOnlineImpact,
        ): DoctorBranchLockRequest {
            $request = $this->lockPendingRequest($requestId, $approver);

            // THE DOCTOR ROW LOCK. Everything below is serialised on it,
            // including any cover decision racing this transfer.
            $subject = $this->subjects->lockDecidableSubject((int) $request->doctor_id);

            $this->assertNotTheSubject($subject, $approver);

            $lock = $this->locks->lockForDoctor($subject->doctorId());
            $liveHomeBranchId = $lock?->home_branch_id === null ? null : (int) $lock->home_branch_id;

            $this->assertNotStale($request, $lock, $liveHomeBranchId);

            $destinationBranchId = (int) $request->destination_branch_id;
            $this->assertEligibleDestination($destinationBranchId);
            $this->assertWithinPracticeBranches($subject, $destinationBranchId);

            // THE FRAME IS PART OF THE RULE, AND GETTING IT WRONG SILENTLY
            // DISARMS THE GUARD BELOW. `starts_at` and `ends_at` are INSTANTS in
            // the application's UTC frame — DoctorBranchCoverPeriod normalises
            // every write to UTC precisely so that every reader can assume it —
            // and `Connection::prepareBindings()` formats a DateTimeInterface
            // binding using the timezone the object is CARRYING, not the
            // application's. A ClinicalClock reading is the same instant on the
            // WITA wall clock, so it binds a string eight hours ahead of the
            // column and `starts_at <= now < ends_at` matches nothing: the cover
            // block returned normally against a cover that was plainly in force.
            //
            // So this is CarbonImmutable::now(), for the same reason
            // DoctorEffectiveBranchResolver documents at its own call to this
            // predicate: comparing an instant to an instant is timezone-agnostic
            // only while both sides are in one frame. The clinical clock stays
            // the authority where a WALL CLOCK is meant — parsing the period an
            // approver types, and rendering the refusal back to them, which is
            // what assertNoActiveCover() still uses it for.
            //
            // Carbon::setTestNow() controls this, so the tests still drive it.
            $now = CarbonImmutable::now();

            // SECTION Q — a permanent transfer must not create an ambiguous
            // effective branch. See assertNoActiveCover() for the exact rule.
            // It sits HERE, after lockDecidableSubject() and lockForDoctor(),
            // so a cover decision racing this transfer is already serialised on
            // the doctor row and cannot land between this read and the write.
            $this->assertNoActiveCover($subject, $now);
            $scheduledCover = $this->firstScheduledCover($subject->doctorId(), $now);

            $online = $this->subjects->isOnline($subject);
            $this->subjects->assertOnlineImpactAcknowledged($online, $acknowledgeOnlineImpact);

            $before = $this->auditPayload($request) + [
                'live_home_branch_id' => $liveHomeBranchId,
                'doctor_online_at_decision' => $online,
            ];

            $isTransfer = $liveHomeBranchId !== null;

            $this->locks->assign(
                $subject->doctorId(),
                $destinationBranchId,
                (int) $approver->id,
                $isTransfer
                    ? DoctorBranchLock::VIA_TRANSFER
                    : DoctorBranchLock::VIA_INITIAL_ASSIGNMENT,
            );

            // Requirement 4 and owner decision O1: an approved change
            // invalidates the doctor's active session and forces a fresh login.
            // Room first, then lease, and no device row is touched. The reason
            // vocabulary on the lease is closed, and BRANCH_TRANSFER_APPROVED is
            // its lock-decision member — an initial assignment reports the same
            // reason, and the audit payload below carries the `request_type`
            // that tells the two apart.
            $this->subjects->endWorkingSession(
                $subject,
                DoctorSessionLease::RELEASE_BRANCH_TRANSFER_APPROVED,
                $approver,
            );

            $approved = $this->requests->update($request, [
                'status' => DoctorBranchLockRequest::STATUS_APPROVED,
                'decided_by_user_id' => (int) $approver->id,
                'decided_at' => now(),
                'decision_note' => $decisionNote,
                'applied_at' => now(),
            ]);

            $this->audit->log(
                self::ENTITY_TYPE,
                (int) $approved->id,
                self::ACTION_APPROVED,
                $before,
                $this->auditPayload($approved) + [
                    'live_home_branch_id' => $destinationBranchId,
                    // RULING P11, PERSISTED WITHOUT A COLUMN. There is no
                    // `doctor_online_at_decision` field on this table and this
                    // sprint adds no migration for one, so the fact that an
                    // approver evicted a working doctor — and acknowledged it —
                    // is recorded here, where an incident can still reconstruct
                    // it.
                    'doctor_online_at_decision' => $online,
                    'online_impact_acknowledged' => $acknowledgeOnlineImpact,
                    // A future-dated cover does not block the transfer, but the
                    // approver was warned about it and the trail says which one.
                    'scheduled_cover_id' => $scheduledCover === null ? null : (int) $scheduledCover->id,
                    'session_released' => true,
                ],
                $approver,
            );

            return $approved;
        });
    }

    /**
     * Reject the request. The home lock is untouched and NO session is released
     * — nothing about the doctor's authority changed, so evicting them would be
     * a punishment for somebody else's paperwork.
     *
     * @throws ValidationException
     */
    public function reject(
        int $requestId,
        User $approver,
        ?string $decisionNote = null,
    ): DoctorBranchLockRequest {
        // A REJECTION REQUIRES A REASON, and an APPROVAL does not. The
        // asymmetry is the requirement, not an oversight: an approval is
        // self-describing — the destination branch and the applied timestamp say
        // what happened — while a refusal leaves only a status, and the person
        // who has to re-file the request is the one person who cannot see why.
        //
        // Enforced BEFORE the transaction opens, so a refusal costs no row lock.
        $decisionNote = $this->requireRejectionReason($decisionNote);

        return DB::transaction(function () use ($requestId, $approver, $decisionNote): DoctorBranchLockRequest {
            $request = $this->lockPendingRequest($requestId, $approver);

            // Locked and re-asserted even though nothing is written to the
            // doctor: a rejection recorded against a deleted or unlinked record
            // is a decision about a row nobody can act on, and the approver
            // deserves to be told that rather than to see it succeed.
            $subject = $this->subjects->lockDecidableSubject((int) $request->doctor_id);
            $this->assertNotTheSubject($subject, $approver);

            $before = $this->auditPayload($request);

            $rejected = $this->requests->update($request, [
                'status' => DoctorBranchLockRequest::STATUS_REJECTED,
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
     * The requester withdraws their own pending request.
     *
     * Only the row's own `requester_user_id`, and only while PENDING. This is
     * not an approval path and grants nothing, so it takes no doctor lock and
     * releases no session.
     *
     * @throws ValidationException
     */
    public function cancel(int $requestId, User $actor): DoctorBranchLockRequest
    {
        return DB::transaction(function () use ($requestId, $actor): DoctorBranchLockRequest {
            $request = $this->requests->lockById($requestId);

            if ($request === null || (int) $request->requester_user_id !== (int) $actor->id) {
                throw ValidationException::withMessages([
                    'request' => 'Permintaan tidak ditemukan.',
                ]);
            }

            if (! $request->isPending()) {
                throw ValidationException::withMessages([
                    'request' => 'Permintaan ini sudah diputuskan dan tidak dapat dibatalkan.',
                ]);
            }

            $before = $this->auditPayload($request);

            $cancelled = $this->requests->update($request, [
                'status' => DoctorBranchLockRequest::STATUS_CANCELLED,
                'decided_at' => now(),
            ]);

            $this->audit->log(
                self::ENTITY_TYPE,
                (int) $cancelled->id,
                self::ACTION_CANCELLED,
                $before,
                $this->auditPayload($cancelled),
                $actor,
            );

            return $cancelled;
        });
    }

    /**
     * Read a PENDING request under a row lock, with the FIRST self-approval
     * comparison applied.
     *
     * SELF-APPROVAL BOUNDARY, PART ONE OF TWO. This one compares the REQUESTER
     * with the approver. It is placed here — in the service, inside the
     * transaction — and not only in the policy, because the single global
     * `Gate::before` returns true for a Super Admin BEFORE any policy method
     * runs, so a clause written only in a policy never executes for the one
     * actor who could be both parties.
     *
     * Part two, {@see self::assertNotTheSubject()}, is the comparison the
     * familiar pattern does not have and cannot be moved up here: it needs the
     * locked doctor row.
     *
     * The requester comparison is ordered BEFORE the status re-assert so a
     * self-approval and an already-decided row each get their own message.
     *
     * @throws ValidationException
     */
    private function lockPendingRequest(int $requestId, User $approver): DoctorBranchLockRequest
    {
        $request = $this->requests->lockById($requestId);

        if ($request === null) {
            throw ValidationException::withMessages([
                'request' => 'Permintaan tidak ditemukan.',
            ]);
        }

        if ((int) $request->requester_user_id === (int) $approver->id) {
            throw ValidationException::withMessages([
                'request' => 'Anda tidak dapat menyetujui permintaan kunci cabang yang Anda ajukan sendiri.',
            ]);
        }

        // RE-ASSERTED AFTER THE LOCK, which is the whole point of taking it:
        // two approvers deciding the same request serialise here, and the second
        // one reads a row that is no longer PENDING.
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'request' => 'Permintaan ini sudah diputuskan.',
            ]);
        }

        return $request;
    }

    /**
     * SELF-APPROVAL BOUNDARY, PART TWO OF TWO — and the one a reviewer misses.
     *
     * The subject of a branch lock is a DOCTOR, identified by
     * `mst_doctors.user_id`, and they need never have filed the request. An
     * approver who is themself the subject therefore passes the requester
     * comparison entirely. Only this comparison closes it, and it can only run
     * after the doctor row has been read under the lock.
     *
     * @throws ValidationException
     */
    private function assertNotTheSubject(DoctorAccessSubject $subject, User $approver): void
    {
        if ($subject->isActedOnBy($approver)) {
            throw ValidationException::withMessages([
                'request' => 'Anda tidak dapat memutuskan kunci cabang untuk akun dokter Anda sendiri.',
            ]);
        }
    }

    /**
     * A rejection nobody can explain is not one.
     *
     * DELIBERATELY THE SAME SHAPE AS
     * {@see DoctorBranchCoverApprovalService::requireReason()}, down to the
     * config key and the minimum: one rule about explaining a refusal, expressed
     * once per service and never twice per service. Enforced in the SERVICE
     * rather than as a conditional FormRequest rule, so an HTTP caller and a
     * console caller cannot diverge on whether the reason was required — the
     * FormRequest mirrors it only to produce a better message on the form.
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
     * THE STALE-SOURCE GUARD, which is this workflow's entire freshness
     * boundary — there is no clinical day here to expire against.
     *
     * The workflow is RE-DERIVED from the live lock and compared with what the
     * row says. Two things are therefore caught with one comparison:
     *
     *  - an INITIAL ASSIGNMENT whose doctor has acquired a home lock since the
     *    request was filed (somebody else's assignment landed first), and
     *  - a TRANSFER whose doctor's lock has been released — which cannot happen
     *    today, but would silently become an assignment if it ever could.
     *
     * The source branch is then compared for a transfer, so an approval is never
     * applied against a starting point the approver never saw.
     *
     * @throws ValidationException
     */
    private function assertNotStale(
        DoctorBranchLockRequest $request,
        ?DoctorBranchLock $lock,
        ?int $liveHomeBranchId,
    ): void {
        $liveType = DoctorBranchLockRequestType::deriveFrom($liveHomeBranchId);

        $stale = $liveType !== (string) $request->request_type;

        if (! $stale && $lock !== null) {
            $stale = $liveHomeBranchId !== ($request->source_branch_id === null
                ? null
                : (int) $request->source_branch_id);
        }

        if ($stale) {
            throw ValidationException::withMessages([
                'request' => 'Permintaan tidak lagi valid karena kunci cabang dokter telah berubah. '
                    .'Minta pengaju mengajukan ulang.',
            ]);
        }
    }

    /**
     * SECTION Q, STATED EXACTLY.
     *
     * A permanent lock decision is REFUSED while an APPROVED, uncancelled cover
     * satisfies `starts_at <= now < ends_at` for this doctor — the half-open
     * interval, evaluated on the same instant the rest of this transaction uses.
     * Moving the home branch underneath a cover that is currently in force would
     * leave two defensible answers to 'which branch is this doctor working
     * from', which is the ambiguity section Q forbids.
     *
     * A merely SCHEDULED (future-dated) cover does NOT block. Section Q blocks
     * only while a cover IS active, and a future cover creates no ambiguity
     * about the CURRENT effective branch; the approver surface warns about it
     * and the audit payload records its id.
     *
     * STRUCTURALLY THIS CAN ONLY FIRE ON A TRANSFER — a cover requires a home
     * lock, so an UNSET doctor can never hold one — but the check is
     * unconditional rather than gated on `request_type`, because gating it would
     * make it depend on the same derived value the stale guard is busy
     * validating.
     *
     * `$now` MUST BE AN INSTANT IN THE STORED FRAME, not a clinical wall-clock
     * reading of it. This guard was dead for exactly that reason once, and the
     * caller above says why; DoctorBranchCoverRepository::asStoredInstant() now
     * closes it at the boundary as well. The clinical clock is still what renders
     * `$ends` below, because that half IS a wall clock the approver reads.
     *
     * THE ESCAPE HATCH IS NAMED IN THE MESSAGE ON PURPOSE. A 90-day cover would
     * otherwise read as a wall to an operator who genuinely has to move the
     * doctor's home branch now. Cancelling the cover is behind the same
     * permission as this decision.
     *
     * @throws ValidationException
     */
    private function assertNoActiveCover(DoctorAccessSubject $subject, CarbonInterface $now): void
    {
        $cover = $this->covers->activeApprovedForDoctor($subject->doctorId(), $now);

        if ($cover === null) {
            return;
        }

        $ends = DoctorBranchCoverPeriod::formatInClinicalZone($cover->ends_at, $this->clock);

        throw ValidationException::withMessages([
            'request' => 'Dokter sedang dalam periode cover sementara'
                .($ends === null ? '' : ' sampai '.$ends)
                .'. Selesaikan atau batalkan cover tersebut sebelum memindahkan cabang tetap.',
        ]);
    }

    /**
     * The earliest approved cover that has not started yet, for the warning and
     * the audit trail. It never blocks anything.
     */
    private function firstScheduledCover(int $doctorId, CarbonInterface $now): ?DoctorBranchCover
    {
        return $this->covers->approvedForDoctor($doctorId)
            ->first(fn (DoctorBranchCover $cover): bool => $cover->starts_at !== null
                && $cover->starts_at->greaterThan($now));
    }

    /**
     * An approval never confers access to a branch the doctor could not
     * otherwise work in, and never resurrects a deactivated one. Re-run at
     * decision time because a branch that was eligible when the request was
     * filed may not be when it is approved.
     *
     * @throws ValidationException
     */
    private function assertEligibleDestination(int $branchId): void
    {
        $branch = $this->branches->findById($branchId);

        if ($branch === null || ! $branch->is_active || ! $branch->is_rme_enabled) {
            throw ValidationException::withMessages([
                'destination_branch_id' => 'Cabang tujuan harus cabang RME yang aktif.',
            ]);
        }
    }

    /**
     * THE STRANDING GUARD (open item V2/V1).
     *
     * `UserOnlineContextService::startDoctorSession()` refuses a branch that is
     * not in the doctor's `mst_doctor_branches` practice pivot, and that refusal
     * runs BEFORE the locked-branch assert. So a home lock naming a branch the
     * doctor does not practise at does not narrow the doctor — it STOPS them:
     * every branch they could otherwise have gone online at is now outside their
     * lock, and the one their lock names is outside their practice pivot. They
     * cannot go online at all.
     *
     * THE FIX BELONGS HERE, NOT THERE. Relaxing the practice-branch check would
     * turn an approval into a way to reach a branch the doctor was never
     * entitled to work in — the exact widening owner decision O1 forbids. So the
     * approval refuses instead, and names the remedy.
     *
     * Evaluated inside the transaction, under the doctor row lock already held
     * by the caller, so the pivot cannot change between this read and the write.
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
            'destination_branch_id' => 'Cabang tujuan belum termasuk Cabang Praktik dokter ini, '
                .'sehingga dokter tidak akan dapat online di cabang mana pun. '
                .'Tambahkan cabang tersebut ke Cabang Praktik dokter di Master Data Dokter '
                .'terlebih dahulu, lalu setujui ulang permintaan ini.',
        ]);
    }

    /**
     * Audit payload — ids, branches, type, status and the decision.
     *
     * NO patient data, no doctor name, no KTP/NIK, no device name, no IP and no
     * session id. The trail explains a branch decision; it is not a second copy
     * of the clinical record, and it must not leak another actor's device or
     * session.
     *
     * @return array<string, mixed>
     */
    private function auditPayload(DoctorBranchLockRequest $request): array
    {
        return [
            'request_id' => (int) $request->id,
            'doctor_id' => (int) $request->doctor_id,
            'requester_user_id' => $request->requester_user_id === null
                ? null
                : (int) $request->requester_user_id,
            'request_type' => (string) $request->request_type,
            'source_branch_id' => $request->source_branch_id === null
                ? null
                : (int) $request->source_branch_id,
            'destination_branch_id' => (int) $request->destination_branch_id,
            'status' => (string) $request->status,
            'decided_by_user_id' => $request->decided_by_user_id === null
                ? null
                : (int) $request->decided_by_user_id,
            'applied_at' => $request->applied_at?->toIso8601String(),
        ];
    }

    /**
     * Recognise a unique-constraint violation across both drivers the project
     * runs on: PostgreSQL in production, SQLite in the suite.
     *
     * Copied verbatim rather than shared, which is this codebase's convention
     * for this helper. SQLSTATE 23000 is deliberately NOT tested as a code: it
     * is the generic integrity-constraint class, so accepting it would misread a
     * foreign-key or not-null violation as a lost race and swallow a real bug.
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
}
