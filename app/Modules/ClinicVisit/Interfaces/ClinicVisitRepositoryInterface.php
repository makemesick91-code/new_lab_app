<?php

namespace App\Modules\ClinicVisit\Interfaces;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ClinicVisitRepositoryInterface
{
    /** @param array{search?: string|null, status?: string|null, visit_date?: string|null} $filters */
    public function paginate(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function findInBranch(int $branchId, int $id): ?ClinicVisit;

    public function nextQueueNumber(int $branchId, Carbon $visitDate): int;

    /** @param array<string, mixed> $data */
    public function create(array $data): ClinicVisit;

    /** @param array<string, mixed> $data */
    public function update(ClinicVisit $visit, array $data): ClinicVisit;
}
