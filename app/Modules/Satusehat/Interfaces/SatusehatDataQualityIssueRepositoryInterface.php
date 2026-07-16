<?php

namespace App\Modules\Satusehat\Interfaces;

use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface SatusehatDataQualityIssueRepositoryInterface
{
    /**
     * Branch-scoped, filterable, paginated issue listing. Empty $branchIds
     * denies everything (fail-closed IDOR boundary).
     *
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $branchIds
     */
    public function paginate(array $filters, array $branchIds, int $perPage = 20): LengthAwarePaginator;

    /**
     * @param  list<int>  $branchIds
     */
    public function findInBranches(int $id, array $branchIds): ?SatusehatDataQualityIssue;

    /**
     * Aggregated counts (by status / severity / rule / owner role) for the
     * given branch set — single GROUP BY queries, never row hydration.
     *
     * @param  list<int>  $branchIds
     * @return array<string, array<string, int>>
     */
    public function aggregates(array $branchIds): array;

    /**
     * Per-candidate open-issue aggregates for a bounded id set (one query).
     *
     * @param  list<int>  $candidateIds
     * @return array<int, array{open: int, awaiting_clinical_review: int, invalid_demographics: int, diagnosis_mapping_gap: int}>
     */
    public function openAggregatesForCandidates(array $candidateIds): array;
}
