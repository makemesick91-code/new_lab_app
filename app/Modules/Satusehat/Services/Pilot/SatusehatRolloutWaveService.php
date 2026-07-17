<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatRolloutWave;
use App\Modules\Satusehat\Models\SatusehatWaveBranchMembership;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4D — controlled multi-branch rollout wave lifecycle.
 *
 * Every mutation is transactional, locked, audited, and branch-scoped to the
 * RME-enabled set (MAIN can never be enrolled). No wave is active by default.
 * A branch may belong to only ONE active wave. A wave never enables SATUSEHAT
 * external send or production — "pilot_ready_internal" is INTERNAL readiness
 * only; the external credential blocker is always separate.
 */
class SatusehatRolloutWaveService
{
    public function __construct(
        private readonly BranchService $branches,
        private readonly SatusehatAuditLogger $audit,
    ) {}

    private function env(): string
    {
        return (string) config('satusehat.environment');
    }

    /** Create a draft wave (never active by default). */
    public function createWave(array $data, User $actor): SatusehatRolloutWave
    {
        $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 150);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Nama wave wajib diisi.']);
        }

        return DB::transaction(function () use ($data, $name, $actor) {
            $exists = SatusehatRolloutWave::query()
                ->where('environment', $this->env())->where('name', $name)->lockForUpdate()->exists();
            if ($exists) {
                throw ValidationException::withMessages(['name' => 'Nama wave sudah digunakan pada environment ini.']);
            }

            $wave = SatusehatRolloutWave::create([
                'environment' => $this->env(),
                'name' => $name,
                'sequence' => (int) ($data['sequence'] ?? 1),
                'status' => SatusehatRolloutWave::STATUS_DRAFT,
                'scope' => isset($data['scope']) ? mb_substr((string) $data['scope'], 0, 500) : null,
                'target_date' => $data['target_date'] ?? null,
                'operational_owner_id' => $data['operational_owner_id'] ?? null,
                'clinical_owner_id' => $data['clinical_owner_id'] ?? null,
                'technical_owner_id' => $data['technical_owner_id'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->logWave($wave, SatusehatAuditLog::EVENT_WAVE_CREATED, 'Wave rollout dibuat (draf)', $actor);

            return $wave->refresh();
        });
    }

    /** Enroll an RME-enabled branch into a wave (one active wave per branch). */
    public function enrollBranch(SatusehatRolloutWave $wave, Branch $branch, User $actor): SatusehatWaveBranchMembership
    {
        $this->assertEnrollableWave($wave);
        $this->assertRmeBranch($branch);

        return DB::transaction(function () use ($wave, $branch, $actor) {
            // Re-assert single-active membership under lock (partial unique index
            // is the hard backstop; this gives a friendly message first).
            $active = SatusehatWaveBranchMembership::query()
                ->where('environment', $this->env())
                ->where('branch_id', (int) $branch->id)
                ->where('status', SatusehatWaveBranchMembership::STATUS_ENROLLED)
                ->lockForUpdate()
                ->first();

            if ($active !== null) {
                if ((int) $active->rollout_wave_id === (int) $wave->id) {
                    return $active; // idempotent
                }
                throw ValidationException::withMessages([
                    'branch_id' => 'Cabang sudah terdaftar pada wave aktif lain. Keluarkan dari wave tersebut terlebih dahulu.',
                ]);
            }

            try {
                $membership = SatusehatWaveBranchMembership::create([
                    'environment' => $this->env(),
                    'rollout_wave_id' => (int) $wave->id,
                    'branch_id' => (int) $branch->id,
                    'status' => SatusehatWaveBranchMembership::STATUS_ENROLLED,
                    'enrolled_by' => $actor->id,
                    'enrolled_at' => now(),
                ]);
            } catch (QueryException $e) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Cabang sudah terdaftar pada wave aktif lain. Keluarkan dari wave tersebut terlebih dahulu.',
                ]);
            }

            $this->logWave($wave, SatusehatAuditLog::EVENT_WAVE_BRANCH_ENROLLED, 'Cabang didaftarkan ke wave', $actor, [
                'branch_id' => (int) $branch->id,
            ]);

            return $membership->refresh();
        });
    }

    /** Remove a branch from a wave (audited, reason required). */
    public function removeBranch(SatusehatRolloutWave $wave, Branch $branch, string $reason, User $actor): void
    {
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < (int) config('satusehat_pilot.change_control.min_reason_length', 10)) {
            throw ValidationException::withMessages(['reason' => 'Alasan pengeluaran cabang wajib diisi (min. 10 karakter).']);
        }

        DB::transaction(function () use ($wave, $branch, $reason, $actor) {
            $membership = SatusehatWaveBranchMembership::query()
                ->where('environment', $this->env())
                ->where('rollout_wave_id', (int) $wave->id)
                ->where('branch_id', (int) $branch->id)
                ->where('status', SatusehatWaveBranchMembership::STATUS_ENROLLED)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                throw ValidationException::withMessages(['branch_id' => 'Cabang tidak terdaftar aktif pada wave ini.']);
            }

            $membership->update([
                'status' => SatusehatWaveBranchMembership::STATUS_REMOVED,
                'removed_by' => $actor->id,
                'removed_at' => now(),
                'removal_reason' => $reason,
            ]);

            $this->logWave($wave, SatusehatAuditLog::EVENT_WAVE_BRANCH_REMOVED, 'Cabang dikeluarkan dari wave', $actor, [
                'branch_id' => (int) $branch->id,
                'reason' => $reason,
            ]);
        });
    }

    /** Approve a wave (requires ≥1 enrolled branch; enforces single-active-wave). */
    public function approveWave(SatusehatRolloutWave $wave, User $actor): SatusehatRolloutWave
    {
        return DB::transaction(function () use ($wave, $actor) {
            $locked = SatusehatRolloutWave::query()->lockForUpdate()->findOrFail($wave->id);

            if ($locked->isTerminal()) {
                throw ValidationException::withMessages(['wave' => 'Wave sudah ditutup dan tidak dapat disetujui.']);
            }

            $enrolled = SatusehatWaveBranchMembership::query()
                ->where('environment', $this->env())
                ->where('rollout_wave_id', $locked->id)
                ->where('status', SatusehatWaveBranchMembership::STATUS_ENROLLED)
                ->count();
            if ($enrolled === 0) {
                throw ValidationException::withMessages(['wave' => 'Wave tidak memiliki cabang terdaftar — daftarkan minimal satu cabang RME.']);
            }

            $this->assertNoOtherActiveWave($locked->id);

            $locked->update([
                'status' => SatusehatRolloutWave::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'started_at' => $locked->started_at ?? now(),
                'suspended_at' => null,
                'suspension_reason' => null,
                'updated_by' => $actor->id,
            ]);

            $this->logWave($locked, SatusehatAuditLog::EVENT_WAVE_APPROVED, 'Wave disetujui (governance)', $actor);

            return $locked->refresh();
        });
    }

    /** Advance a wave to another non-terminal status (internal progression only). */
    public function changeStatus(SatusehatRolloutWave $wave, string $status, User $actor): SatusehatRolloutWave
    {
        $allowed = (array) config('satusehat_pilot.multi_branch.wave_states', []);
        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'Status wave tidak dikenal.']);
        }
        // Suspend/resume/close have dedicated methods.
        if (in_array($status, [SatusehatRolloutWave::STATUS_SUSPENDED, SatusehatRolloutWave::STATUS_CLOSED], true)) {
            throw ValidationException::withMessages(['status' => 'Gunakan aksi khusus untuk menangguhkan atau menutup wave.']);
        }

        return DB::transaction(function () use ($wave, $status, $actor) {
            $locked = SatusehatRolloutWave::query()->lockForUpdate()->findOrFail($wave->id);
            if ($locked->isTerminal()) {
                throw ValidationException::withMessages(['status' => 'Wave sudah ditutup.']);
            }

            if (in_array($status, SatusehatRolloutWave::ACTIVE_STATUSES, true)) {
                $this->assertNoOtherActiveWave($locked->id);
            }

            $from = $locked->status;
            $locked->update(['status' => $status, 'updated_by' => $actor->id]);
            $this->logWave($locked, SatusehatAuditLog::EVENT_WAVE_STATUS_CHANGED, 'Status wave diperbarui', $actor, [
                'from' => $from, 'to' => $status,
            ]);

            return $locked->refresh();
        });
    }

    /** Reversibly suspend a wave (audited, reason required). */
    public function suspendWave(SatusehatRolloutWave $wave, string $reason, User $actor): SatusehatRolloutWave
    {
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < (int) config('satusehat_pilot.change_control.min_reason_length', 10)) {
            throw ValidationException::withMessages(['reason' => 'Alasan penangguhan wave wajib diisi (min. 10 karakter).']);
        }

        return DB::transaction(function () use ($wave, $reason, $actor) {
            $locked = SatusehatRolloutWave::query()->lockForUpdate()->findOrFail($wave->id);
            if ($locked->isTerminal()) {
                throw ValidationException::withMessages(['wave' => 'Wave sudah ditutup.']);
            }
            $locked->update([
                'status' => SatusehatRolloutWave::STATUS_SUSPENDED,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
                'updated_by' => $actor->id,
            ]);
            $this->logWave($locked, SatusehatAuditLog::EVENT_WAVE_SUSPENDED, 'Wave ditangguhkan', $actor, ['reason' => $reason]);

            return $locked->refresh();
        });
    }

    /** Resume a suspended wave back to profiling. */
    public function resumeWave(SatusehatRolloutWave $wave, User $actor): SatusehatRolloutWave
    {
        return DB::transaction(function () use ($wave, $actor) {
            $locked = SatusehatRolloutWave::query()->lockForUpdate()->findOrFail($wave->id);
            if ($locked->status !== SatusehatRolloutWave::STATUS_SUSPENDED) {
                throw ValidationException::withMessages(['wave' => 'Wave tidak dalam status ditangguhkan.']);
            }
            $this->assertNoOtherActiveWave($locked->id);
            $locked->update([
                'status' => SatusehatRolloutWave::STATUS_PROFILING,
                'suspended_at' => null,
                'suspension_reason' => null,
                'updated_by' => $actor->id,
            ]);
            $this->logWave($locked, SatusehatAuditLog::EVENT_WAVE_RESUMED, 'Wave dilanjutkan', $actor);

            return $locked->refresh();
        });
    }

    /** Close a wave (terminal). Active memberships are marked removed. */
    public function closeWave(SatusehatRolloutWave $wave, User $actor): SatusehatRolloutWave
    {
        return DB::transaction(function () use ($wave, $actor) {
            $locked = SatusehatRolloutWave::query()->lockForUpdate()->findOrFail($wave->id);

            SatusehatWaveBranchMembership::query()
                ->where('environment', $this->env())
                ->where('rollout_wave_id', $locked->id)
                ->where('status', SatusehatWaveBranchMembership::STATUS_ENROLLED)
                ->update([
                    'status' => SatusehatWaveBranchMembership::STATUS_REMOVED,
                    'removed_by' => $actor->id,
                    'removed_at' => now(),
                    'removal_reason' => 'Wave ditutup',
                ]);

            $locked->update([
                'status' => SatusehatRolloutWave::STATUS_CLOSED,
                'completed_at' => now(),
                'updated_by' => $actor->id,
            ]);
            $this->logWave($locked, SatusehatAuditLog::EVENT_WAVE_CLOSED, 'Wave ditutup (terminal)', $actor);

            return $locked->refresh();
        });
    }

    private function assertEnrollableWave(SatusehatRolloutWave $wave): void
    {
        if ($wave->isTerminal() || $wave->status === SatusehatRolloutWave::STATUS_SUSPENDED) {
            throw ValidationException::withMessages(['wave' => 'Wave tidak menerima pendaftaran cabang pada status ini.']);
        }
    }

    private function assertRmeBranch(Branch $branch): void
    {
        if (! in_array((int) $branch->id, $this->branches->rmeEnabledIds(), true)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Hanya cabang RME aktif (bukan MAIN) yang dapat didaftarkan ke wave.',
            ]);
        }
    }

    private function assertNoOtherActiveWave(int $waveId): void
    {
        if ((bool) config('satusehat_pilot.multi_branch.allow_multiple_active_waves', false)) {
            return;
        }

        $other = SatusehatRolloutWave::query()
            ->where('environment', $this->env())
            ->where('id', '!=', $waveId)
            ->whereIn('status', SatusehatRolloutWave::ACTIVE_STATUSES)
            ->lockForUpdate()
            ->exists();

        if ($other) {
            throw ValidationException::withMessages([
                'wave' => 'Sudah ada wave aktif lain. Tutup atau tangguhkan wave tersebut terlebih dahulu.',
            ]);
        }
    }

    private function logWave(SatusehatRolloutWave $wave, string $event, string $summary, User $actor, array $context = []): void
    {
        $this->audit->log(
            'satusehat_rollout_wave',
            (int) $wave->id,
            $event,
            $summary,
            $context + ['wave' => $wave->name, 'status' => $wave->status],
            $context['branch_id'] ?? null,
            $actor,
        );
    }
}
