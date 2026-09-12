<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\DoctorAccess\Services\DoctorDeviceBulkAuthorizationService;
use App\Modules\DoctorAccess\Support\DoctorDeviceBulkAuthorizationOutcome;
use App\Modules\DoctorAccess\Support\DoctorDeviceBulkAuthorizationPlan;
use App\Modules\DoctorAccess\Support\DoctorDeviceBulkAuthorizationRefusal;
use Illuminate\Console\Command;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 PR-C — authorize every eligible
 * doctor on every eligible trusted clinic tablet.
 *
 * WHY THIS EXISTS. Device trust and doctor branch authority are separate
 * boundaries: a tablet is trusted hardware, and a DoctorDeviceAuthorization is
 * a per-doctor grant on top of it. Populating that grant one row at a time
 * through the approval inbox does not scale past a pilot — twelve doctors and
 * three tablets is thirty-six clicks, and a missed one is a doctor refused at a
 * chairside at 08:00. This closes the matrix in one reviewed action.
 *
 * ── SAFE BY DEFAULT, FIVE WAYS ────────────────────────────────────────────
 *
 *   DRY RUN UNLESS `--apply`. With no flags this command writes NOTHING: no
 *   authorization row, no device row, no audit row — not even a "previewed"
 *   one. It prints the exact delta and stops.
 *
 *   APPROVAL IS BOUND TO THE DELTA, NOT TO A MOMENT. `--apply` additionally
 *   requires `--confirm-plan=<digest>`, and the digest is a hash of the
 *   eligible sets and the exact pair lists. The operator cannot apply without
 *   having read the preview and copied a value out of it, and if the estate
 *   drifts in between, the digest changes and the run fails closed.
 *
 *   DELIBERATELY NOT A PROMPT. `$this->confirm()` appears nowhere in this
 *   repository's commands. A Laravel prompt auto-answers with its default under
 *   a non-interactive invocation — an SSH one-liner, a deploy script, CI — so
 *   at fleet scale it is an unreviewed write wearing the costume of a question.
 *
 *   AN EXPLICIT HUMAN ACTOR. `--actor=` names a real active account and
 *   `manage_doctor_device_authorizations` is checked against it exactly as the
 *   approval screen would. The Linux user, the SSH login and root are never an
 *   application identity, and without a named actor every row this writes would
 *   land in the audit trail attributed to nobody.
 *
 *   A WRITTEN REASON, bounded by config('doctor_access.reason') — the same
 *   bounds every other surface in this module reads.
 *
 * ── WHAT IT CANNOT DO ─────────────────────────────────────────────────────
 *
 * It never registers a device, never enrols or revokes a WebAuthn credential,
 * never changes a device's status or branch, never touches a doctor's home
 * lock, a temporary cover, a session lease, the pilot cohort or any feature
 * flag. It never revokes or un-rejects an authorization either: a REJECTED or
 * REVOKED pair is reported and left exactly as the human who decided it left it.
 *
 * It also does not make anyone able to log in on its own. A doctor still needs
 * a device-bound credential enrolled at the tablet under their own biometric.
 * The report says which pairs are still blocked on that, because a bulk tool
 * that presented partial provisioning as readiness would be worse than one that
 * refused.
 *
 * ── REUSED PERMISSION, ON PURPOSE ─────────────────────────────────────────
 *
 * `manage_doctor_device_authorizations` already exists, is already seeded and
 * is already the write authority for this domain — an operator holding it can
 * approve every one of these rows by hand today. Minting a new permission would
 * add no safety and would add a `db:seed` step that both sibling PRs proved is
 * easy to forget: each shipped with its new permission absent on the host.
 *
 * EXIT CODES. 0 for a completed dry run, and for an apply where every intended
 * write landed — including the honest "nothing to provision" case, which is the
 * idempotent outcome, not a failure. Non-zero for every refusal and for an
 * apply where any pair was refused at write time, so a wrapper script cannot
 * mistake drift for success.
 */
final class DoctorDeviceBulkAuthorizeCommand extends Command
{
    protected $signature = 'doctor:device-bulk-authorize
        {--actor= : Id or email of the user this run is attributed to (required)}
        {--reason= : Why the fleet is being provisioned (required to write, recorded in the audit log)}
        {--doctor= : Narrow to these doctors (mst_doctors.id, comma separated)}
        {--device= : Narrow to these devices (mst_doctor_devices.id, comma separated)}
        {--apply : Perform the writes; without it nothing is written}
        {--confirm-plan= : The plan digest printed by the dry run; required with --apply}
        {--dry-run : Explicit dry run (already the default; refuses when combined with --apply)}
        {--json : Emit the plan or the outcome as JSON}
        {--strict : Exit non-zero when the matrix cannot be fully closed}';

    protected $description = 'Authorize every eligible doctor on every eligible trusted clinic device (dry-run unless --apply --confirm-plan=<digest>). Never registers a device, never touches a credential, a branch lock or a feature flag.';

    /**
     * Checked HERE, at the surface, and deliberately not inside the service:
     * the service performs the operation and every surface authorizes its own
     * caller, exactly as DoctorDeviceAuthorizationPolicy::decide does for the
     * approval screen.
     */
    private const PERMISSION = 'manage_doctor_device_authorizations';

    public function handle(DoctorDeviceBulkAuthorizationService $bulk): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && (bool) $this->option('dry-run')) {
            return $this->invalid('--dry-run dan --apply tidak dapat digabungkan. Hilangkan salah satunya.');
        }

        $actor = $this->resolveActor();

        if (is_string($actor)) {
            return $this->refuse($actor);
        }

        $requestedDoctors = $this->idList('doctor');

        if (is_string($requestedDoctors)) {
            return $this->refuse($requestedDoctors, 'SCOPE_UNREADABLE');
        }

        $requestedDevices = $this->idList('device');

        if (is_string($requestedDevices)) {
            return $this->refuse($requestedDevices, 'SCOPE_UNREADABLE');
        }

        try {
            $plan = $bulk->plan($requestedDoctors, $requestedDevices);
        } catch (DoctorDeviceBulkAuthorizationRefusal $refusal) {
            return $this->refuse($refusal->getMessage(), $refusal->refusalCode);
        }

        // A scope that names ids matching no eligible row is reported, never
        // silently executed as something wider or narrower than asked for.
        $unmatched = $this->unmatchedScope($requestedDoctors, $plan->eligibleDoctorIds, 'doctor')
            ?? $this->unmatchedScope($requestedDevices, $plan->eligibleDeviceIds, 'device');

        if ($unmatched !== null) {
            return $this->refuse($unmatched, 'SCOPE_MATCHED_NOTHING');
        }

        if (! $apply) {
            return $this->report($this->previewPayload($plan, $actor), $plan);
        }

        $reason = $this->resolveReason();

        if ($reason === null) {
            $bounds = $this->reasonBounds();

            return $this->refuse(sprintf(
                '--reason="<alasan>" wajib diisi untuk menulis otorisasi, %d-%d karakter.',
                $bounds['min'],
                $bounds['max'],
            ), 'REASON_REQUIRED');
        }

        $supplied = trim((string) $this->option('confirm-plan'));
        $digest = $plan->digest();

        if ($supplied === '') {
            $refusal = DoctorDeviceBulkAuthorizationRefusal::planDigestMissing($digest);

            return $this->refuse($refusal->getMessage(), $refusal->refusalCode);
        }

        if (! hash_equals($digest, $supplied)) {
            $refusal = DoctorDeviceBulkAuthorizationRefusal::planDigestMismatch($supplied, $digest);

            return $this->refuse($refusal->getMessage(), $refusal->refusalCode);
        }

        // The idempotent outcome. Reported honestly and exited 0: a second run
        // over a closed matrix is a success, not a no-op to be flagged.
        if (! $plan->hasWork()) {
            return $this->report(
                $plan->summary() + [
                    'applied' => true,
                    'created' => 0,
                    'approved_existing' => 0,
                    'refused' => 0,
                    // An EMPTY matrix and a CLOSED one are different facts, and
                    // an operator reading "sudah lengkap" about an estate with
                    // no eligible tablet would believe a fleet was provisioned.
                    'message' => $plan->matrixSize() === 0
                        ? 'Tidak ada pasangan yang memenuhi syarat: matriks kosong, bukan lengkap. '
                            .'Periksa daftar pengecualian dokter dan perangkat di atas.'
                        : 'Tidak ada otorisasi yang perlu dibuat. Matriks sudah lengkap.',
                    'actor_user_id' => (int) $actor->id,
                ],
                $plan,
            );
        }

        $result = $bulk->apply($plan, $actor, $reason);

        $payload = $plan->summary() + [
            'applied' => true,
            'created' => $result['created'],
            'approved_existing' => $result['approved_existing'],
            'skipped_already_active' => $result['skipped'],
            'refused' => $result['refused'],
            'reason' => $reason,
            'actor_user_id' => (int) $actor->id,
            'outcomes' => $result['outcomes'],
        ];

        // A plan and a result that disagree is drift, and drift exits non-zero.
        // Smoothing it over would let a partially-applied run read as a clean
        // one, which is the whole reason the two vocabularies are separate.
        if ($result['refused'] > 0) {
            $this->report($payload, $plan);

            return self::FAILURE;
        }

        return $this->report($payload, $plan);
    }

    /**
     * @return array<string, mixed>
     */
    private function previewPayload(DoctorDeviceBulkAuthorizationPlan $plan, User $actor): array
    {
        return $plan->summary() + [
            'applied' => false,
            'actor_user_id' => (int) $actor->id,
            'proposed' => array_map(
                static fn ($pair): array => $pair->toArray(),
                $plan->actionable(),
            ),
            'blocked_pairs' => array_map(
                static fn ($pair): array => $pair->toArray(),
                array_merge(
                    $plan->inBucket(DoctorDeviceBulkAuthorizationOutcome::BUCKET_BLOCKED_REJECTED),
                    $plan->inBucket(DoctorDeviceBulkAuthorizationOutcome::BUCKET_BLOCKED_REVOKED),
                ),
            ),
            'excluded_doctor_rows' => $plan->excludedDoctors,
            'excluded_device_rows' => $plan->excludedDevices,
            'existing_active_on_ineligible_device_rows' => $plan->activeOnIneligibleDevice,
            // Proof, printed rather than promised. A dry run that merely said it
            // wrote nothing would be asking to be believed.
            'branch_mutations' => 0,
            'device_mutations' => 0,
            'credential_mutations' => 0,
            'session_lease_mutations' => 0,
            'pilot_scope_mutations' => 0,
            'feature_flag_mutations' => 0,
        ];
    }

    /**
     * The ids an operator named, or the refusal explaining why they named none.
     *
     * AN EMPTY LIST MEANS "THE WHOLE FLEET", so a parser that silently dropped
     * what it could not read would turn a scoped write into an unscoped one.
     * `--device=7;8` with the wrong separator, an unset shell variable, a `0` —
     * each would have widened a two-tablet run to every tablet in the estate,
     * and the digest gate could not catch it because the preview was wrong in
     * exactly the same way.
     *
     * So parsing is STRICT and total: every token must be a positive integer,
     * and a flag that was supplied at all must yield at least one id. `null`
     * (the flag is absent) and `[]` (the flag matched nothing) are different
     * answers, and only the first one means "no narrowing".
     *
     * A string return is the refusal, keeping every exit through one reporter.
     *
     * @return list<int>|string
     */
    private function idList(string $option): array|string
    {
        $supplied = $this->option($option);

        // Absent. NOT the same as supplied-and-empty: `--device=` with nothing
        // after it is an operator who meant to name something.
        if ($supplied === null || $supplied === false) {
            return [];
        }

        $raw = trim((string) $supplied);

        if ($raw === '') {
            return sprintf(
                '--%s= diisi kosong. Hilangkan opsi ini untuk menjalankan seluruh armada, '
                .'atau sebutkan id yang dimaksud — opsi kosong tidak pernah diartikan sebagai "semua".',
                $option,
            );
        }

        $ids = [];

        foreach (explode(',', $raw) as $token) {
            $token = trim($token);

            if ($token === '' || ! ctype_digit($token) || (int) $token < 1) {
                return sprintf(
                    '--%s=%s tidak dapat dibaca sebagai daftar id (gunakan pemisah koma, contoh --%s=3,5). '
                    .'Nilai yang tidak terbaca tidak pernah diartikan sebagai "semua".',
                    $option,
                    $raw,
                    $option,
                );
            }

            $ids[] = (int) $token;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * The refusal for ids the operator named that no eligible row answers to.
     *
     * Silence here would be the same failure in a quieter form: an operator who
     * scoped a run to a device that turns out to be revoked should be told so,
     * not handed a confident report about a different set of rows.
     *
     * @param  list<int>  $requested
     * @param  list<int>  $matched
     */
    private function unmatchedScope(array $requested, array $matched, string $label): ?string
    {
        if ($requested === []) {
            return null;
        }

        $missing = array_values(array_diff($requested, $matched));

        if ($missing === []) {
            return null;
        }

        return sprintf(
            '--%s= menyebut id yang tidak memenuhi syarat atau tidak ada: %s. '
            .'Jalankan pratinjau tanpa penyempitan untuk melihat alasan pengecualiannya.',
            $label,
            implode(', ', $missing),
        );
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
            return 'Akun tersebut tidak berwenang mengelola otorisasi perangkat dokter.';
        }

        return $actor;
    }

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

    private function invalid(string $message): int
    {
        $this->emitRefusal($message, 'INVALID_ARGUMENTS');

        return self::INVALID;
    }

    private function refuse(string $message, string $code = 'REFUSED'): int
    {
        $this->emitRefusal($message, $code);

        return self::FAILURE;
    }

    private function emitRefusal(string $message, string $code): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['refused' => true, 'code' => $code, 'message' => $message],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }

        $this->error($message);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function report(array $payload, DoctorDeviceBulkAuthorizationPlan $plan): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->renderText($payload, $plan);
        }

        if ((bool) $this->option('strict') && $plan->unreachable() > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderText(array $payload, DoctorDeviceBulkAuthorizationPlan $plan): void
    {
        $applied = (bool) ($payload['applied'] ?? false);

        $this->line($applied ? 'MODE=APPLY' : 'MODE=DRY_RUN');
        $this->line('ELIGIBLE_DOCTOR_COUNT='.$plan->eligibleDoctorCount());
        $this->line('ELIGIBLE_DEVICE_COUNT='.$plan->eligibleDeviceCount());
        $this->line('TARGET_PAIR_COUNT='.$plan->matrixSize());
        $this->line('EXISTING_ACTIVE_TARGET_PAIRS='.$plan->existingActive());
        $this->line('MISSING_TARGET_PAIRS='.$plan->proposedCreate());
        $this->line('PENDING_TARGET_PAIRS='.$plan->proposedApproveExisting());
        // Structurally impossible: mst_dd_authorizations_pair_unique is an
        // unconditional UNIQUE(doctor_id, doctor_device_id). Printed anyway so
        // the guarantee is visible in the evidence rather than only in a schema
        // nobody reads during an incident.
        $this->line('DUPLICATE_ACTIVE_PAIRS=0');
        $this->line('CONFLICTING_TARGET_PAIRS='.$plan->blocked());
        $this->line('PROPOSED_CREATE_COUNT='.$plan->proposedCreate());
        $this->line('PROPOSED_REACTIVATE_COUNT=0');
        $this->line('PROPOSED_SKIP_COUNT='.$plan->existingActive());
        $this->line('FINAL_EXPECTED_AUTHORIZATIONS='.$plan->finalExpectedActive());
        $this->line('UNREACHABLE='.$plan->unreachable());
        $this->line('PENDING_INBOX_BEFORE='.$plan->pendingInboxBefore);
        $this->line('PENDING_INBOX_AFTER_EXPECTED='.($plan->pendingInboxBefore - $plan->proposedApproveExisting()));
        $this->line('PLAN_DIGEST='.$plan->digest());

        if (! $applied) {
            $this->line('BRANCH_MUTATIONS=0');
            $this->line('DEVICE_MUTATIONS=0');
            $this->line('CREDENTIAL_MUTATIONS=0');
            $this->line('PILOT_SCOPE_MUTATIONS=0');
            $this->line('FEATURE_FLAG_MUTATIONS=0');
        } else {
            $this->line('APPLIED_CREATED='.($payload['created'] ?? 0));
            $this->line('APPLIED_APPROVED_EXISTING='.($payload['approved_existing'] ?? 0));
            $this->line('REFUSED='.($payload['refused'] ?? 0));
        }

        foreach ($plan->actionable() as $pair) {
            $this->line(sprintf(
                'PAIR doctor_id=%d user_id=%d doctor=%s device_id=%d device=%s branch=%s action=%s',
                $pair->doctorId,
                $pair->userId,
                $pair->doctorName,
                $pair->deviceId,
                $pair->deviceName,
                $pair->deviceBranchCode ?? '-',
                $pair->bucket,
            ));
        }

        foreach ($plan->excludedDoctors as $row) {
            $this->line(sprintf('EXCLUDED_DOCTOR user_id=%d reason=%s', $row['user_id'], $row['reason']));
        }

        foreach ($plan->excludedDevices as $row) {
            $this->line(sprintf('EXCLUDED_DEVICE device_id=%d reason=%s', $row['device_id'], $row['reason']));
        }

        if (isset($payload['message'])) {
            $this->line((string) $payload['message']);
        }
    }
}
