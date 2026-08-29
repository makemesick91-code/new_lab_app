<?php

namespace App\Modules\Patient\Interfaces;

use App\Modules\Patient\Models\Patient;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
     * REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1 — bounded name/Nomor-RM search
     * for the patient selector, restricted to an already-authorized branch set.
     *
     * The caller owns authorization: it passes the branch ids the workspace may
     * read and, for a doctor, the extra RM-scope closure. An EMPTY `$branchIds`
     * means "no authorized branch" and MUST return nothing — never the estate.
     *
     * @param  array<int, int>  $branchIds
     * @param  Closure(Builder<Patient>): Builder<Patient>|null  $additionalScope
     * @return Collection<int, Patient>
     */
    public function searchSelectable(array $branchIds, string $term, int $limit, ?Closure $additionalScope = null): Collection;

    /**
     * The single-row counterpart of {@see searchSelectable()} — the submit-time
     * boundary for a chosen `patient_id`. Same scope rules, same fail-closed
     * behaviour on an empty branch set.
     *
     * @param  array<int, int>  $branchIds
     * @param  Closure(Builder<Patient>): Builder<Patient>|null  $additionalScope
     */
    public function findSelectable(array $branchIds, int $patientId, ?Closure $additionalScope = null): ?Patient;

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
