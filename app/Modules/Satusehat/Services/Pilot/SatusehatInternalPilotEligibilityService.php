<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Models\User;
use App\Modules\MedicalRecord\Models\DiagnosisRolloutSetting;
use App\Modules\MedicalRecord\Services\DiagnosisRolloutService;
use App\Modules\Satusehat\Models\SatusehatBranchPilotProfile;
use App\Modules\Satusehat\Models\SatusehatPilotRehearsalRun;
use App\Modules\Satusehat\Support\SatusehatProductionActivationGuard;

/**
 * SATUSEHAT-4C — internal pilot eligibility gate.
 *
 * Composes an already-computed branch readiness snapshot (from
 * {@see SatusehatBranchReadinessProfileService}) with branch thresholds, the
 * diagnosis rollout mode, rehearsal evidence, and the permanent production
 * guard. Credential-independent and read-only: it NEVER performs an external
 * request and NEVER enables anything.
 *
 * Overall decision:
 *   - suspended                   — the profile is a suspended pilot
 *   - not_eligible                — one or more INTERNAL gates fail
 *   - blocked_external_credential — every internal gate passes, but external
 *                                   credentials are absent (always this sprint);
 *                                   only ever returned AFTER internal gates pass
 *
 * `internal_ready` is the internal-only verdict (eligible_internal). External
 * readiness is never asserted without real SATUSEHAT-2 credentials + proof.
 */
class SatusehatInternalPilotEligibilityService
{
    public function __construct(
        private readonly SatusehatReadinessThresholdService $thresholds,
        private readonly SatusehatProductionActivationGuard $productionGuard,
        private readonly DiagnosisRolloutService $rollout,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot  from SatusehatBranchReadinessProfileService::computeSnapshot()
     * @return array<string, mixed>
     */
    public function evaluate(array $snapshot, ?SatusehatBranchPilotProfile $profile): array
    {
        $branchId = (int) $snapshot['branch_id'];
        $thresholds = $this->thresholds->effectiveThresholds($profile);
        $rates = (array) ($snapshot['rates'] ?? []);
        $suspended = $profile !== null && $profile->isSuspended();

        $gates = [];
        $gates[] = $this->gate('branch_rme_enabled', 'Cabang RME aktif (bukan MAIN)', 'internal',
            (bool) ($snapshot['branch_rme_enabled'] ?? false),
            'Cabang bukan cabang RME aktif atau MAIN.');

        $gates[] = $this->gate('no_critical_internal_issue', 'Tidak ada isu kritis internal terbuka', 'internal',
            (int) ($snapshot['open_critical_internal_issues'] ?? 0) === 0,
            'Masih ada isu berprioritas kritis internal yang terbuka.');

        $gates[] = $this->gate('within_hard_issue_budget', 'Isu keras (hard) dalam batas', 'internal',
            (int) ($snapshot['open_hard_issues'] ?? 0) <= (int) $thresholds['max_open_hard_issues'],
            'Jumlah isu keras terbuka melebihi ambang batas.');

        $gates[] = $this->gate('no_overdue_critical', 'Tidak ada isu kritis lewat SLA', 'internal',
            (int) ($snapshot['overdue_critical_issues'] ?? 0) <= (int) $thresholds['max_overdue_critical_issues'],
            'Ada isu kritis internal yang melewati SLA.');

        $gates[] = $this->gate('diagnosis_adoption', 'Adopsi diagnosis terstruktur memadai', 'internal',
            $this->rateMeets($rates['diagnosis_adoption'] ?? null, (int) $thresholds['diagnosis_adoption_min_percent']),
            'Adopsi diagnosis terstruktur di bawah ambang batas.');

        $gates[] = $this->gate('structured_diagnosis_enabled', 'Alur diagnosis terstruktur aktif', 'internal',
            $this->rollout->modeForBranch($branchId) !== DiagnosisRolloutSetting::MODE_DISABLED,
            'Mode rollout diagnosis cabang masih disabled.');

        $gates[] = $this->gate('treatment_mapping', 'Pemetaan tindakan memadai', 'internal',
            $this->rateMeets($rates['treatment_mapping'] ?? null, (int) $thresholds['treatment_mapping_min_percent']),
            'Cakupan pemetaan tindakan di bawah ambang batas.');

        $gates[] = $this->gate('dental_readiness', 'Kesiapan dental memadai', 'internal',
            $this->rateMeetsOrNull($rates['dental_readiness'] ?? null, (int) $thresholds['dental_readiness_min_percent']),
            'Kesiapan pemetaan/data dental di bawah ambang batas.');

        $gates[] = $this->gate('practitioner_readiness', 'Data dokter siap lookup', 'internal',
            $this->rateMeetsOrNull($rates['practitioner_readiness'] ?? null, (int) $thresholds['patient_readiness_min_percent']),
            'Kelengkapan data dokter di bawah ambang batas.');

        $gates[] = $this->gate('organization_placeholder', 'Placeholder Organization lengkap', 'internal',
            $this->rateMeetsOrNull($rates['organization_readiness'] ?? null, 100),
            'Placeholder Organization cabang belum lengkap.');

        $gates[] = $this->gate('location_placeholder', 'Placeholder Location lengkap', 'internal',
            $this->rateMeetsOrNull($rates['location_readiness'] ?? null, 100),
            'Placeholder Location (ruangan) belum lengkap.');

        $gates[] = $this->gate('patient_readiness', 'Kesiapan data pasien memadai', 'internal',
            $this->rateMeetsOrNull($rates['patient_readiness'] ?? null, (int) $thresholds['patient_readiness_min_percent']),
            'Kesiapan data pasien di bawah ambang batas.');

        $gates[] = $this->gate('local_conformance', 'Konformansi lokal memadai', 'internal',
            $this->rateMeetsOrNull($rates['local_conformance'] ?? null, (int) $thresholds['local_conformance_min_percent']),
            'Tingkat konformansi lokal di bawah ambang batas.');

        $gates[] = $this->gate('no_unreviewed_source_drift', 'Tidak ada drift sumber belum ditinjau', 'internal',
            (int) ($snapshot['source_changed_candidates'] ?? 0) <= (int) $thresholds['max_source_changed_candidates'],
            'Ada kandidat dengan perubahan sumber yang belum ditinjau ulang.');

        $gates[] = $this->gate('synthetic_rehearsal_passed', 'Rehearsal sintetis lulus', 'internal',
            $this->hasPassingRehearsal($branchId),
            'Belum ada rehearsal pilot sintetis yang lulus untuk cabang ini.');

        $gates[] = $this->gate('operator_roles_assigned', 'Operator remediasi tersedia', 'internal',
            $this->hasOperatorAssigned(),
            'Belum ada operator dengan izin remediasi kesiapan cabang.');

        $gates[] = $this->gate('runbook_approved', 'Runbook operasional tersedia', 'internal',
            is_file(base_path('docs/runbooks/satusehat-4c-branch-readiness-internal-pilot-runbook.md')),
            'Runbook operasional SATUSEHAT-4C belum tersedia.');

        // External-posture gates. These MUST remain blocked/disabled this sprint;
        // they pass when SATUSEHAT stays credential-independent (production
        // blocked, SATUSEHAT-2 WATCH, external send off).
        $gates[] = $this->gate('production_blocked', 'Produksi tetap terblokir', 'external_posture',
            ! $this->productionGuard->isProductionAllowed(),
            'Guard produksi TIDAK terblokir — anomali, produksi harus tetap terblokir.');

        $gates[] = $this->gate('satusehat2_watch', 'SATUSEHAT-2 tetap WATCH', 'external_posture',
            config('satusehat.sandbox_verified') !== true,
            'SATUSEHAT-2 tidak lagi WATCH — di luar cakupan SATUSEHAT-4C.');

        $gates[] = $this->gate('external_send_disabled', 'Pengiriman eksternal nonaktif', 'external_posture',
            config('satusehat.enabled') !== true && config('satusehat.send_enabled') !== true,
            'Pengiriman eksternal aktif — harus nonaktif pada SATUSEHAT-4C.');

        $internalGates = array_values(array_filter($gates, fn ($g) => $g['category'] === 'internal'));
        $failedInternal = array_values(array_filter($internalGates, fn ($g) => ! $g['passed']));
        $externalPostureOk = ! in_array(false, array_map(
            fn ($g) => $g['passed'],
            array_filter($gates, fn ($g) => $g['category'] === 'external_posture'),
        ), true);

        $internalReady = $failedInternal === [] && $externalPostureOk;

        $decision = match (true) {
            $suspended => 'suspended',
            ! $internalReady => 'not_eligible',
            // Every internal gate passed; the only remaining blocker is external
            // credentials (always absent this sprint).
            default => 'blocked_external_credential',
        };

        return [
            'branch_id' => $branchId,
            'decision' => $decision,
            'internal_ready' => $internalReady,
            'external_blocked' => true,
            'suspended' => $suspended,
            'gates' => $gates,
            'failed_internal_gates' => array_map(fn ($g) => $g['key'], $failedInternal),
            'thresholds' => $thresholds,
        ];
    }

    private function hasPassingRehearsal(int $branchId): bool
    {
        return SatusehatPilotRehearsalRun::query()
            ->where('environment', (string) config('satusehat.environment'))
            ->where('branch_id', $branchId)
            ->whereIn('result', (array) config('satusehat_pilot.rehearsal.terminal_states', []))
            ->exists();
    }

    private function hasOperatorAssigned(): bool
    {
        // At least one non-trashed user can effectively perform branch
        // remediation — via a role permission, a direct permission, or the
        // Super Admin role (Gate::before bypass). Bounded existence query.
        $permissions = ['manage_satusehat_branch_remediation', 'manage_satusehat_remediation'];

        return User::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($permissions) {
                $q->whereHas('roles.permissions', fn ($p) => $p->whereIn('name', $permissions))
                    ->orWhereHas('permissions', fn ($p) => $p->whereIn('name', $permissions))
                    ->orWhereHas('roles', fn ($r) => $r->where('name', 'Super Admin'));
            })
            ->exists();
    }

    private function rateMeets(?float $rate, int $minPercent): bool
    {
        return $rate !== null && $rate + 0.0001 >= $minPercent;
    }

    /** A null (N/A) rate is treated as satisfied — there is nothing to remediate. */
    private function rateMeetsOrNull(?float $rate, int $minPercent): bool
    {
        return $rate === null || $rate + 0.0001 >= $minPercent;
    }

    /**
     * @return array{key:string, label:string, category:string, passed:bool, detail:string}
     */
    private function gate(string $key, string $label, string $category, bool $passed, string $failDetail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'category' => $category,
            'passed' => $passed,
            'detail' => $passed ? 'OK' : $failDetail,
        ];
    }
}
