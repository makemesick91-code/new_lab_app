<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\DoctorAccess\Services\DoctorSessionReleaseService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — FORCE LOGOUT: end one doctor's
 * active login session when they cannot end it themselves.
 *
 * WHY THIS EXISTS AT ALL. One active session per doctor means a lease can get
 * STUCK: the tablet is locked in a drawer, or the doctor went home without
 * logging out and their session row is still live, so the doctor standing in
 * the clinic is refused. The rule is refuse-not-evict on purpose, so the escape
 * hatch has to be a deliberate, authorized, audited human action — never an
 * idle timer, which would be eviction by the back door, and never a manual
 * UPDATE on the lease table, which leaves no trail and frees no clinic room.
 *
 * ── THIS ENDS A LOGIN SESSION. IT ENDS NOTHING ELSE. ──────────────────────
 *
 * No device, no `DoctorDeviceAuthorization` and no WebAuthn credential is
 * touched, and none of those tables is written (ruling P17). That is not a
 * promise about intent, it is a property of the call graph: the only thing
 * below this command is {@see DoctorSessionReleaseService}, which frees the
 * clinic room and releases the lease. WebAuthn revocation is irreversible, so a
 * support action taken to unstick a doctor at 08:00 must never destroy the
 * credential on their tablet. The doctor logs back in; they do not re-enrol.
 *
 * ── SAFE BY DEFAULT, FOUR WAYS ────────────────────────────────────────────
 *
 *   ONE DOCTOR AT A TIME. `--doctor=` names exactly one. There is no `--all`,
 *   no branch selector and no wildcard: a tool whose blast radius is "every
 *   doctor on shift" is how one wrong command at 2am becomes an incident.
 *
 *   DRY RUN UNLESS `--apply`. Without it the command reports what it WOULD do
 *   and writes nothing — no release, no room change, no audit row. The preview
 *   runs the same guard and the same refusals as the decision, so a refusal
 *   previews as a refusal.
 *
 *   AN EXPLICIT HUMAN ACTOR. `--actor=` names a real, active account and
 *   `release_doctor_session_leases` is checked against it exactly as a browser
 *   surface would. The Linux user, the SSH login and root are never an
 *   application identity — and without a named actor the self-release refusal
 *   in the service would have nobody to compare against.
 *
 *   A WRITTEN REASON. `--reason=` is required and bounded by
 *   config('doctor_access.reason'), the same bounds any later surface reads. A
 *   doctor logged out mid-consultation deserves a trail that says who did it
 *   and why; 'x' passes a non-empty check and explains nothing.
 *
 * ── WHAT THE DOCTOR EXPERIENCES ───────────────────────────────────────────
 *
 * The release is DATA, not an in-process logout: there is no cross-session
 * logout primitive in this codebase and this command does not invent one. The
 * doctor keeps working until their browser makes another request, at which
 * point the lease middleware finds no lease behind their token and tears that
 * session down. For an idle tablet that can be minutes. Say that to the
 * operator rather than implying the release is instantaneous.
 *
 * EXIT CODES. 0 only when the requested outcome was reached, or a dry run
 * completed. Every refusal — no actor, unknown actor, inactive actor,
 * unauthorized, unknown or unlinked doctor, self-release, a reason outside the
 * bounds — exits non-zero, so a wrapper script cannot mistake a refusal for
 * success. Finding NOTHING TO RELEASE is not a refusal: it is the honest,
 * idempotent outcome and it exits 0 while saying so.
 */
final class DoctorSessionForceLogoutCommand extends Command
{
    protected $signature = 'doctor:session-force-logout
        {--doctor= : Id of the doctor (mst_doctors.id) whose session is being ended}
        {--actor= : Id or email of the user the action is attributed to (required)}
        {--reason= : Why the session is being ended (required, recorded in the audit log)}
        {--apply : Perform the release; without it nothing is written}
        {--json : Emit the outcome as JSON}';

    protected $description = 'Force logout one doctor: release their active session lease and free their clinic room (dry-run unless --apply). Never revokes a device, an authorization or a credential.';

    /**
     * The permission a browser surface would check through a policy.
     *
     * Checked HERE, at the surface, and deliberately not inside the service:
     * the service performs the operation, and every surface authorizes its own
     * caller. A future HTTP surface gates the same grant through a policy.
     */
    private const PERMISSION = 'release_doctor_session_leases';

    public function handle(DoctorSessionReleaseService $releases): int
    {
        $doctorId = $this->doctorId();

        if ($doctorId === null) {
            return $this->refuse('--doctor=<id> wajib diisi dengan id dokter yang sesinya akan diakhiri.');
        }

        $actor = $this->resolveActor();

        if (is_string($actor)) {
            return $this->refuse($actor);
        }

        $reason = $this->resolveReason();

        if (! $this->option('apply')) {
            try {
                $preview = $releases->previewForDoctor($doctorId, $actor);
            } catch (ValidationException $exception) {
                return $this->refuse($this->firstMessage($exception));
            }

            return $this->report($preview + [
                'applied' => false,
                'released' => false,
                'reason' => $reason,
                'actor_user_id' => (int) $actor->id,
            ]);
        }

        // The reason is only required to WRITE. A dry run that refused for a
        // missing reason would be a worse tool: an operator diagnosing a stuck
        // lease should be able to look before they have decided what to write.
        if ($reason === null) {
            $bounds = $this->reasonBounds();

            return $this->refuse(sprintf(
                '--reason="<alasan>" wajib diisi untuk mengakhiri sesi dokter, %d-%d karakter.',
                $bounds['min'],
                $bounds['max'],
            ));
        }

        try {
            $released = $releases->releaseForDoctor($doctorId, $actor, $reason);
        } catch (ValidationException $exception) {
            return $this->refuse($this->firstMessage($exception));
        }

        // No `user_id` on this path, deliberately. It is the SUBJECT's account
        // id, and after the release the only way to state it would be a second
        // read that could answer about a row the decision never saw. The audit
        // trail records it from inside the transaction that used it.
        return $this->report([
            'doctor_id' => $doctorId,
            'applied' => true,
            'released' => $released,
            'reason' => $reason,
            'actor_user_id' => (int) $actor->id,
        ]);
    }

    private function doctorId(): ?int
    {
        $raw = trim((string) $this->option('doctor'));

        if ($raw === '' || ! ctype_digit($raw) || (int) $raw < 1) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * The acting human, or the refusal message explaining why there is none.
     *
     * A string return is the refusal. Returning it rather than throwing keeps
     * every exit through one reporter, so `--json` is honoured on every path.
     */
    private function resolveActor(): User|string
    {
        $identifier = trim((string) $this->option('actor'));

        if ($identifier === '') {
            return 'Sertakan --actor= dengan id atau email pengguna yang menjalankan tindakan ini.';
        }

        // Soft-deleted users are excluded by the model's default scope, so a
        // removed account can never act.
        $actor = ctype_digit($identifier)
            ? User::query()->find((int) $identifier)
            : User::query()->where('email', $identifier)->first();

        if ($actor === null) {
            return 'Pengguna tersebut tidak ditemukan.';
        }

        if (! (bool) $actor->is_active) {
            return 'Akun tersebut sudah tidak aktif dan tidak dapat menjalankan tindakan ini.';
        }

        if (! $actor->can(self::PERMISSION)) {
            return 'Akun tersebut tidak berwenang mengakhiri sesi login dokter.';
        }

        return $actor;
    }

    /**
     * The operator's words, or null when they are absent or out of bounds.
     *
     * Null covers both, because on the writing path both refuse with the same
     * message quoting the same bounds — which is what the operator needs.
     */
    private function resolveReason(): ?string
    {
        $reason = trim((string) $this->option('reason'));
        $bounds = $this->reasonBounds();

        if (mb_strlen($reason) < $bounds['min'] || mb_strlen($reason) > $bounds['max']) {
            return null;
        }

        return $reason;
    }

    /**
     * @return array{min: int, max: int}
     */
    private function reasonBounds(): array
    {
        return [
            'min' => (int) config('doctor_access.reason.min_length'),
            'max' => (int) config('doctor_access.reason.max_length'),
        ];
    }

    private function firstMessage(ValidationException $exception): string
    {
        $first = collect($exception->errors())->flatten()->first();

        return is_string($first) && $first !== ''
            ? $first
            : 'Tindakan ini tidak dapat dijalankan.';
    }

    private function refuse(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['refused' => true, 'message' => $message],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function report(array $outcome): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(
                $outcome,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        }

        if ($outcome['applied'] !== true) {
            $this->comment('DRY-RUN — tidak ada perubahan. Jalankan ulang dengan --apply untuk mengakhiri sesi.');
        } elseif ($outcome['released'] === true) {
            $this->info('APPLIED — sesi dokter dilepas dan ruangan dibebaskan. '
                .'Sesi di perangkat dokter berhenti pada permintaan berikutnya, bukan seketika.');
        } else {
            $this->info('Tidak ada sesi aktif untuk dilepas. Ruangan tetap dibebaskan.');
        }

        $this->info('Perangkat, otorisasi perangkat dan kredensial WebAuthn TIDAK disentuh.');

        $rows = [];

        foreach ($outcome as $field => $value) {
            $rows[] = [$field, match (true) {
                $value === null => '—',
                is_bool($value) => $value ? 'yes' : 'no',
                default => (string) $value,
            }];
        }

        $this->table(['field', 'value'], $rows);

        return self::SUCCESS;
    }
}
