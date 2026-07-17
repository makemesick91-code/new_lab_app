<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatBranchPilotProfile;
use App\Modules\Satusehat\Models\SatusehatBranchScoreSnapshot;
use App\Modules\Satusehat\Models\SatusehatWaveBranchMembership;
use Illuminate\Support\Collection;

/**
 * SATUSEHAT-4D — append-only readiness score history.
 *
 * Captures a reproducible snapshot of a branch profile's score + component
 * breakdown so score-weight/threshold changes are auditable over time and
 * historical scores stay reproducible. Derived numbers + FK only, no PII.
 */
class SatusehatScoreSnapshotService
{
    private function env(): string
    {
        return (string) config('satusehat.environment');
    }

    public function capture(SatusehatBranchPilotProfile $profile, ?User $actor = null): SatusehatBranchScoreSnapshot
    {
        $waveId = SatusehatWaveBranchMembership::query()
            ->where('environment', $this->env())
            ->where('branch_id', (int) $profile->branch_id)
            ->where('status', SatusehatWaveBranchMembership::STATUS_ENROLLED)
            ->value('rollout_wave_id');

        return SatusehatBranchScoreSnapshot::create([
            'environment' => $this->env(),
            'branch_id' => (int) $profile->branch_id,
            'rollout_wave_id' => $waveId,
            'score' => $profile->internal_readiness_score,
            'score_version' => $profile->score_version ?? (int) config('satusehat_pilot.score.version', 1),
            'threshold_version' => $profile->threshold_version,
            'open_hard_issues' => (int) $profile->open_hard_issues,
            'open_soft_issues' => (int) $profile->open_soft_issues,
            'has_hard_blocker' => (int) $profile->open_hard_issues > 0,
            'component_breakdown' => [
                'diagnosis_adoption_rate' => $profile->diagnosis_adoption_rate,
                'treatment_mapping_rate' => $profile->treatment_mapping_rate,
                'dental_readiness_rate' => $profile->dental_readiness_rate,
                'patient_data_readiness_rate' => $profile->patient_data_readiness_rate,
                'practitioner_readiness_rate' => $profile->practitioner_readiness_rate,
                'location_readiness_rate' => $profile->location_readiness_rate,
                'local_conformance_rate' => $profile->local_conformance_rate,
            ],
            'readiness_stage' => $profile->readiness_stage,
            'captured_by' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    /** @return Collection<int, SatusehatBranchScoreSnapshot> */
    public function history(int $branchId, int $limit = 30): Collection
    {
        return SatusehatBranchScoreSnapshot::query()
            ->where('environment', $this->env())
            ->where('branch_id', $branchId)
            ->orderByDesc('created_at')
            ->limit(max(1, min($limit, 200)))
            ->get();
    }
}
