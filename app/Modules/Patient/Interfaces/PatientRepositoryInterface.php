<?php

namespace App\Modules\Patient\Interfaces;

use App\Modules\Patient\Models\Patient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface PatientRepositoryInterface
{
    /**
     * @param  array{search?: string|null, clinic_id?: int|null, doctor_id?: int|null}  $filters
     */
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function listAll(): Collection;

    public function findById(int $id): ?Patient;

    /**
     * Read-only preview of "legacy" patients that have no Cabang RME assigned yet
     * (branch_id is null). Sprint 23 Phase 23.10 does NOT backfill these — this is
     * a non-mutating reporting helper for a future controlled migration phase.
     *
     * @return Collection<int, Patient>
     */
    public function legacyWithoutBranch(): Collection;

    /**
     * Read-only patient set for the Sprint 61.0 data-completeness audit, scoped
     * by branch and active status only (completeness/search filters are applied
     * downstream in PHP so duplicate-risk detection sees the full scope). Eager
     * loads the branch for labelling.
     *
     * @param  array{branch_id?: int|null, is_active?: bool|null}  $filters
     * @return Collection<int, Patient>
     */
    public function forAudit(array $filters = []): Collection;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Patient;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Patient $patient, array $data): Patient;

    public function delete(Patient $patient): bool;

    public function setActiveStatus(Patient $patient, bool $isActive): Patient;
}
