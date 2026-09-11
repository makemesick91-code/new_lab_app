<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Services;

use App\Models\User;
use App\Modules\Doctor\Interfaces\DoctorRepositoryInterface;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use App\Modules\DoctorAccess\Support\DoctorAccessSubject;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Illuminate\Validation\ValidationException;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the three things every decision
 * about a doctor must do identically, in one place.
 *
 * Lock-assignment approval, transfer approval, cover approval, cover
 * cancellation and the approver's manual session release all need the same
 * sequence: lock the doctor row, prove the record is decidable, read live
 * presence, and — if the decision goes through — free the room BEFORE releasing
 * the session. Written out five times, one of those copies eventually drifts,
 * and the copy that drifts is the one that leaves a clinic room occupied by a
 * doctor who has been logged out.
 *
 * ── WHY THE DOCTOR ROW LOCK LIVES HERE ────────────────────────────────────
 *
 * `DoctorRepositoryInterface::findForUpdate()` is a real `SELECT … FOR UPDATE`
 * on `mst_doctors`. It is not defensive decoration: it is the serialisation
 * point for EVERY decision about one doctor, and because a unique index compares
 * values while overlap is a range predicate, it is the only thing on either
 * engine that stops two approvers granting overlapping covers. That was
 * confirmed by attempting the insert — identical approved periods are refused by
 * an index, merely OVERLAPPING ones are accepted — so no reader may assume a
 * database backstop exists.
 *
 * On SQLite `lockForUpdate()` compiles to an empty string, so the local suite
 * proves the logic and only the PostgreSQL critical gate proves the concurrency.
 *
 * The nearest existing analogue, `DoctorDeviceAuthorizationService::approve()`,
 * loads its doctor with an UNLOCKED `Doctor::query()->find()`. This sprint
 * deliberately does better (ruling P10).
 *
 * ── WHAT THIS CLASS MUST NEVER TOUCH ──────────────────────────────────────
 *
 * `mst_doctor_devices`, `mst_doctor_device_authorizations` and
 * `trx_doctor_device_webauthn_credentials`. Ending a login session is not
 * withdrawing trust from a tablet, and WebAuthn revocation is irreversible.
 * Releasing a lease is data: the doctor keeps working until their browser makes
 * another request, at which point the sibling middleware finds no lease behind
 * their token and tears that session down.
 */
class DoctorAccessSubjectGuard
{
    public function __construct(
        private readonly DoctorRepositoryInterface $doctors,
        private readonly UserOnlineContextService $presence,
        private readonly DoctorSessionLeaseService $leases,
    ) {}

    /**
     * Read a doctor WITHOUT a lock and prove it is worth filing against.
     *
     * The filing-time twin of {@see self::lockDecidableSubject()}. It refuses
     * the same three conditions so a requester is told at once, rather than
     * discovering at decision time that the row was never actionable — but it
     * is explicitly NOT the boundary, because nothing here is serialised.
     *
     * @throws ValidationException
     */
    public function assertRequestableSubject(int $doctorId): DoctorAccessSubject
    {
        return $this->assertDecidable($this->doctors->findById($doctorId));
    }

    /**
     * Lock the doctor row and prove the decision may proceed against it.
     *
     * MUST be called inside a transaction — the lock is worthless outside one,
     * and every caller in this module opens the transaction itself.
     *
     * Three refusals, each with its own message because each has a different
     * remedy:
     *
     *  - MISSING. `findForUpdate()` queries through the model's SoftDeletes
     *    global scope, so a soft-deleted doctor reads as absent. Correct: a
     *    removed doctor is not a doctor whose branch anybody should be moving.
     *  - INACTIVE. Re-read at decision time, not trusted from filing time
     *    (ruling P10) — a doctor deactivated while the request sat in the queue
     *    must not be silently reactivated into a branch.
     *  - UNLINKED. Ruling P6. Refused at BOTH ends, and the wording is the one
     *    the doctor-performance hotfix already uses, so operators see one
     *    message for one condition.
     *
     * @throws ValidationException
     */
    public function lockDecidableSubject(int $doctorId): DoctorAccessSubject
    {
        return $this->assertDecidable($this->doctors->findForUpdate($doctorId));
    }

    /**
     * The same lock and the same three checks, but answering null instead of
     * refusing.
     *
     * FOR DE-ESCALATION PATHS ONLY. Cancelling a cover REMOVES authority, and a
     * removal must never be blockable: if the doctor record were soft-deleted,
     * deactivated or unlinked while an approved cover was in force, throwing
     * here would strand that cover with no way to end it — and, because an
     * active cover blocks a permanent transfer, would jam both workflows at
     * once. A null subject means the cancellation proceeds and the eviction is
     * skipped, which is the honest outcome: an unlinked or removed doctor has no
     * session to end. The caller records that it was skipped.
     *
     * It still takes the row lock whenever there is a row to lock, so the
     * serialisation this module depends on is unaffected.
     */
    public function tryLockDecidableSubject(int $doctorId): ?DoctorAccessSubject
    {
        try {
            return $this->lockDecidableSubject($doctorId);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Is the subject doctor working right now?
     *
     * Read INSIDE the approval transaction, never carried in from the screen.
     * An approver surface rendered ten minutes ago cannot be allowed to approve
     * past a doctor who has come online since it was drawn.
     */
    public function isOnline(DoctorAccessSubject $subject): bool
    {
        return $this->presence->isDoctorOnline($subject->user);
    }

    /**
     * Ruling P11 — the confirmation boundary.
     *
     * The checkbox on the approve form is UI. THIS is the check. An approval
     * ends the doctor's session, and a doctor mid-consultation can lose unsaved
     * handwriting RM or odontogram input that the system cannot save on their
     * behalf. So when the subject is ONLINE an acknowledgement is required, and
     * it is required against presence read a moment ago rather than against
     * presence rendered on a stale page.
     *
     * A tampered `acknowledge_online_impact` therefore only lets an approver
     * skip a warning they have already been shown. It never skips a check.
     *
     * @throws ValidationException
     */
    public function assertOnlineImpactAcknowledged(bool $online, bool $acknowledged): void
    {
        if (! $online || $acknowledged) {
            return;
        }

        throw ValidationException::withMessages([
            'acknowledge_online_impact' => 'Dokter sedang online. Centang konfirmasi bahwa sesi dokter '
                .'akan diakhiri dan input rekam medis tulisan tangan atau odontogram yang belum '
                .'disimpan dapat hilang.',
        ]);
    }

    /**
     * End the doctor's working session, in the ONE order that is safe.
     *
     * ROOM FIRST, THEN LEASE (ruling P5). `markOffline()` nulls
     * `clinic_room_id` for a doctor context, so an evicted doctor does not leave
     * a consultation room marked occupied and blocking the next clinician. Doing
     * it after the lease release would be a race against the doctor's own next
     * request, which refreshes presence.
     *
     * `markOffline()` deliberately returns early when there is no online-context
     * row, writing nothing. That is correct and must not be 'fixed' into a
     * create, which would bring an offline doctor online as a side effect of
     * logging them out.
     *
     * REALIGNMENT IS DELIBERATELY NOT USED HERE. The sibling daily-lock service
     * repoints an operator's context at the new branch instead. A doctor's
     * context carries a ROOM, and that room belongs to the OLD branch, so
     * realigning the branch alone would leave an incoherent branch/room pair.
     * The doctor re-selects both.
     *
     * Both writes run inside the CALLER'S transaction, so the branch decision
     * and the eviction commit together or not at all.
     *
     * @param  string  $reason  one of DoctorSessionLease::RELEASE_REASONS
     * @return DoctorSessionLease|null the lease that was released, or null when
     *                                 the doctor held none. Null is an ordinary
     *                                 answer, not a failure: the room is freed
     *                                 either way, and a caller reporting to an
     *                                 operator can say there was nothing to
     *                                 clear rather than claiming a release that
     *                                 never happened.
     */
    public function endWorkingSession(
        DoctorAccessSubject $subject,
        string $reason,
        ?User $actor = null,
    ): ?DoctorSessionLease {
        $this->presence->markOffline($subject->user);

        return $this->leases->releaseFor($subject->userId(), $reason, $actor);
    }

    /**
     * @throws ValidationException
     */
    private function assertDecidable(?Doctor $doctor): DoctorAccessSubject
    {
        if ($doctor === null) {
            throw ValidationException::withMessages([
                'doctor_id' => 'Data dokter tidak ditemukan.',
            ]);
        }

        if ($doctor->is_active !== true) {
            throw ValidationException::withMessages([
                'doctor_id' => 'Dokter ini tidak aktif.',
            ]);
        }

        $userId = $doctor->user_id === null ? null : (int) $doctor->user_id;
        $user = $userId === null ? null : User::query()->find($userId);

        if ($user === null) {
            throw ValidationException::withMessages([
                'doctor_id' => 'Akun dokter belum terhubung ke data dokter. '
                    .'Hubungi admin untuk menghubungkan user ke master dokter.',
            ]);
        }

        return new DoctorAccessSubject($doctor, $user);
    }
}
