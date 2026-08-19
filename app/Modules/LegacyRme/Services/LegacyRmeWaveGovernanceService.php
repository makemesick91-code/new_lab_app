<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeWaveBranch;
use App\Modules\LegacyRme\Models\LegacyRmeWaveOperator;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeBatchWindowRule;
use App\Modules\LegacyRme\Support\LegacyRmeWaveBranchStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-ROLL-4 — every state change a migration wave can undergo.
 *
 * ONE PLACE, BY DESIGN. Wave and branch lifecycle, operator assignment, quota
 * declaration and completion sign-off all mutate the same small set of rows and
 * all share the same invariants (valid transition, locked row, audited, reason
 * recorded). Splitting them across services would mean re-proving those
 * invariants four times.
 *
 * WHAT GOVERNANCE CANNOT DO. Nothing here admits a branch. Admission is ROLL-3's
 * config allowlist plus the owner's approval reference — deploy-time state,
 * outside this application's write path. A wave may only be approved for a
 * branch set that config ALREADY approves, so an operator with full governance
 * permission still cannot open a clinic the owner did not authorize. That is the
 * property that makes concentrating these actions in one service acceptable.
 *
 * EVERY MUTATION IS LOCKED. Transitions re-read the row under `lockForUpdate`
 * and re-assert the current status inside the transaction. Two operators
 * clicking "pause" and "drain" at the same moment otherwise both read ACTIVE and
 * both write, and the loser's intent silently disappears.
 */
class LegacyRmeWaveGovernanceService
{
    public function __construct(
        private readonly LegacyRmeWaveBindingService $binding,
        private readonly LegacyRmeMigrationReconciliationService $reconciliation,
        private readonly LegacyRmeAuditService $audit,
        private readonly BranchService $branches,
        private readonly LegacyRmeBatchWindowRule $batchWindow,
    ) {}

    /**
     * Register a wave and enroll its branches.
     *
     * The branch set is validated against BOTH the config approval and the live
     * RME-enabled branch list, so a wave cannot be created for a branch the
     * owner never approved or for a branch that cannot hold RME history.
     *
     * @param  list<string>  $branchCodes
     *
     * @throws ValidationException
     */
    public function createWave(
        User $actor,
        string $code,
        string $name,
        array $branchCodes,
        ?int $dailyQuota = null,
        ?int $perBranchDailyQuota = null,
        ?string $plannedStartDate = null,
        ?string $plannedEndDate = null,
    ): LegacyRmeMigrationWave {
        $code = strtoupper(trim($code));

        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'Kode gelombang migrasi wajib diisi.']);
        }

        if (LegacyRmeMigrationWave::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => sprintf('Gelombang migrasi %s sudah terdaftar.', $code)]);
        }

        $codes = $this->normalizeBranchCodes($branchCodes);

        if ($codes === []) {
            throw ValidationException::withMessages(['branch_codes' => 'Pilih minimal satu cabang untuk gelombang ini.']);
        }

        $approved = $this->binding->declaredApprovedBranchCodes();
        $outside = array_values(array_diff($codes, $approved));

        if ($outside !== []) {
            // The scope-binding rule, one level up. A wave that enrolls a branch
            // the deployment's approval does not cover would be a governance
            // record asserting an authorization that was never given.
            throw ValidationException::withMessages([
                'branch_codes' => sprintf(
                    'Cabang %s tidak tercakup dalam persetujuan gelombang yang berlaku pada deployment ini.',
                    implode(', ', $outside),
                ),
            ]);
        }

        $rmeBranches = $this->branches->listRmeEnabled()
            ->keyBy(fn ($branch): string => strtoupper((string) $branch->code));

        $unknown = array_values(array_filter($codes, static fn (string $c): bool => ! $rmeBranches->has($c)));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'branch_codes' => sprintf(
                    'Cabang %s bukan cabang aktif ber-RME pada deployment ini.',
                    implode(', ', $unknown),
                ),
            ]);
        }

        $this->assertQuotaWithinBounds($dailyQuota, 'daily_quota');
        $this->assertQuotaWithinBounds($perBranchDailyQuota, 'per_branch_daily_quota');

        // A routine batch is time-bounded. Asserted HERE, in the service both
        // callers reach, rather than in the wave FormRequest — the CLI never
        // touches a FormRequest, and a governance rule that only the browser
        // enforces is not a rule. The normalised strings are what gets
        // persisted, so the row holds exactly what was validated.
        $window = $this->batchWindow->normalize($plannedStartDate, $plannedEndDate);
        $plannedStartDate = $window[LegacyRmeBatchWindowRule::FIELD_START];
        $plannedEndDate = $window[LegacyRmeBatchWindowRule::FIELD_END];

        return DB::transaction(function () use (
            $actor, $code, $name, $codes, $rmeBranches, $dailyQuota, $perBranchDailyQuota, $plannedStartDate, $plannedEndDate
        ): LegacyRmeMigrationWave {
            $wave = LegacyRmeMigrationWave::query()->create([
                'code' => $code,
                'name' => trim($name) !== '' ? trim($name) : $code,
                'status' => LegacyRmeWaveStatus::DRAFT,
                // Mirrored from config at creation time; re-verified on every
                // upload, so a later config change surfaces as a binding
                // mismatch rather than being silently inherited.
                'approval_reference' => $this->binding->declaredApprovalReference() ?: null,
                'approved_branch_codes' => $this->binding->declaredApprovedBranchCodes(),
                'daily_quota' => $dailyQuota,
                'per_branch_daily_quota' => $perBranchDailyQuota,
                'planned_start_date' => $plannedStartDate,
                'planned_end_date' => $plannedEndDate,
                'created_by' => (int) $actor->getKey(),
            ]);

            foreach ($codes as $branchCode) {
                $branch = $rmeBranches->get($branchCode);

                LegacyRmeWaveBranch::query()->create([
                    'wave_id' => $wave->getKey(),
                    'branch_id' => (int) $branch->getKey(),
                    'branch_code' => $branchCode,
                    'status' => LegacyRmeWaveBranchStatus::PLANNED,
                ]);
            }

            $this->audit->logImportEvent(LegacyRmeAuditEvent::WAVE_REGISTERED, null, [
                'wave' => $wave->code,
                'status' => $wave->status,
            ], $actor);

            return $wave->refresh();
        });
    }

    /**
     * Approve a DRAFT wave.
     *
     * `require_separate_approver` enforces approver-is-not-creator server-side
     * when the deployment has two staffed accounts. It is off for the pilot, and
     * the accepted risk is recorded in config/legacy_rme_operations.php.
     *
     * @throws ValidationException
     */
    public function approve(User $actor, LegacyRmeMigrationWave $wave): LegacyRmeMigrationWave
    {
        return $this->transition($actor, $wave, LegacyRmeWaveStatus::APPROVED, null, function (LegacyRmeMigrationWave $locked) use ($actor): array {
            if ((bool) config('legacy_rme_operations.require_separate_approver', false)
                && $locked->created_by !== null
                && (int) $locked->created_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages([
                    'wave' => 'Gelombang migrasi harus disetujui oleh pengguna yang berbeda dari pembuatnya.',
                ]);
            }

            // Re-verify the mirror at the moment of approval: approving a record
            // that already disagrees with the deployment's approval would put a
            // signature on the wrong scope.
            if (! $this->binding->bindingMatches($locked)) {
                throw ValidationException::withMessages([
                    'wave' => 'Catatan gelombang tidak lagi cocok dengan persetujuan pada deployment ini.',
                ]);
            }

            return [
                'approved_by' => (int) $actor->getKey(),
                'approved_at' => now(),
            ];
        });
    }

    /**
     * Start an APPROVED wave, moving every PLANNED branch to ACTIVE.
     *
     * @throws ValidationException
     */
    public function activate(User $actor, LegacyRmeMigrationWave $wave): LegacyRmeMigrationWave
    {
        return $this->transition($actor, $wave, LegacyRmeWaveStatus::ACTIVE, null, function (LegacyRmeMigrationWave $locked) use ($actor): array {
            if (! $this->binding->bindingMatches($locked)) {
                throw ValidationException::withMessages([
                    'wave' => 'Catatan gelombang tidak cocok dengan persetujuan pada deployment ini.',
                ]);
            }

            LegacyRmeWaveBranch::query()
                ->where('wave_id', $locked->getKey())
                ->where('status', LegacyRmeWaveBranchStatus::PLANNED)
                ->update(['status' => LegacyRmeWaveBranchStatus::ACTIVE, 'updated_at' => now()]);

            return [
                'activated_by' => (int) $actor->getKey(),
                'activated_at' => now(),
            ];
        });
    }

    /**
     * Pause a running wave. New intake stops; accepted work keeps its lifecycle
     * and may still be published.
     *
     * @throws ValidationException
     */
    public function pause(User $actor, LegacyRmeMigrationWave $wave, string $reason): LegacyRmeMigrationWave
    {
        $reason = $this->assertReason($reason);

        return $this->transition($actor, $wave, LegacyRmeWaveStatus::PAUSED, $reason, static fn (): array => [
            'paused_by' => (int) $actor->getKey(),
            'paused_at' => now(),
            'pause_reason' => $reason,
        ]);
    }

    /**
     * Resume a paused wave.
     *
     * The binding is re-verified because a pause is often exactly when someone
     * changes the deployment's approval; resuming into a scope nobody approved
     * is the drift this whole layer exists to catch.
     *
     * @throws ValidationException
     */
    public function resume(User $actor, LegacyRmeMigrationWave $wave): LegacyRmeMigrationWave
    {
        return $this->transition($actor, $wave, LegacyRmeWaveStatus::ACTIVE, null, function (LegacyRmeMigrationWave $locked): array {
            if (! $this->binding->bindingMatches($locked)) {
                throw ValidationException::withMessages([
                    'wave' => 'Catatan gelombang tidak lagi cocok dengan persetujuan pada deployment ini, sehingga migrasi tidak dapat dilanjutkan.',
                ]);
            }

            return ['paused_at' => null, 'paused_by' => null];
        });
    }

    /**
     * Begin winding a wave down. Same runtime effect as a pause; different
     * intent, and it cannot be resumed.
     *
     * @throws ValidationException
     */
    public function drain(User $actor, LegacyRmeMigrationWave $wave, string $reason): LegacyRmeMigrationWave
    {
        $reason = $this->assertReason($reason);

        $wave = $this->transition($actor, $wave, LegacyRmeWaveStatus::DRAINING, $reason, static fn (): array => []);

        // Branches follow the wave: a draining wave whose branches still read
        // ACTIVE would misreport where the migration actually stands.
        LegacyRmeWaveBranch::query()
            ->where('wave_id', $wave->getKey())
            ->whereIn('status', [LegacyRmeWaveBranchStatus::ACTIVE, LegacyRmeWaveBranchStatus::PAUSED])
            ->update(['status' => LegacyRmeWaveBranchStatus::DRAINING, 'updated_at' => now()]);

        return $wave->refresh();
    }

    /**
     * Change ONE branch's state inside a wave.
     *
     * @throws ValidationException
     */
    public function transitionBranch(
        User $actor,
        LegacyRmeWaveBranch $branch,
        string $to,
        ?string $reason = null,
    ): LegacyRmeWaveBranch {
        if (in_array($to, [LegacyRmeWaveBranchStatus::PAUSED, LegacyRmeWaveBranchStatus::DRAINING, LegacyRmeWaveBranchStatus::CANCELLED], true)) {
            $reason = $this->assertReason((string) $reason);
        }

        return DB::transaction(function () use ($actor, $branch, $to, $reason): LegacyRmeWaveBranch {
            /** @var LegacyRmeWaveBranch $locked */
            $locked = LegacyRmeWaveBranch::query()->lockForUpdate()->findOrFail($branch->getKey());

            if (! LegacyRmeWaveBranchStatus::canTransition($locked->status, $to)) {
                throw ValidationException::withMessages([
                    'status' => sprintf('Cabang tidak dapat berpindah dari %s ke %s.', $locked->status, $to),
                ]);
            }

            // Completion has its own gate; it is never reachable through a plain
            // status change, or the reconciliation requirement could be skipped
            // by choosing a different button.
            if ($to === LegacyRmeWaveBranchStatus::COMPLETED) {
                throw ValidationException::withMessages([
                    'status' => 'Penyelesaian cabang harus melalui proses rekonsiliasi dan tanda tangan penyelesaian.',
                ]);
            }

            $locked->status = $to;
            $locked->status_reason = $reason;
            $locked->save();

            $this->audit->logImportEvent(LegacyRmeAuditEvent::WAVE_BRANCH_TRANSITIONED, null, [
                'wave' => $locked->wave?->code,
                'branch_code' => $locked->branch_code,
                'status' => $to,
            ], $actor);

            return $locked;
        });
    }

    /**
     * Sign a branch off as COMPLETE.
     *
     * The reconciliation is recomputed HERE, under the lock, and frozen onto the
     * row. Trusting a number the operator saw on a dashboard minutes ago would
     * let a document accepted in between be signed away.
     *
     * @throws ValidationException
     */
    public function completeBranch(User $actor, LegacyRmeWaveBranch $branch, string $note): LegacyRmeWaveBranch
    {
        $note = $this->assertReason($note);

        return DB::transaction(function () use ($actor, $branch, $note): LegacyRmeWaveBranch {
            /** @var LegacyRmeWaveBranch $locked */
            $locked = LegacyRmeWaveBranch::query()->lockForUpdate()->findOrFail($branch->getKey());
            /** @var LegacyRmeMigrationWave $wave */
            $wave = LegacyRmeMigrationWave::query()->findOrFail($locked->wave_id);

            if (! LegacyRmeWaveBranchStatus::canTransition($locked->status, LegacyRmeWaveBranchStatus::COMPLETED)) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Cabang harus dalam status %s sebelum dapat diselesaikan (saat ini %s).',
                        LegacyRmeWaveBranchStatus::DRAINING,
                        $locked->status,
                    ),
                ]);
            }

            $reconciliation = $this->reconciliation->forBranch($wave, $locked);

            if (! $reconciliation->completable()) {
                throw ValidationException::withMessages([
                    'completion' => sprintf(
                        'Cabang belum dapat diselesaikan: %s.',
                        implode(', ', $reconciliation->blockers()),
                    ),
                ]);
            }

            $locked->status = LegacyRmeWaveBranchStatus::COMPLETED;
            $locked->completed_by = (int) $actor->getKey();
            $locked->completed_at = now();
            $locked->completion_note = $note;
            $locked->reconciliation_snapshot = $reconciliation->toArray();
            $locked->save();

            $this->audit->logImportEvent(LegacyRmeAuditEvent::WAVE_BRANCH_COMPLETED, null, [
                'wave' => $wave->code,
                'branch_code' => $locked->branch_code,
                'status' => LegacyRmeWaveBranchStatus::COMPLETED,
            ], $actor);

            return $locked;
        });
    }

    /**
     * Close a wave once every enrolled branch is accounted for.
     *
     * "Accounted for" means COMPLETED or explicitly CANCELLED. A branch that
     * simply never finished blocks the wave — silently ignoring it is the
     * outcome this gate exists to prevent.
     *
     * @throws ValidationException
     */
    public function completeWave(User $actor, LegacyRmeMigrationWave $wave, string $note): LegacyRmeMigrationWave
    {
        $note = $this->assertReason($note);

        return $this->transition($actor, $wave, LegacyRmeWaveStatus::COMPLETED, $note, function (LegacyRmeMigrationWave $locked) use ($actor, $note): array {
            $outstanding = LegacyRmeWaveBranch::query()
                ->where('wave_id', $locked->getKey())
                ->whereNotIn('status', LegacyRmeWaveBranchStatus::TERMINAL)
                ->pluck('branch_code')
                ->all();

            if ($outstanding !== []) {
                throw ValidationException::withMessages([
                    'completion' => sprintf(
                        'Gelombang belum dapat ditutup: cabang %s belum diselesaikan atau dibatalkan.',
                        implode(', ', $outstanding),
                    ),
                ]);
            }

            $waveReconciliation = $this->reconciliation->forWave($locked);

            if (! $waveReconciliation->completable()) {
                throw ValidationException::withMessages([
                    'completion' => sprintf(
                        'Gelombang belum dapat ditutup: %s.',
                        implode(', ', $waveReconciliation->blockers()),
                    ),
                ]);
            }

            return [
                'completed_by' => (int) $actor->getKey(),
                'completed_at' => now(),
                'completion_note' => $note,
            ];
        });
    }

    /**
     * Cancel a wave outright.
     *
     * @throws ValidationException
     */
    public function cancelWave(User $actor, LegacyRmeMigrationWave $wave, string $reason): LegacyRmeMigrationWave
    {
        $reason = $this->assertReason($reason);

        return $this->transition($actor, $wave, LegacyRmeWaveStatus::CANCELLED, $reason, static fn (): array => []);
    }

    /**
     * Assign an operator to one branch of a wave.
     *
     * Re-assigning a previously revoked operator REACTIVATES the existing row
     * rather than inserting a second, so "is this person assigned?" stays a
     * single readable fact instead of "the newest of several rows".
     *
     * @throws ValidationException
     */
    public function assignOperator(
        User $actor,
        LegacyRmeMigrationWave $wave,
        User $operator,
        LegacyRmeWaveBranch $branch,
    ): LegacyRmeWaveOperator {
        if ((int) $branch->wave_id !== (int) $wave->getKey()) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang tersebut tidak termasuk dalam gelombang migrasi ini.',
            ]);
        }

        // An assignment is a narrowing, not a grant. Assigning someone who
        // cannot import at all would create a record implying an authority they
        // do not have.
        if (! $operator->can('create_legacy_rme_imports')) {
            throw ValidationException::withMessages([
                'user_id' => 'Pengguna tersebut belum memiliki izin untuk mengunggah arsip RME lama.',
            ]);
        }

        return DB::transaction(function () use ($actor, $wave, $operator, $branch): LegacyRmeWaveOperator {
            /** @var LegacyRmeWaveOperator $assignment */
            $assignment = LegacyRmeWaveOperator::query()->lockForUpdate()->firstOrNew([
                'wave_id' => (int) $wave->getKey(),
                'user_id' => (int) $operator->getKey(),
                'branch_id' => (int) $branch->branch_id,
            ]);

            $assignment->branch_code = (string) $branch->branch_code;
            $assignment->assigned_by = (int) $actor->getKey();
            $assignment->assigned_at = now();
            $assignment->revoked_at = null;
            $assignment->revoked_by = null;
            $assignment->save();

            $this->audit->logImportEvent(LegacyRmeAuditEvent::WAVE_OPERATOR_ASSIGNED, null, [
                'wave' => $wave->code,
                'branch_code' => $assignment->branch_code,
                // The assigned user's id, not a name — the audit allow-list is
                // structure-only and `actor_id` already names the assigner.
                'operator_user_id' => (int) $operator->getKey(),
            ], $actor);

            return $assignment;
        });
    }

    /**
     * Revoke an assignment. Soft, so the record of who could touch a clinical
     * archive — and when that stopped — survives.
     */
    public function revokeOperator(User $actor, LegacyRmeWaveOperator $assignment): LegacyRmeWaveOperator
    {
        return DB::transaction(function () use ($actor, $assignment): LegacyRmeWaveOperator {
            /** @var LegacyRmeWaveOperator $locked */
            $locked = LegacyRmeWaveOperator::query()->lockForUpdate()->findOrFail($assignment->getKey());

            if ($locked->revoked_at !== null) {
                return $locked;
            }

            $locked->revoked_at = now();
            $locked->revoked_by = (int) $actor->getKey();
            $locked->save();

            $this->audit->logImportEvent(LegacyRmeAuditEvent::WAVE_OPERATOR_REVOKED, null, [
                'wave' => $locked->wave?->code,
                'branch_code' => $locked->branch_code,
                'operator_user_id' => (int) $locked->user_id,
            ], $actor);

            return $locked;
        });
    }

    /**
     * Declare or change a branch's daily quota.
     *
     * @throws ValidationException
     */
    public function setBranchQuota(User $actor, LegacyRmeWaveBranch $branch, ?int $dailyQuota, ?int $plannedDocumentCount): LegacyRmeWaveBranch
    {
        $this->assertQuotaWithinBounds($dailyQuota, 'daily_quota');

        return DB::transaction(function () use ($actor, $branch, $dailyQuota, $plannedDocumentCount): LegacyRmeWaveBranch {
            /** @var LegacyRmeWaveBranch $locked */
            $locked = LegacyRmeWaveBranch::query()->lockForUpdate()->findOrFail($branch->getKey());

            $locked->daily_quota = $dailyQuota;
            // Left NULL when the operator does not know. A fabricated
            // denominator would make every completion percentage a lie.
            $locked->planned_document_count = $plannedDocumentCount;
            $locked->save();

            $this->audit->logImportEvent(LegacyRmeAuditEvent::WAVE_QUOTA_CHANGED, null, [
                'wave' => $locked->wave?->code,
                'branch_code' => $locked->branch_code,
                'daily_quota' => $dailyQuota,
            ], $actor);

            return $locked;
        });
    }

    /**
     * The shared transition mechanism: lock, re-assert, mutate, audit.
     *
     * @param  callable(LegacyRmeMigrationWave): array<string, mixed>  $mutate
     *
     * @throws ValidationException
     */
    private function transition(
        User $actor,
        LegacyRmeMigrationWave $wave,
        string $to,
        ?string $reason,
        callable $mutate,
    ): LegacyRmeMigrationWave {
        return DB::transaction(function () use ($actor, $wave, $to, $reason, $mutate): LegacyRmeMigrationWave {
            /** @var LegacyRmeMigrationWave $locked */
            $locked = LegacyRmeMigrationWave::query()->lockForUpdate()->findOrFail($wave->getKey());

            // Re-asserted INSIDE the lock. The status read before the
            // transaction is only a hint; two operators acting at once would
            // both have seen ACTIVE.
            if (! LegacyRmeWaveStatus::canTransition($locked->status, $to)) {
                throw ValidationException::withMessages([
                    'status' => sprintf('Gelombang tidak dapat berpindah dari %s ke %s.', $locked->status, $to),
                ]);
            }

            $attributes = $mutate($locked);

            $locked->fill($attributes);
            $locked->status = $to;
            $locked->save();

            $this->audit->logImportEvent(LegacyRmeAuditEvent::WAVE_TRANSITIONED, null, array_filter([
                'wave' => $locked->code,
                'status' => $to,
                'reason_length' => $reason !== null ? mb_strlen($reason) : null,
            ], static fn ($value): bool => $value !== null), $actor);

            return $locked->refresh();
        });
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function normalizeBranchCodes(array $codes): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($code): string => strtoupper(trim((string) $code)), $codes),
            static fn (string $code): bool => $code !== '',
        )));
    }

    /**
     * @throws ValidationException
     */
    private function assertQuotaWithinBounds(?int $quota, string $field): void
    {
        if ($quota === null) {
            return;
        }

        if ($quota < 0) {
            throw ValidationException::withMessages([$field => 'Kuota harian tidak boleh negatif.']);
        }

        $max = (int) config('legacy_rme_operations.quota.max_declarable_daily', 500);

        if ($max > 0 && $quota > $max) {
            // A quota is a safety rail; letting someone type 1,000,000 turns the
            // rail into decoration. Refused server-side, not merely absent from
            // the form.
            throw ValidationException::withMessages([
                $field => sprintf('Kuota harian tidak boleh melebihi %d dokumen.', $max),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertReason(string $reason): string
    {
        $reason = trim($reason);
        $min = (int) config('legacy_rme_operations.min_reason_length', 10);

        if (mb_strlen($reason) < $min) {
            throw ValidationException::withMessages([
                'reason' => sprintf('Alasan wajib diisi minimal %d karakter.', $min),
            ]);
        }

        return $reason;
    }
}
