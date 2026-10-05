<?php

declare(strict_types=1);

namespace App\Modules\MedicalRecord\Interfaces;

use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Support\UnifiedMedicalRecordScope;
use App\Modules\Patient\Models\Patient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * FEATURE-RME-MEDICAL-RECORDS-UNIFIED-NATIVE-LEGACY-1 — the read model behind
 * /rme/medical-records. Read-only: nothing here writes a row.
 */
interface UnifiedMedicalRecordIndexRepositoryInterface
{
    /**
     * One row per patient who holds at least one record the actor may read,
     * paginated AFTER the unified eligibility predicate.
     *
     * @param  array{search?: ?string, status?: ?string, visit_date_from?: ?string, visit_date_to?: ?string, source?: string}  $filters
     */
    public function paginatePatients(UnifiedMedicalRecordScope $scope, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Counts over the same filtered set, independent of the source filter.
     *
     * @param  array{search?: ?string, status?: ?string, visit_date_from?: ?string, visit_date_to?: ?string}  $filters
     * @return array{total: int, native: int, legacy: int, native_and_legacy: int}
     */
    public function summarize(UnifiedMedicalRecordScope $scope, array $filters): array;

    /** The patient, only when they qualify for this actor's index; null otherwise. */
    public function findVisiblePatient(UnifiedMedicalRecordScope $scope, int $patientId): ?Patient;

    /**
     * The latest visible native record per patient, for one page of rows.
     *
     * @param  list<int>  $patientIds
     * @return Collection<int, MedicalRecord> keyed by patient id
     */
    public function latestNativeRecordsFor(UnifiedMedicalRecordScope $scope, array $patientIds): Collection;

    /** @return Collection<int, MedicalRecord> */
    public function nativeRecordsForPatient(UnifiedMedicalRecordScope $scope, int $patientId): Collection;
}
