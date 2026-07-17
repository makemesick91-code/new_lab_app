<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatBranchPilotProfile;
use App\Modules\Satusehat\Models\SatusehatRolloutWave;
use App\Modules\Satusehat\Models\SatusehatUatRun;
use App\Modules\Satusehat\Models\SatusehatUatScenario;
use App\Modules\Satusehat\Models\SatusehatUatSignoff;
use App\Modules\Satusehat\Models\SatusehatWaveBranchMembership;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4D — human operator UAT workflow + sign-off.
 *
 * Records REAL operator UAT: runs, per-scenario results, and role-based
 * sign-offs. A run can only reach SIGNED_OFF when every required role has an
 * 'approved' sign-off AND no scenario failed — and that signed-off state is the
 * mandatory precondition for an operational GO decision. Evidence stays
 * synthetic / PII-safe. Sign-offs are never fabricated by the system; a real
 * user records their decision (operator_name/role identify the accountable
 * human). Transactional, audited.
 */
class SatusehatUatService
{
    public function __construct(
        private readonly SatusehatAuditLogger $audit,
    ) {}

    private function env(): string
    {
        return (string) config('satusehat.environment');
    }

    public function createRun(array $data, User $actor): SatusehatUatRun
    {
        $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 200);
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Judul sesi UAT wajib diisi.']);
        }

        $waveId = $data['rollout_wave_id'] ?? null;
        if ($waveId !== null && ! SatusehatRolloutWave::query()
            ->where('environment', $this->env())->whereKey($waveId)->exists()) {
            throw ValidationException::withMessages(['rollout_wave_id' => 'Wave tidak ditemukan.']);
        }

        $run = SatusehatUatRun::create([
            'environment' => $this->env(),
            'rollout_wave_id' => $waveId,
            'title' => $title,
            'status' => SatusehatUatRun::STATUS_DRAFT,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->log($run, SatusehatAuditLog::EVENT_UAT_RUN_CREATED, 'Sesi UAT dibuat', $actor);

        return $run->refresh();
    }

    /** Record a single scenario result (moves the run to in_progress). */
    public function recordScenario(SatusehatUatRun $run, array $data, User $actor): SatusehatUatScenario
    {
        $outcomes = (array) config('satusehat_pilot.uat.outcomes', []);
        $outcome = (string) ($data['outcome'] ?? SatusehatUatScenario::OUTCOME_PENDING);
        if (! in_array($outcome, $outcomes, true)) {
            throw ValidationException::withMessages(['outcome' => 'Hasil skenario tidak dikenal.']);
        }

        return DB::transaction(function () use ($run, $data, $outcome, $actor) {
            $locked = SatusehatUatRun::query()->lockForUpdate()->findOrFail($run->id);
            if (in_array($locked->status, [SatusehatUatRun::STATUS_SIGNED_OFF, SatusehatUatRun::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages(['run' => 'Sesi UAT sudah final.']);
            }

            $scenario = SatusehatUatScenario::create([
                'uat_run_id' => $locked->id,
                'scenario_code' => mb_substr((string) ($data['scenario_code'] ?? 'GENERIC'), 0, 60),
                'role' => mb_substr((string) ($data['role'] ?? 'unknown'), 0, 60),
                'branch_id' => $data['branch_id'] ?? null,
                'precondition' => isset($data['precondition']) ? mb_substr((string) $data['precondition'], 0, 1000) : null,
                'steps' => isset($data['steps']) ? mb_substr((string) $data['steps'], 0, 2000) : null,
                'expected_result' => isset($data['expected_result']) ? mb_substr((string) $data['expected_result'], 0, 1000) : null,
                'actual_result' => isset($data['actual_result']) ? mb_substr((string) $data['actual_result'], 0, 1000) : null,
                'outcome' => $outcome,
                'finding_severity' => $data['finding_severity'] ?? null,
                'evidence_reference' => isset($data['evidence_reference']) ? mb_substr((string) $data['evidence_reference'], 0, 500) : null,
                'operator_name' => isset($data['operator_name']) ? mb_substr((string) $data['operator_name'], 0, 150) : null,
                'operator_role' => isset($data['operator_role']) ? mb_substr((string) $data['operator_role'], 0, 60) : null,
                'executed_at' => now(),
            ]);

            if ($locked->status === SatusehatUatRun::STATUS_DRAFT) {
                $locked->update(['status' => SatusehatUatRun::STATUS_IN_PROGRESS, 'started_at' => now()]);
            }

            $this->log($locked, SatusehatAuditLog::EVENT_UAT_SCENARIO_RECORDED, 'Hasil skenario UAT dicatat', $actor, [
                'scenario_code' => $scenario->scenario_code, 'outcome' => $outcome,
            ]);

            return $scenario;
        });
    }

    /** Record one role's human sign-off (approved | rejected). */
    public function recordSignoff(SatusehatUatRun $run, array $data, User $actor): SatusehatUatSignoff
    {
        $role = mb_substr((string) ($data['role'] ?? ''), 0, 60);
        if (! in_array($role, (array) config('satusehat_pilot.uat.required_signoff_roles', []), true)) {
            throw ValidationException::withMessages(['role' => 'Peran sign-off tidak dikenal.']);
        }
        $decision = (string) ($data['decision'] ?? '');
        if (! in_array($decision, (array) config('satusehat_pilot.uat.signoff_decisions', []), true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan sign-off tidak valid.']);
        }
        $operatorName = mb_substr(trim((string) ($data['operator_name'] ?? '')), 0, 150);
        if ($operatorName === '') {
            throw ValidationException::withMessages(['operator_name' => 'Nama operator penandatangan wajib diisi.']);
        }

        return DB::transaction(function () use ($run, $role, $decision, $operatorName, $data, $actor) {
            $locked = SatusehatUatRun::query()->lockForUpdate()->findOrFail($run->id);
            if (in_array($locked->status, [SatusehatUatRun::STATUS_SIGNED_OFF, SatusehatUatRun::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages(['run' => 'Sesi UAT sudah final.']);
            }

            $signoff = SatusehatUatSignoff::updateOrCreate(
                ['uat_run_id' => $locked->id, 'role' => $role],
                [
                    'signed_by_user_id' => $actor->id,
                    'operator_name' => $operatorName,
                    'operator_role' => mb_substr((string) ($data['operator_role'] ?? $role), 0, 60),
                    'decision' => $decision,
                    'notes' => isset($data['notes']) ? mb_substr((string) $data['notes'], 0, 500) : null,
                    'signed_at' => now(),
                ],
            );

            $this->log($locked, SatusehatAuditLog::EVENT_UAT_SIGNED_OFF, 'Sign-off UAT dicatat', $actor, [
                'role' => $role, 'decision' => $decision,
            ]);

            return $signoff;
        });
    }

    /**
     * Finalize a run to SIGNED_OFF. Requires: every required role has an
     * 'approved' sign-off, at least one scenario, and no failed scenario.
     * Stamps enrolled-branch profiles (uat passed).
     */
    public function finalize(SatusehatUatRun $run, User $actor): SatusehatUatRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $locked = SatusehatUatRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status === SatusehatUatRun::STATUS_SIGNED_OFF) {
                return $locked; // idempotent
            }
            if ($locked->status === SatusehatUatRun::STATUS_REJECTED) {
                throw ValidationException::withMessages(['run' => 'Sesi UAT sudah ditolak.']);
            }

            $requiredRoles = (array) config('satusehat_pilot.uat.required_signoff_roles', []);
            $approvedSignoffs = SatusehatUatSignoff::query()
                ->where('uat_run_id', $locked->id)
                ->where('decision', SatusehatUatSignoff::DECISION_APPROVED)
                ->get();
            $approved = $approvedSignoffs->pluck('role')->all();
            $missing = array_values(array_diff($requiredRoles, $approved));
            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'signoff' => 'Sign-off belum lengkap. Peran belum menyetujui: '.implode(', ', $missing),
                ]);
            }

            // Separation of duties: a single human must not attest multiple
            // required roles. Each required role's approved sign-off must carry a
            // DISTINCT operator_name (a facilitator may enter them, but the named
            // accountable operators must differ). Sign-offs are never fabricated.
            $requiredNames = $approvedSignoffs
                ->whereIn('role', $requiredRoles)
                ->map(fn ($s) => mb_strtolower(trim((string) $s->operator_name)))
                ->filter()
                ->values();
            if ($requiredNames->unique()->count() < count($requiredRoles)) {
                throw ValidationException::withMessages([
                    'signoff' => 'Setiap peran wajib ditandatangani oleh operator yang berbeda (segregation of duties). Nama operator tidak boleh sama antar peran.',
                ]);
            }

            $scenarioCount = SatusehatUatScenario::where('uat_run_id', $locked->id)->count();
            if ($scenarioCount === 0) {
                throw ValidationException::withMessages(['run' => 'Belum ada skenario UAT yang dicatat.']);
            }
            $failed = SatusehatUatScenario::where('uat_run_id', $locked->id)
                ->where('outcome', SatusehatUatScenario::OUTCOME_FAIL)->count();
            if ($failed > 0) {
                throw ValidationException::withMessages([
                    'run' => 'Masih ada skenario UAT yang GAGAL — perbaiki dan uji ulang sebelum sign-off.',
                ]);
            }

            $locked->update(['status' => SatusehatUatRun::STATUS_SIGNED_OFF, 'completed_at' => now()]);
            $this->stampBranches($locked, $actor);
            $this->log($locked, SatusehatAuditLog::EVENT_UAT_SIGNED_OFF, 'Sesi UAT disetujui penuh (signed off)', $actor);

            return $locked->refresh();
        });
    }

    public function reject(SatusehatUatRun $run, string $reason, User $actor): SatusehatUatRun
    {
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < (int) config('satusehat_pilot.uat.min_reason_length', 10)) {
            throw ValidationException::withMessages(['reason' => 'Alasan penolakan UAT wajib diisi (min. 10 karakter).']);
        }

        return DB::transaction(function () use ($run, $reason, $actor) {
            $locked = SatusehatUatRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status === SatusehatUatRun::STATUS_SIGNED_OFF) {
                throw ValidationException::withMessages(['run' => 'Sesi UAT sudah disetujui.']);
            }
            $locked->update(['status' => SatusehatUatRun::STATUS_REJECTED, 'completed_at' => now()]);
            $this->log($locked, SatusehatAuditLog::EVENT_UAT_REJECTED, 'Sesi UAT ditolak', $actor, ['reason' => $reason]);

            return $locked->refresh();
        });
    }

    /** Stamp the wave's enrolled branch profiles as UAT-passed. */
    private function stampBranches(SatusehatUatRun $run, User $actor): void
    {
        if ($run->rollout_wave_id === null) {
            return;
        }
        $branchIds = SatusehatWaveBranchMembership::query()
            ->where('environment', $this->env())
            ->where('rollout_wave_id', $run->rollout_wave_id)
            ->where('status', SatusehatWaveBranchMembership::STATUS_ENROLLED)
            ->pluck('branch_id')->all();

        SatusehatBranchPilotProfile::query()
            ->where('environment', $this->env())
            ->whereIn('branch_id', $branchIds)
            ->update(['uat_status' => 'passed', 'last_uat_signed_off_at' => now()]);
    }

    private function log(SatusehatUatRun $run, string $event, string $summary, User $actor, array $context = []): void
    {
        $this->audit->log(
            'satusehat_uat_run',
            (int) $run->id,
            $event,
            $summary,
            $context + ['run' => $run->title, 'status' => $run->status],
            null,
            $actor,
        );
    }
}
