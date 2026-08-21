<?php

namespace App\Modules\MedicalRecord\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\DiagnosisRequirementOverride;
use App\Modules\MedicalRecord\Models\DiagnosisRolloutSetting;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4B — branch-scoped structured diagnosis rollout.
 *
 * Modes: disabled | informational | warning | pilot_enforced. The mode is
 * resolved SERVER-SIDE from the medical record's branch — never from request
 * input. There is no global enforcement switch: pilot_enforced exists only as
 * an explicit per-branch, reasoned, audited setting, and the config default
 * for unconfigured branches must stay non-blocking.
 *
 * pilot_enforced blocks RME finalization until the record carries at least one
 * PRIMARY structured diagnosis whose master terminology is ACTIVE — or a
 * reasoned, time-boxed emergency override exists. An override never makes the
 * SATUSEHAT candidate ready; the missing-diagnosis issue stays open.
 */
class DiagnosisRolloutService
{
    public function __construct(
        private readonly BranchService $branches,
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /** Effective rollout mode for a branch (config default when unconfigured). */
    public function modeForBranch(int $branchId): string
    {
        if (! in_array($branchId, $this->branches->rmeEnabledIds(), true)) {
            return DiagnosisRolloutSetting::MODE_DISABLED;
        }

        $setting = DiagnosisRolloutSetting::query()->where('branch_id', $branchId)->first();
        if ($setting !== null && in_array($setting->mode, DiagnosisRolloutSetting::MODES, true)) {
            return $setting->mode;
        }

        return $this->defaultMode();
    }

    /**
     * Safe default for unconfigured branches. A blocking default is refused —
     * global hard enforcement is forbidden by design.
     */
    public function defaultMode(): string
    {
        $default = (string) config('clinical_diagnosis_rollout.default_mode', DiagnosisRolloutSetting::MODE_INFORMATIONAL);

        return in_array($default, DiagnosisRolloutSetting::NON_BLOCKING_MODES, true)
            ? $default
            : DiagnosisRolloutSetting::MODE_INFORMATIONAL;
    }

    /**
     * Rollout board: every active RME-enabled branch with its effective mode.
     *
     * @return Collection<int, array{branch: Branch, mode: string, setting: ?DiagnosisRolloutSetting}>
     */
    public function board(): Collection
    {
        $settings = DiagnosisRolloutSetting::query()
            ->with('configuredBy:id,name')
            ->get()
            ->keyBy('branch_id');

        return $this->branches->listRmeEnabled()
            ->map(fn (Branch $branch) => [
                'branch' => $branch,
                'mode' => $settings->has($branch->id) && in_array($settings[$branch->id]->mode, DiagnosisRolloutSetting::MODES, true)
                    ? $settings[$branch->id]->mode
                    : $this->defaultMode(),
                'setting' => $settings->get($branch->id),
            ])
            ->values();
    }

    /** Explicitly configure a branch's rollout mode (reasoned + audited). */
    public function setMode(Branch $branch, string $mode, string $reason, User $actor): DiagnosisRolloutSetting
    {
        if (! in_array($mode, DiagnosisRolloutSetting::MODES, true)) {
            throw ValidationException::withMessages(['mode' => 'Mode rollout tidak dikenal.']);
        }

        if (! in_array((int) $branch->id, $this->branches->rmeEnabledIds(), true)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Mode rollout hanya dapat dikonfigurasi untuk cabang RME aktif.',
            ]);
        }

        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < (int) config('clinical_diagnosis_rollout.override.min_reason_length', 10)) {
            throw ValidationException::withMessages(['reason' => 'Alasan perubahan mode wajib diisi dengan jelas.']);
        }

        return DB::transaction(function () use ($branch, $mode, $reason, $actor) {
            $setting = DiagnosisRolloutSetting::query()
                ->where('branch_id', $branch->id)
                ->lockForUpdate()
                ->first();

            $previous = $setting?->mode ?? $this->defaultMode();

            if ($setting === null) {
                try {
                    $setting = DiagnosisRolloutSetting::create([
                        'branch_id' => (int) $branch->id,
                        'mode' => $mode,
                        'reason' => $reason,
                        'configured_by' => $actor->id,
                    ]);
                } catch (QueryException) {
                    // First-configuration race on the branch unique constraint:
                    // re-fetch under lock and update instead of 500ing.
                    $setting = DiagnosisRolloutSetting::query()
                        ->where('branch_id', $branch->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $setting->update([
                        'mode' => $mode,
                        'reason' => $reason,
                        'configured_by' => $actor->id,
                    ]);
                }
            } else {
                $setting->update([
                    'mode' => $mode,
                    'reason' => $reason,
                    'configured_by' => $actor->id,
                ]);
            }

            $this->audit->log(
                'diagnosis_rollout',
                (int) $branch->id,
                SatusehatAuditLog::EVENT_ROLLOUT_MODE_CHANGED,
                'Mode rollout diagnosis terstruktur diubah',
                ['previous_mode' => $previous, 'mode' => $mode],
                (int) $branch->id,
                $actor,
            );

            return $setting;
        });
    }

    /**
     * Server-side enforcement state for a medical record — the single source
     * the RM page banner AND the finalize gate both read.
     *
     * @return array{mode: string, has_active_primary: bool, override_active: bool, blocking: bool}
     */
    public function enforcementStateFor(MedicalRecord $medicalRecord): array
    {
        $mode = $this->modeForBranch((int) $medicalRecord->branch_id);
        $hasPrimary = $this->hasActivePrimaryDiagnosis($medicalRecord);
        $override = $hasPrimary ? false : $this->hasUsableOverride($medicalRecord);

        return [
            'mode' => $mode,
            'has_active_primary' => $hasPrimary,
            'override_active' => $override,
            'blocking' => $mode === DiagnosisRolloutSetting::MODE_PILOT_ENFORCED && ! $hasPrimary && ! $override,
        ];
    }

    /** Finalize gate — throws only in pilot_enforced without primary/override. */
    public function assertFinalizationAllowed(MedicalRecord $medicalRecord): void
    {
        $state = $this->enforcementStateFor($medicalRecord);

        if ($state['blocking']) {
            throw ValidationException::withMessages([
                'diagnoses' => 'Cabang ini mewajibkan minimal satu diagnosis utama terstruktur sebelum RME difinalkan. '
                    .'Catat diagnosis pada kartu "Diagnosis Terstruktur", atau gunakan override darurat beralasan bila kondisi klinis menuntutnya.',
            ]);
        }
    }

    /** At least one non-deleted PRIMARY diagnosis whose master is ACTIVE. */
    public function hasActivePrimaryDiagnosis(MedicalRecord $medicalRecord): bool
    {
        return MedicalRecordDiagnosis::query()
            ->where('medical_record_id', $medicalRecord->id)
            ->where('diagnosis_role', MedicalRecordDiagnosis::ROLE_PRIMARY)
            ->whereHas('clinicalDiagnosis', fn ($q) => $q->where('status', ClinicalDiagnosis::STATUS_ACTIVE))
            ->exists();
    }

    public function hasUsableOverride(MedicalRecord $medicalRecord): bool
    {
        return DiagnosisRequirementOverride::query()
            ->where('medical_record_id', $medicalRecord->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    /**
     * Grant a reasoned, time-boxed emergency override (append-only, audited).
     * Permission (override_diagnosis_requirement) is enforced at the route;
     * the medical-record update authority is re-checked by the controller.
     */
    public function grantOverride(MedicalRecord $medicalRecord, string $reason, User $actor): DiagnosisRequirementOverride
    {
        /*
         * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-02 — granting an
         * emergency override is an authoring act on this visit's record (it
         * unlocks finalization), so it waits for consent like every other write.
         */
        app(RmeVisitConsentService::class)
            ->assertRmeAuthoringAllowed($medicalRecord->clinicVisit);

        $mode = $this->modeForBranch((int) $medicalRecord->branch_id);
        if ($mode !== DiagnosisRolloutSetting::MODE_PILOT_ENFORCED) {
            throw ValidationException::withMessages([
                'reason' => 'Override hanya relevan pada cabang dengan mode pilot_enforced.',
            ]);
        }

        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < (int) config('clinical_diagnosis_rollout.override.min_reason_length', 10)) {
            throw ValidationException::withMessages(['reason' => 'Alasan override darurat wajib diisi dengan jelas (min. 10 karakter).']);
        }

        return DB::transaction(function () use ($medicalRecord, $reason, $actor) {
            $ttl = max(1, (int) config('clinical_diagnosis_rollout.override.ttl_hours', 24));

            $override = DiagnosisRequirementOverride::create([
                'medical_record_id' => (int) $medicalRecord->id,
                'clinic_visit_id' => (int) $medicalRecord->clinic_visit_id,
                'branch_id' => (int) $medicalRecord->branch_id,
                'used_by' => $actor->id,
                'reason' => $reason,
                'expires_at' => now()->addHours($ttl),
            ]);

            $this->audit->log(
                'medical_record',
                (int) $medicalRecord->id,
                SatusehatAuditLog::EVENT_DIAGNOSIS_OVERRIDE_GRANTED,
                'Override darurat kewajiban diagnosis terstruktur digunakan',
                ['override_id' => (int) $override->id, 'ttl_hours' => $ttl],
                (int) $medicalRecord->branch_id,
                $actor,
            );

            return $override;
        });
    }
}
