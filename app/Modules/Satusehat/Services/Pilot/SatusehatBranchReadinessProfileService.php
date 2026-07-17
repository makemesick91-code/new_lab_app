<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatBranchPilotProfile;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Models\SatusehatPilotRehearsalRun;
use App\Modules\Satusehat\Services\DataQuality\SatusehatOperationalReadinessService;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use App\Modules\Satusehat\Services\SatusehatDiagnosisAdoptionService;

/**
 * SATUSEHAT-4C — per-branch readiness profiling.
 *
 * Computes a deterministic, PII-free readiness snapshot for a branch (rates +
 * issue counts), scores it, derives the readiness stage via the eligibility
 * gate, and persists it to the branch pilot profile. Read-only against clinical
 * data; branch-scoped server-side; never HTTP. Reuses the SATUSEHAT-4A
 * operational readiness aggregates and the SATUSEHAT-4B diagnosis adoption
 * metrics — no duplicate engine.
 */
class SatusehatBranchReadinessProfileService
{
    private const PATIENT_RULES = ['patient_identity', 'patient_demographics'];

    public function __construct(
        private readonly BranchService $branches,
        private readonly SatusehatDiagnosisAdoptionService $adoption,
        private readonly SatusehatOperationalReadinessService $readiness,
        private readonly SatusehatReadinessScoreService $score,
        private readonly SatusehatInternalPilotEligibilityService $eligibility,
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /**
     * Pure readiness snapshot for a branch (no persistence).
     *
     * @return array<string, mixed>
     */
    public function computeSnapshot(int $branchId): array
    {
        $env = (string) config('satusehat.environment');
        $branchRme = in_array($branchId, $this->branches->rmeEnabledIds(), true);

        $total = SatusehatCandidate::query()
            ->where('environment', $env)->where('branch_id', $branchId)->count();

        $open = fn () => SatusehatDataQualityIssue::query()
            ->where('environment', $env)
            ->where('branch_id', $branchId)
            ->whereIn('status', SatusehatDataQualityIssue::OPEN_STATUSES);

        $openHard = (clone $open())->where('severity', SatusehatDataQualityIssue::SEVERITY_HARD)->count();
        $openSoft = (clone $open())->where('severity', SatusehatDataQualityIssue::SEVERITY_SOFT)->count();
        $openCritical = (clone $open())->where('priority', SatusehatDataQualityIssue::PRIORITY_CRITICAL_INTERNAL)->count();
        $overdueCritical = (clone $open())
            ->where('priority', SatusehatDataQualityIssue::PRIORITY_CRITICAL_INTERNAL)
            ->whereNotNull('due_at')->where('due_at', '<', now())->count();

        $affected = fn (array $codes) => (clone $open())
            ->whereIn('rule_code', $codes)->distinct()->count('satusehat_candidate_id');

        $patientAffected = $affected(self::PATIENT_RULES);
        $conformanceAffected = $affected(['local_conformance']);
        $diagnosisMappingAffected = $affected(['diagnosis_mapping']);
        $sourceChanged = $affected(['source_drift']);

        // Dental readiness only applies to candidates that actually have an
        // odontogram (a real dental case). Odontogram-less visits are dental-
        // informational and NEVER counted against readiness (N/A, not 0%).
        $odontogramVisitIds = Odontogram::query()->select('clinic_visit_id');
        $dentalTotal = SatusehatCandidate::query()
            ->where('environment', $env)->where('branch_id', $branchId)
            ->whereIn('clinic_visit_id', $odontogramVisitIds)->count();
        $dentalReady = SatusehatCandidate::query()
            ->where('environment', $env)->where('branch_id', $branchId)
            ->whereIn('clinic_visit_id', $odontogramVisitIds)
            ->where('dental_readiness_status', SatusehatCandidate::DENTAL_READY)->count();

        // Global signals (treatment master data + doctor readiness are lab-wide).
        $treatment = $this->readiness->treatmentMappingSummary();
        $practitioners = $this->readiness->practitionerReadiness();
        $practReady = count(array_filter(
            $practitioners,
            fn ($p) => ! in_array($p['status'], ['inactive', 'data_incomplete'], true),
        ));

        // Location placeholders (active rooms in this branch).
        $roomsTotal = ClinicRoom::query()
            ->where('branch_id', $branchId)
            ->where('status', ClinicRoom::STATUS_ACTIVE)->count();

        $adoptionRate = $this->adoption->metrics(['branch_id' => $branchId])['adoption_rate'] ?? null;

        $hasRehearsal = SatusehatPilotRehearsalRun::query()
            ->where('environment', $env)->where('branch_id', $branchId)
            ->whereIn('result', (array) config('satusehat_pilot.rehearsal.terminal_states', []))
            ->exists();

        $rates = [
            'patient_readiness' => $this->rate($total - $patientAffected, $total),
            'practitioner_readiness' => $this->rate($practReady, count($practitioners)),
            'organization_readiness' => $branchRme ? 100.0 : null,
            'location_readiness' => $roomsTotal > 0 ? 100.0 : null,
            'diagnosis_adoption' => $adoptionRate !== null ? (float) $adoptionRate : null,
            'diagnosis_mapping' => $this->rate($total - $diagnosisMappingAffected, $total),
            'treatment_mapping' => $this->rate(
                (int) $treatment['mapped_active'],
                (int) $treatment['total_active_treatments'],
            ),
            'dental_readiness' => $this->rate($dentalReady, $dentalTotal),
            'local_conformance' => $this->rate($total - $conformanceAffected, $total),
            'operational_rehearsal' => $total === 0 ? null : ($hasRehearsal ? 100.0 : 0.0),
        ];

        $scored = $this->score->calculate($rates, $openHard > 0);

        return [
            'branch_id' => $branchId,
            'branch_rme_enabled' => $branchRme,
            'total_candidates' => $total,
            'open_hard_issues' => $openHard,
            'open_soft_issues' => $openSoft,
            'open_critical_internal_issues' => $openCritical,
            'overdue_critical_issues' => $overdueCritical,
            'source_changed_candidates' => $sourceChanged,
            'has_rehearsal' => $hasRehearsal,
            'rates' => $rates,
            'score' => $scored,
        ];
    }

    /**
     * Recompute and persist a branch's readiness profile (upsert + audit).
     * Pilot status/approval/suspension are NOT changed here.
     */
    public function recalculate(int $branchId, ?User $actor = null): SatusehatBranchPilotProfile
    {
        $profile = $this->profileFor($branchId);
        $snapshot = $this->computeSnapshot($branchId);
        $eligibility = $this->eligibility->evaluate($snapshot, $profile);
        $stage = $this->deriveStage($snapshot, $eligibility, $profile);
        $rates = $snapshot['rates'];

        $profile->fill([
            'internal_readiness_score' => $snapshot['score']['score'],
            'score_version' => $snapshot['score']['version'],
            'open_hard_issues' => $snapshot['open_hard_issues'],
            'open_soft_issues' => $snapshot['open_soft_issues'],
            'diagnosis_adoption_rate' => $rates['diagnosis_adoption'],
            'treatment_mapping_rate' => $rates['treatment_mapping'],
            'dental_readiness_rate' => $rates['dental_readiness'],
            'patient_data_readiness_rate' => $rates['patient_readiness'],
            'practitioner_readiness_rate' => $rates['practitioner_readiness'],
            'location_readiness_rate' => $rates['location_readiness'],
            'local_conformance_rate' => $rates['local_conformance'],
            'readiness_stage' => $stage,
            'last_recalculated_at' => now(),
        ])->save();

        $this->audit->log(
            'satusehat_branch_pilot_profile',
            (int) $profile->id,
            SatusehatAuditLog::EVENT_PILOT_READINESS_RECALCULATED,
            'Kesiapan cabang dihitung ulang',
            [
                'branch_id' => $branchId,
                'score' => $snapshot['score']['score'],
                'stage' => $stage,
                'decision' => $eligibility['decision'],
                'open_hard_issues' => $snapshot['open_hard_issues'],
            ],
            $branchId,
            $actor,
        );

        return $profile->refresh();
    }

    /** Get-or-create the profile row for a branch (used by recalc + config). */
    public function profileFor(int $branchId): SatusehatBranchPilotProfile
    {
        return SatusehatBranchPilotProfile::query()->firstOrCreate(
            ['environment' => (string) config('satusehat.environment'), 'branch_id' => $branchId],
            ['pilot_status' => SatusehatBranchPilotProfile::STATUS_NONE, 'readiness_stage' => SatusehatBranchPilotProfile::STAGE_NOT_STARTED],
        );
    }

    /** Existing profile without creating one. */
    public function existingProfile(int $branchId): ?SatusehatBranchPilotProfile
    {
        return SatusehatBranchPilotProfile::query()
            ->where('environment', (string) config('satusehat.environment'))
            ->where('branch_id', $branchId)
            ->first();
    }

    /**
     * Convenience: eligibility for a branch (fresh snapshot + existing profile).
     *
     * @return array<string, mixed>
     */
    public function eligibilityFor(int $branchId): array
    {
        return $this->eligibility->evaluate($this->computeSnapshot($branchId), $this->existingProfile($branchId));
    }

    /**
     * Branch readiness board — every RME-enabled branch with its cached profile
     * summary (does NOT recalculate; recalculation is explicit).
     *
     * @return list<array<string, mixed>>
     */
    public function board(): array
    {
        $profiles = SatusehatBranchPilotProfile::query()
            ->where('environment', (string) config('satusehat.environment'))
            ->get()->keyBy('branch_id');

        return $this->branches->listRmeEnabled()->map(function (Branch $branch) use ($profiles) {
            $profile = $profiles->get($branch->id);

            return [
                'branch_id' => (int) $branch->id,
                'code' => (string) $branch->code,
                'name' => (string) $branch->name,
                'pilot_status' => $profile?->pilot_status ?? SatusehatBranchPilotProfile::STATUS_NONE,
                'readiness_stage' => $profile?->readiness_stage ?? SatusehatBranchPilotProfile::STAGE_NOT_STARTED,
                'internal_readiness_score' => $profile?->internal_readiness_score,
                'open_hard_issues' => (int) ($profile?->open_hard_issues ?? 0),
                'open_soft_issues' => (int) ($profile?->open_soft_issues ?? 0),
                'diagnosis_adoption_rate' => $profile?->diagnosis_adoption_rate,
                'last_recalculated_at' => $profile?->last_recalculated_at,
                'profile' => $profile,
            ];
        })->values()->all();
    }

    private function deriveStage(array $snapshot, array $eligibility, ?SatusehatBranchPilotProfile $profile): string
    {
        if ($profile !== null && $profile->isSuspended()) {
            return SatusehatBranchPilotProfile::STAGE_SUSPENDED;
        }

        if ((int) $snapshot['total_candidates'] === 0) {
            return SatusehatBranchPilotProfile::STAGE_NOT_STARTED;
        }

        if ($eligibility['internal_ready']) {
            return SatusehatBranchPilotProfile::STAGE_PILOT_READY_INTERNAL;
        }

        if ((int) $snapshot['open_hard_issues'] > 0) {
            return SatusehatBranchPilotProfile::STAGE_REMEDIATION;
        }

        // Only the rehearsal gate remains → ready for UAT/rehearsal.
        $nonRehearsalFailed = array_diff($eligibility['failed_internal_gates'], ['synthetic_rehearsal_passed']);

        return $nonRehearsalFailed === []
            ? SatusehatBranchPilotProfile::STAGE_UAT_READY
            : SatusehatBranchPilotProfile::STAGE_PROFILING;
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return round(100 * max(0, $numerator) / $denominator, 2);
    }
}
