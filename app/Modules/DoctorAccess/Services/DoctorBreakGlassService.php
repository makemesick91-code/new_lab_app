<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Services;

use App\Models\User;
use App\Modules\Doctor\Services\DoctorIdentityResolver;
use App\Modules\DoctorAccess\Interfaces\DoctorBreakGlassGrantRepositoryInterface;
use App\Modules\DoctorAccess\Interfaces\DoctorSessionLeaseRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorBreakGlassGrant;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1 Stage 2 — bounded emergency
 * admission, and the only thing allowed to write one.
 *
 * WHAT A GRANT IS, AND IS NOT.
 *
 * It admits ONE named doctor account to a session without a trusted-device
 * assertion, for a bounded window, with a written reason, revocably, and it is
 * audited at every transition. It is NOT a return to password-only login for
 * the fleet, and it does not disable enforcement: every other doctor keeps
 * needing the device exactly as before, which is the difference between an
 * emergency path and a rollback.
 *
 * It confers NO permission, NO role and NO branch. The only question it
 * answers is "may this account hold a session without a device right now",
 * and DoctorAppLoginGate is the only thing that asks it.
 *
 * WHAT IS DELIBERATELY NOT BUILT HERE.
 *
 * No shared emergency password, no secret in a URL, no client-side flag, no
 * stored `is_active` boolean, and nothing that turns the global enforcement
 * switch off. Each of those is a permanent second way in wearing an emergency
 * label; the bound below is what keeps "emergency" true.
 *
 * THIS IS NOT MAKER/CHECKER, AND IS NOT DESCRIBED AS SUCH.
 *
 * One authorized approver files a grant. That follows the branch-cover
 * precedent in this module — a single approval permission made safe by a hard
 * bound, rather than a two-actor ceremony that nobody can complete at 02:00
 * when a tablet has just died. The safety comes from `max_hours`, the written
 * reason, revocability and the audit trail, not from a second signature.
 */
class DoctorBreakGlassService
{
    public const ENTITY = 'trx_doctor_break_glass_grants';

    public const ACTION_GRANTED = 'DOCTOR_BREAK_GLASS_GRANTED';

    public const ACTION_REVOKED = 'DOCTOR_BREAK_GLASS_REVOKED';

    public const ACTION_FIRST_USED = 'DOCTOR_BREAK_GLASS_FIRST_USED';

    public function __construct(
        private readonly DoctorBreakGlassGrantRepositoryInterface $grants,
        private readonly DoctorIdentityResolver $doctors,
        private readonly AuditLogService $auditLogs,
        private readonly DoctorSessionLeaseRepositoryInterface $leases,
        private readonly DoctorSessionReleaseService $sessionReleases,
    ) {}

    /**
     * The gate's read. Null means "no emergency admission", which is the
     * normal answer on every request of every doctor.
     */
    public function activeFor(User $user): ?DoctorBreakGlassGrant
    {
        return $this->grants->activeForUser((int) $user->id, Carbon::now());
    }

    /**
     * File a bounded emergency grant for one doctor account.
     *
     * Every bound is re-asserted INSIDE the transaction as well as in the
     * FormRequest. A grant filed while `max_hours` was wider must not become
     * committable after an operator narrows it — the second read is what makes
     * narrowing the bound take effect on work already in flight.
     */
    public function grant(User $doctorAccount, User $actor, string $reason, int $hours): DoctorBreakGlassGrant
    {
        $reason = trim($reason);

        $this->assertActorMayGrant($actor);
        $this->assertTargetIsDoctorAccount($doctorAccount);
        $this->assertReason($reason);
        $this->assertWindow($hours);

        return DB::transaction(function () use ($doctorAccount, $actor, $reason, $hours) {
            $now = Carbon::now();

            // Re-assert under the lock. Two approvers reacting to the same
            // incident must not stack two windows into one longer one.
            $incumbent = $this->grants->activeForUserForUpdate((int) $doctorAccount->id, $now);

            if ($incumbent !== null) {
                throw ValidationException::withMessages([
                    'user_id' => 'Dokter ini sudah memiliki akses darurat yang masih aktif sampai '
                        .$incumbent->expires_at->format('d/m/Y H:i')
                        .'. Cabut akses tersebut lebih dulu jika ingin menggantinya.',
                ]);
            }

            // Bounds, again, against the values as they are NOW.
            $this->assertWindow($hours);
            $this->assertReason($reason);

            $doctor = $this->doctors->resolveForUser($doctorAccount);

            $grant = $this->grants->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $doctorAccount->id,
                'doctor_id' => $doctor?->id,
                'reason' => $reason,
                'granted_by' => $actor->id,
                'granted_at' => $now,
                'expires_at' => $now->copy()->addHours($hours),
            ]);

            $this->auditLogs->log(self::ENTITY, (int) $grant->id, self::ACTION_GRANTED, null, [
                'user_id' => $grant->user_id,
                'doctor_id' => $grant->doctor_id,
                'granted_by' => $grant->granted_by,
                'expires_at' => $grant->expires_at?->toIso8601String(),
                'window_hours' => $hours,
                'reason' => $grant->reason,
            ], $actor);

            return $grant;
        });
    }

    /**
     * End a grant before its window closes.
     *
     * Idempotent: revoking an already-revoked grant returns it untouched
     * rather than rewriting who ended it and when, because the first
     * revocation is the one that happened.
     */
    public function revoke(DoctorBreakGlassGrant $grant, User $actor, string $reason): DoctorBreakGlassGrant
    {
        $reason = trim($reason);

        $this->assertActorMayGrant($actor);
        $this->assertReason($reason);

        return DB::transaction(function () use ($grant, $actor, $reason) {
            $locked = $this->grants->findForUpdate((int) $grant->id);

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'grant' => 'Akses darurat tidak ditemukan.',
                ]);
            }

            if ($locked->isRevoked()) {
                return $locked;
            }

            $before = ['state' => $locked->state()];

            $updated = $this->grants->update($locked, [
                'revoked_at' => Carbon::now(),
                'revoked_by' => $actor->id,
                'revoked_reason' => $reason,
            ]);

            // FIX-SUNU-GO-LIVE-BLOCKERS-1 / B6 — ending the grant is only half
            // of ending the access. Revocation denies the NEXT request, but the
            // session lease the emergency window created stayed open, and under
            // `doctor.single_active_session` an open lease is an incumbent: the
            // doctor could no longer log in by ANY path until somebody released
            // it by hand. The 2026-09-18 production drill left exactly that —
            // a lease open for ~18 hours after a 72-second grant.
            //
            // Inside the SAME transaction on purpose: "grant revoked but lease
            // still held" is the precise partial state this must never produce.
            $leaseReleased = $this->releaseEmergencyLease($locked, $actor, $reason);

            $this->auditLogs->log(self::ENTITY, (int) $updated->id, self::ACTION_REVOKED, $before, [
                'state' => $updated->state(),
                'revoked_by' => $updated->revoked_by,
                'reason' => $updated->revoked_reason,
                // Stated either way so the trail distinguishes "nothing to
                // release" from "release skipped", instead of staying silent.
                'session_lease_released' => $leaseReleased,
            ], $actor);

            return $updated;
        });
    }

    /**
     * Release the session lease THIS emergency window created — and nothing else.
     *
     * Scoped deliberately, because the destructive mistake here is logging out
     * a session the grant never produced:
     *
     *  - a grant that was never used carried no session, so there is nothing of
     *    its making to release;
     *  - a lease claimed BEFORE the window opened belongs to a different,
     *    legitimate login and is left alone;
     *  - the actor revoking their own grant is skipped rather than refused,
     *    because the canonical release service rejects self-release and
     *    revocation must never become impossible. They can log out normally.
     *
     * Composes {@see DoctorSessionReleaseService} rather than touching the
     * lease directly, so the room is freed and the approver audit row is
     * written by the one service that owns that behaviour.
     */
    private function releaseEmergencyLease(DoctorBreakGlassGrant $grant, User $actor, string $reason): bool
    {
        if ($grant->first_used_at === null) {
            return false;
        }

        if ((int) $grant->user_id === (int) $actor->id) {
            return false;
        }

        $lease = $this->leases->activeForUser((int) $grant->user_id);

        if ($lease === null) {
            return false;
        }

        if ($lease->claimed_at === null || $lease->claimed_at->lt($grant->granted_at)) {
            return false;
        }

        return $this->sessionReleases->releaseForDoctor((int) $grant->doctor_id, $actor, $reason);
    }

    /**
     * Record that an emergency window actually carried a session.
     *
     * Only the FIRST use is stamped, so an unused grant can be told apart from
     * one a clinician worked a whole list on. Never throws: this is evidence,
     * and failing to record evidence must not deny a doctor who is already
     * inside an approved window.
     */
    public function markUsed(DoctorBreakGlassGrant $grant): void
    {
        if ($grant->first_used_at !== null) {
            return;
        }

        try {
            $updated = $this->grants->update($grant, ['first_used_at' => Carbon::now()]);

            $this->auditLogs->log(self::ENTITY, (int) $updated->id, self::ACTION_FIRST_USED, null, [
                'user_id' => $updated->user_id,
                'expires_at' => $updated->expires_at?->toIso8601String(),
            ], null);
        } catch (\Throwable) {
            // Swallowed deliberately. See the docblock: an evidence write must
            // never become the reason a clinician is refused mid-emergency.
        }
    }

    /**
     * Server-side authority check, duplicated from the policy on purpose.
     *
     * The policy and the route middleware guard the HTTP surface; this guards
     * the SERVICE, so a console command, a queued job or a future caller that
     * never passes through a controller cannot file a grant that no permission
     * was ever checked for.
     */
    private function assertActorMayGrant(User $actor): void
    {
        if (! $actor->can('grant_doctor_break_glass_access')) {
            throw ValidationException::withMessages([
                'actor' => 'Anda tidak berwenang memberikan atau mencabut akses darurat dokter.',
            ]);
        }
    }

    private function assertTargetIsDoctorAccount(User $doctorAccount): void
    {
        if (! $doctorAccount->hasRole('Doctor')) {
            throw ValidationException::withMessages([
                'user_id' => 'Akses darurat hanya dapat diberikan kepada akun dengan peran Dokter.',
            ]);
        }
    }

    private function assertReason(string $reason): void
    {
        $min = (int) config('doctor_access.reason.min_length', 10);
        $max = (int) config('doctor_access.reason.max_length', 1000);
        $length = mb_strlen($reason);

        if ($length < $min || $length > $max) {
            throw ValidationException::withMessages([
                'reason' => "Alasan akses darurat wajib diisi antara {$min} dan {$max} karakter.",
            ]);
        }
    }

    private function assertWindow(int $hours): void
    {
        $maxHours = (int) config('doctor_access.break_glass.max_hours', 12);
        $minMinutes = (int) config('doctor_access.break_glass.min_minutes', 15);

        if ($hours < 1 || $hours > $maxHours) {
            throw ValidationException::withMessages([
                'hours' => "Durasi akses darurat harus antara 1 dan {$maxHours} jam.",
            ]);
        }

        if ($hours * 60 < $minMinutes) {
            throw ValidationException::withMessages([
                'hours' => "Durasi akses darurat minimal {$minMinutes} menit.",
            ]);
        }
    }
}
