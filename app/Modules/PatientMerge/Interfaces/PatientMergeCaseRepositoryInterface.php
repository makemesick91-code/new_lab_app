<?php

namespace App\Modules\PatientMerge\Interfaces;

use App\Modules\PatientMerge\Models\PatientMergeCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PatientMergeCaseRepositoryInterface
{
    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): PatientMergeCase;

    public function findByUuid(string $uuid): ?PatientMergeCase;

    /** Re-read a case under a row lock. Callers must be inside a transaction. */
    public function lockForUpdate(int $id): ?PatientMergeCase;

    /**
     * Write workflow columns. forceFill on purpose: they are not fillable,
     * so no request payload can reach them.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function write(PatientMergeCase $case, array $attributes): PatientMergeCase;

    /** The open case (if any) that already claims one of these patients. */
    public function openCaseInvolving(array $patientIds, ?int $exceptCaseId = null): ?PatientMergeCase;

    /**
     * Cases whose BOTH patients sit inside the branch scope (a legacy patient
     * without a branch is in every scope).
     *
     * @param  array<int, int>  $branchIds
     * @param  array<int, string>  $statuses
     * @param  array<string, mixed>  $filters
     */
    public function paginateScoped(array $branchIds, array $statuses, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * @param  array<int, int>  $branchIds
     * @return array<string, int>
     */
    public function statusCounts(array $branchIds): array;

    /**
     * Completed merges per month, oldest first.
     *
     * @param  array<int, int>  $branchIds
     * @return array<string, int> "Y-m" => count
     */
    public function completedPerMonth(array $branchIds, int $months): array;

    public function countHighRiskOpen(array $branchIds): int;

    public function nextCaseNumber(int $year): string;

    /** @param  array<int, array<string, mixed>>  $rows */
    public function replaceFieldResolutions(PatientMergeCase $case, array $rows): void;

    /**
     * Open cases keyed "lowerId-higherId" => uuid.
     *
     * @return array<string, string>
     */
    public function openPairKeys(): array;
}
