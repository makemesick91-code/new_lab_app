<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Services;

use App\Models\User;
use App\Modules\DoctorAccess\Interfaces\DoctorSessionLeaseRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use App\Modules\DoctorAccess\Support\DoctorAccessSubject;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — ruling P17: FORCE LOGOUT. An
 * operator clears a doctor's stuck lease, on a named actor's authority.
 *
 * ── THE SURFACE IN THIS PULL REQUEST IS A CONSOLE COMMAND ─────────────────
 *
 * `doctor:session-force-logout`, and being a console command it needs server
 * access — so the ruling's eventual "without an SSH session" is NOT yet
 * delivered, and this note says so rather than implying otherwise. What IS
 * delivered is everything that makes the action safe to expose later: the
 * ordering, the row lock, the self-release refusal, the two audit rows, and the
 * guarantee about what is never touched. An HTTP surface can be added on top of
 * this service without restating one of those decisions.
 *
 * THE ACTOR IS ALWAYS A NAMED HUMAN, never 'the console'. The command resolves
 * one and checks `release_doctor_session_leases` against them, so the trail
 * names a person who held the grant — which is also the only way the
 * self-release refusal below can mean anything on a command line.
 *
 * ── THIS ENDS A LOGIN SESSION. IT ENDS NOTHING ELSE. ──────────────────────
 *
 * It must NEVER revoke a device, a `DoctorDeviceAuthorization` or a WebAuthn
 * credential, and it writes to none of those tables. WebAuthn revocation is
 * irreversible — the only write to `revoked_at` in the registration service is
 * `=> now()` — so a support action taken to unstick a doctor at 08:00 would
 * permanently destroy the credential on their tablet. The doctor logs back in;
 * they do not re-enrol their device.
 *
 * ── WHY IT IS ITS OWN SERVICE ─────────────────────────────────────────────
 *
 * It is an operational support action and nothing else. Folding it into a
 * workflow that decides something ELSE about the doctor would put it behind
 * that workflow's guards — none of which mean anything here, and any one of
 * which could refuse the release precisely when the doctor is most stuck.
 *
 * ── THE ONE ORDERING THAT MATTERS ─────────────────────────────────────────
 *
 * Room first, then lease, through {@see DoctorAccessSubjectGuard}. A released
 * lease with an occupied room leaves the next clinician unable to take the
 * consultation room the doctor is no longer in.
 *
 * ── WHAT THE DOCTOR EXPERIENCES ───────────────────────────────────────────
 *
 * A release is DATA, not an in-process logout: there is no cross-session logout
 * primitive in this codebase and this service does not invent one. The victim
 * keeps working until their browser makes another request, at which point the
 * lease middleware finds no lease behind their token and tears that session
 * down. For an idle tablet that can be minutes. Say so in the runbook rather
 * than implying the release is instantaneous.
 */
class DoctorSessionReleaseService
{
    /**
     * ITS OWN ACTION, on the DOCTOR record.
     *
     * `DoctorSessionLeaseService::releaseFor()` already writes the lease-
     * lifecycle row (`DOCTOR_SESSION_LEASE_REVOKED`) carrying the closed reason
     * vocabulary. This second row records a different fact that the first cannot
     * hold: WHY a human decided to end somebody else's session, in their own
     * words. A refusal — or an eviction — nobody can explain is not one.
     */
    public const ACTION_RELEASED_BY_APPROVER = 'DOCTOR_SESSION_RELEASED_BY_APPROVER';

    public const ENTITY_TYPE = 'mst_doctors';

    public function __construct(
        private readonly DoctorAccessSubjectGuard $subjects,
        private readonly DoctorSessionLeaseRepositoryInterface $leases,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * What {@see self::releaseForDoctor()} would do, WITHOUT doing any of it.
     *
     * It exists because the operator surface is dry-run by default, and a
     * preview that guessed would be worse than no preview at all. So it runs
     * the SAME guard, takes the SAME row lock and applies the SAME two
     * refusals, and then writes nothing: no release, no room, no audit row.
     * A refusal previews as a refusal, by throwing exactly where the real
     * action would throw.
     *
     * Read INSIDE a transaction so the lock is real. There is nothing to roll
     * back, which is the point.
     *
     * IT IS NOT A PROMISE. The lease it reports can be released by a logout, or
     * claimed by a reconnecting tablet, between the preview and the decision —
     * so the real action re-reads everything under its own lock and is the only
     * authority. A preview that goes stale simply reports one outcome and the
     * decision reports another, which is the honest behaviour.
     *
     * @return array{doctor_id: int, user_id: int, doctor_online: bool, holds_active_lease: bool, lease_id: int|null, claimed_at: string|null, last_seen_at: string|null}
     *
     * @throws ValidationException
     */
    public function previewForDoctor(int $doctorId, User $actor): array
    {
        return DB::transaction(function () use ($doctorId, $actor): array {
            $subject = $this->subjects->lockDecidableSubject($doctorId);

            $this->assertNotSelfRelease($subject, $actor);

            $lease = $this->leases->activeForUser($subject->userId());

            return [
                'doctor_id' => $subject->doctorId(),
                'user_id' => $subject->userId(),
                'doctor_online' => $this->subjects->isOnline($subject),
                'holds_active_lease' => $lease !== null,
                'lease_id' => $lease === null ? null : (int) $lease->id,
                'claimed_at' => $lease?->claimed_at?->toDateTimeString(),
                'last_seen_at' => $lease?->last_seen_at?->toDateTimeString(),
            ];
        });
    }

    /**
     * Release the doctor's active session.
     *
     * REFUSES AN UNLINKED SUBJECT (ruling P6): a Doctor-role record with no
     * `mst_doctors.user_id` has no session to end, so the action would report
     * success while doing nothing. The guard's message names the remedy.
     *
     * REFUSES A SELF-RELEASE. An operator ending their own session through this
     * action is not a support action; it is a logout, and the logout path is
     * where a session gets released cleanly with its own reason. Blocking it
     * here keeps `admin_release` meaning what it says in the trail. The
     * comparison lives in the service, inside the transaction, after the doctor
     * row is locked — a permission check could not catch it, and a policy clause
     * would not run for a Super Admin, whom the single global `Gate::before`
     * short-circuits.
     *
     * IDEMPOTENT AND HONEST. A doctor who holds no active lease is not an error:
     * `releaseFor()` answers null, the audit records `released = false`, and the
     * caller can tell the operator there was nothing to clear rather than
     * claiming a release that never happened.
     *
     * @param  string  $reason  the operator's own words, already length-validated
     *                          by the caller against config('doctor_access.reason')
     * @return bool whether an active lease was actually released
     *
     * @throws ValidationException
     */
    public function releaseForDoctor(int $doctorId, User $actor, string $reason): bool
    {
        return DB::transaction(function () use ($doctorId, $actor, $reason): bool {
            $subject = $this->subjects->lockDecidableSubject($doctorId);

            $this->assertNotSelfRelease($subject, $actor);

            // Read before the release so the trail records what was actually
            // interrupted, not the state after the interruption.
            $online = $this->subjects->isOnline($subject);

            $before = [
                'doctor_id' => $subject->doctorId(),
                'user_id' => $subject->userId(),
                'doctor_online_at_decision' => $online,
            ];

            // The room is freed either way. The return value says whether there
            // was actually a lease to clear, so the operator is told the truth
            // rather than shown a success message for a no-op.
            $released = $this->subjects->endWorkingSession(
                $subject,
                DoctorSessionLease::RELEASE_ADMIN,
                $actor,
            ) !== null;

            $this->audit->log(
                self::ENTITY_TYPE,
                $subject->doctorId(),
                self::ACTION_RELEASED_BY_APPROVER,
                $before,
                [
                    'doctor_id' => $subject->doctorId(),
                    'user_id' => $subject->userId(),
                    // The operator's justification. No device name, no IP, no
                    // user agent and no session id: this row explains a support
                    // decision, and it must not become a channel for one actor's
                    // device details to reach another's screen.
                    'reason' => $reason,
                    'doctor_online_at_decision' => $online,
                    'session_released' => $released,
                    'devices_touched' => false,
                    'webauthn_credentials_touched' => false,
                ],
                $actor,
            );

            return $released;
        });
    }

    /**
     * One refusal, declared once, so the preview and the decision can never
     * disagree about who may end whose session.
     *
     * @throws ValidationException
     */
    private function assertNotSelfRelease(DoctorAccessSubject $subject, User $actor): void
    {
        if (! $subject->isActedOnBy($actor)) {
            return;
        }

        throw ValidationException::withMessages([
            'reason' => 'Anda tidak dapat mengakhiri sesi Anda sendiri dengan tindakan ini. '
                .'Gunakan proses keluar yang biasa.',
        ]);
    }
}
