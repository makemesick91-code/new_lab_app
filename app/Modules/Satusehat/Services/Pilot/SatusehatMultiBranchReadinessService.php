<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Modules\Satusehat\Models\SatusehatBranchPilotProfile;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Models\SatusehatWaveBranchMembership;
use Illuminate\Support\Carbon;

/**
 * SATUSEHAT-4D — comparative multi-branch readiness matrix.
 *
 * Read-only. Builds one comparative row per AUTHORIZED branch (the caller passes
 * the branch-scoped id set — never a request branch_id) by reusing the 4C
 * per-branch readiness profiles + eligibility. Wave attribution and overdue
 * issue counts are batched (no N+1). Emits derived numbers + FK ids only — no
 * PII (no NIK, no raw clinical notes). External readiness is surfaced as a
 * SEPARATE blocker; a high internal score never hides a hard blocker or the
 * external credential blocker.
 */
class SatusehatMultiBranchReadinessService
{
    public function __construct(
        private readonly SatusehatBranchReadinessProfileService $profiles,
    ) {}

    /**
     * Comparative matrix for the authorized branch id set.
     *
     * @param  list<int>  $branchIds  authorized, branch-scoped ids (MAIN already excluded)
     * @param  array<string,mixed>  $filters  wave_id|stage|promotion_eligible|search
     * @return list<array<string,mixed>>
     */
    public function matrix(array $branchIds, array $filters = []): array
    {
        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
        if ($branchIds === []) {
            return [];
        }

        $env = (string) config('satusehat.environment');

        // Batched: enrolled wave membership per branch (source of truth).
        $memberships = SatusehatWaveBranchMembership::query()
            ->where('environment', $env)
            ->where('status', SatusehatWaveBranchMembership::STATUS_ENROLLED)
            ->whereIn('branch_id', $branchIds)
            ->with('wave:id,name,status,sequence')
            ->get()
            ->keyBy('branch_id');

        // Batched: overdue open issues per branch.
        $overdue = SatusehatDataQualityIssue::query()
            ->where('environment', $env)
            ->whereIn('branch_id', $branchIds)
            ->whereIn('status', SatusehatDataQualityIssue::OPEN_STATUSES)
            ->whereNotNull('due_at')
            ->where('due_at', '<', Carbon::now())
            ->selectRaw('branch_id, count(*) as c')
            ->groupBy('branch_id')
            ->pluck('c', 'branch_id');

        $rows = [];
        foreach ($this->profiles->board() as $row) {
            $branchId = (int) $row['branch_id'];
            if (! in_array($branchId, $branchIds, true)) {
                continue; // enforce authorized scope
            }

            /** @var SatusehatBranchPilotProfile|null $profile */
            $profile = $row['profile'];
            $membership = $memberships->get($branchId);
            $eligibility = $this->profiles->eligibilityFor($branchId);

            $rows[] = [
                'branch_id' => $branchId,
                'code' => $row['code'],
                'name' => $row['name'],
                'wave_id' => $membership?->rollout_wave_id,
                'wave_name' => $membership?->wave?->name,
                'wave_status' => $membership?->wave?->status,
                'readiness_stage' => $row['readiness_stage'],
                'internal_readiness_score' => $row['internal_readiness_score'],
                'has_hard_blocker' => (int) $row['open_hard_issues'] > 0,
                'open_hard_issues' => (int) $row['open_hard_issues'],
                'open_soft_issues' => (int) $row['open_soft_issues'],
                'overdue_issues' => (int) ($overdue[$branchId] ?? 0),
                'diagnosis_adoption_rate' => $profile?->diagnosis_adoption_rate,
                'treatment_mapping_rate' => $profile?->treatment_mapping_rate,
                'dental_readiness_rate' => $profile?->dental_readiness_rate,
                'patient_readiness_rate' => $profile?->patient_data_readiness_rate,
                'practitioner_readiness_rate' => $profile?->practitioner_readiness_rate,
                'location_readiness_rate' => $profile?->location_readiness_rate,
                'local_conformance_rate' => $profile?->local_conformance_rate,
                'uat_status' => $profile?->uat_status ?? 'not_started',
                'last_rehearsal_result' => $profile?->last_rehearsal_result,
                'last_rehearsal_at' => $profile?->last_rehearsal_at,
                // External blocker is ALWAYS separate and always present in 4D.
                'external_blocker' => 'blocked_external_credential',
                // Promotion eligibility = internal gates only; never external.
                'internal_ready' => (bool) ($eligibility['internal_ready'] ?? false),
                'promotion_eligible' => (bool) ($eligibility['internal_ready'] ?? false)
                    && (int) $row['open_hard_issues'] === 0,
                'failed_internal_gates' => $eligibility['failed_internal_gates'] ?? [],
            ];
        }

        return $this->applyFilters($rows, $filters);
    }

    /**
     * PII-free aggregate summary across the authorized matrix.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    public function summary(array $rows): array
    {
        $count = count($rows);
        $inStage = fn (string $stage) => count(array_filter($rows, fn ($r) => $r['readiness_stage'] === $stage));

        return [
            'branches_total' => $count,
            'branches_profiling' => $inStage(SatusehatBranchPilotProfile::STAGE_PROFILING),
            'branches_in_remediation' => $inStage(SatusehatBranchPilotProfile::STAGE_REMEDIATION),
            'branches_uat_ready' => $inStage(SatusehatBranchPilotProfile::STAGE_UAT_READY),
            'branches_pilot_ready_internal' => $inStage(SatusehatBranchPilotProfile::STAGE_PILOT_READY_INTERNAL),
            'branches_with_hard_blockers' => count(array_filter($rows, fn ($r) => $r['has_hard_blocker'])),
            'branches_with_overdue' => count(array_filter($rows, fn ($r) => $r['overdue_issues'] > 0)),
            'branches_promotion_eligible' => count(array_filter($rows, fn ($r) => $r['promotion_eligible'])),
            // Every branch remains blocked externally by design in 4D.
            'branches_blocked_external_credential' => $count,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,mixed>  $filters
     * @return list<array<string,mixed>>
     */
    private function applyFilters(array $rows, array $filters): array
    {
        if (! empty($filters['wave_id'])) {
            $waveId = (int) $filters['wave_id'];
            $rows = array_filter($rows, fn ($r) => (int) ($r['wave_id'] ?? 0) === $waveId);
        }

        if (! empty($filters['stage'])) {
            $rows = array_filter($rows, fn ($r) => $r['readiness_stage'] === $filters['stage']);
        }

        if (array_key_exists('promotion_eligible', $filters) && $filters['promotion_eligible'] !== null && $filters['promotion_eligible'] !== '') {
            $want = filter_var($filters['promotion_eligible'], FILTER_VALIDATE_BOOLEAN);
            $rows = array_filter($rows, fn ($r) => $r['promotion_eligible'] === $want);
        }

        if (! empty($filters['search'])) {
            $needle = mb_strtolower(trim((string) $filters['search']));
            $rows = array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['name']), $needle)
                || str_contains(mb_strtolower($r['code']), $needle));
        }

        return array_values($rows);
    }
}
