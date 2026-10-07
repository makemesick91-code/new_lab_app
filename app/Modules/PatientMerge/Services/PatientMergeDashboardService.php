<?php

namespace App\Modules\PatientMerge\Services;

use App\Models\User;
use App\Modules\PatientMerge\Interfaces\PatientMergeCaseRepositoryInterface;
use App\Modules\PatientMerge\Interfaces\PatientRmAliasRepositoryInterface;
use App\Modules\PatientMerge\Support\PatientMergeStatus;

/**
 * Read-only numbers for the Duplikasi Pasien dashboard, inside the viewer's
 * branch scope. Counts only — no identity is rendered here.
 */
class PatientMergeDashboardService
{
    public const TREND_MONTHS = 6;

    public function __construct(
        private readonly PatientMergeCaseRepositoryInterface $cases,
        private readonly PatientRmAliasRepositoryInterface $aliases,
        private readonly PatientDuplicateDetectionService $detection,
        private readonly PatientMergeScope $scope,
    ) {}

    /** @return array<string, mixed> */
    public function overview(?User $user): array
    {
        $branchIds = $this->scope->branchIdsFor($user);
        $statusCounts = $this->cases->statusCounts($branchIds);

        return [
            'candidates' => $this->detection->countFor($user),
            'drafts' => $statusCounts[PatientMergeStatus::DRAFT],
            'awaiting_review' => $statusCounts[PatientMergeStatus::PENDING_REVIEW],
            'completed' => $statusCounts[PatientMergeStatus::COMPLETED],
            'rejected' => $statusCounts[PatientMergeStatus::REJECTED],
            'reversal_review' => $statusCounts[PatientMergeStatus::REVERSAL_REQUIRED],
            'high_risk_open' => $this->cases->countHighRiskOpen($branchIds),
            'active_aliases' => $this->aliases->paginateScoped($branchIds, ['state' => 'active'], 1)->total(),
            'status_counts' => $statusCounts,
            'monthly_completed' => $this->cases->completedPerMonth($branchIds, self::TREND_MONTHS),
        ];
    }
}
