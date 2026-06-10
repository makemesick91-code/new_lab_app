<?php

namespace App\Modules\MedicalRecord\Interfaces;

use App\Modules\MedicalRecord\Models\MedicalRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MedicalRecordRepositoryInterface
{
    /** @param array<string, mixed> $filters */
    public function paginateForBranch(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function findByVisitId(int $clinicVisitId): ?MedicalRecord;

    public function countByBranchStatus(int $branchId, string $status): int;

    public function countFinalizedTodayByBranch(int $branchId, string $date): int;

    /** @param array<string, mixed> $data */
    public function create(array $data): MedicalRecord;

    /** @param array<string, mixed> $data */
    public function update(MedicalRecord $medicalRecord, array $data): MedicalRecord;
}
