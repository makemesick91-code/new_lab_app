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
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the things every decision ABOUT
 * a doctor must do identically, in one place.
 *
 * A decision that ends a doctor's working session needs the same sequence every
 * time: lock the doctor row, prove the record is decidable, read live presence,
 * and — if the decision goes through — free the room BEFORE releasing the
 * session. Written out once per caller, one of those copies eventually drifts,
 * and the copy that drifts is the one that leaves a clinic room occupied by a
 * doctor who has been logged out.
 *
 * ONE CALLER TODAY, AND THE SEAM IS STILL WORTH ITS KEEP. The manual session
 * release is the only decision in this pull request; every method below has that
 * caller and there is nothing here waiting for a second one. The value is that
 * the ordering rule lives beside the lock that makes it safe, rather than inside
 * a service that also has an operator interface to worry about.
 *
 * ── WHY THE DOCTOR ROW LOCK LIVES HERE ────────────────────────────────────
 *
 * `DoctorRepositoryInterface::findForUpdate()` is a real `SELECT … FOR UPDATE`
 * on `mst_doctors`. It is not defensive decoration: it is the serialisation
 * point for EVERY decision about one doctor, so two operators releasing the
 * same doctor's session at the same instant are ordered rather than
 * interleaved, and the presence read that decides what to report cannot be
 * taken between another actor's read and its write.
 *
 * On SQLite `lockForUpdate()` compiles to an empty string, so the local suite
 * proves the logic and only the PostgreSQL critical gate proves the
 * concurrency.
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
     *    removed doctor is not one whose session anybody needs to end.
     *  - INACTIVE. Read at decision time and never trusted from an earlier
     *    screen (ruling P10).
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
     * Both writes run inside the CALLER'S transaction, so freeing the room and
     * releasing the lease commit together or not at all.
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
