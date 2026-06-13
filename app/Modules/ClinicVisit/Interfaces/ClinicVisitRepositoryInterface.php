<?php

namespace App\Modules\ClinicVisit\Interfaces;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ClinicVisitRepositoryInterface
{
    /** @param array{search?: string|null, status?: string|null, visit_date?: string|null} $filters */
    public function paginate(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Paginate visits scoped to a set of branches (the active RME-enabled
     * "Cabang RME" set), rather than a single BranchContext fallback branch.
     *
     * @param  array<int, int>  $branchIds
     * @param  array{search?: string|null, status?: string|null, visit_date?: string|null}  $filters
     */
    public function paginateForBranches(array $branchIds, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function findInBranch(int $branchId, int $id): ?ClinicVisit;

    public function nextQueueNumber(int $branchId, Carbon $visitDate): int;

    public function countTodayByBranch(int $branchId, string $date): int;

    public function countByBranchStatus(int $branchId, string $status): int;

    /** @param array<int, int> $branchIds */
    public function countTodayByBranches(array $branchIds, string $date): int;

    /** @param array<int, int> $branchIds */
    public function countByBranchesStatus(array $branchIds, string $status): int;

    /** @param array<string, mixed> $data */
    public function create(array $data): ClinicVisit;

    /** @param array<string, mixed> $data */
    public function update(ClinicVisit $visit, array $data): ClinicVisit;
}
