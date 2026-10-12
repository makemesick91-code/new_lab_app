<?php

namespace App\Modules\ClinicOversight\Interfaces;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use Illuminate\Support\Collection;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — branch-scoped, read-only
 * lookups of native clinical records for the oversight module.
 *
 * Every lookup takes the SERVER-resolved branch set first and matches nothing
 * for an empty set, so an out-of-scope id resolves to null (the caller answers
 * 404, never a 403 that would confirm the record exists).
 */
interface OversightClinicalRecordRepositoryInterface
{
    /**
     * @param  list<int>  $branchIds
     */
    public function findVisitInBranches(array $branchIds, int $visitId): ?ClinicVisit;

    /**
     * @param  list<int>  $branchIds
     */
    public function findVisitByNumberInBranches(array $branchIds, string $visitNumber): ?ClinicVisit;

    /**
     * The patient's visits in the branches, newest first, with the clinical
     * relations the history page renders. Bounded.
     *
     * @param  list<int>  $branchIds
     * @return Collection<int, ClinicVisit>
     */
    public function visitsForPatient(array $branchIds, int $patientId, int $limit = 200): Collection;

    /**
     * The patient's RM sheets (medical records) in the branches, in visit
     * order, with their handwriting so the whole handwriting book renders.
     *
     * @param  list<int>  $branchIds
     * @return Collection<int, MedicalRecord>
     */
    public function sheetsForPatient(array $branchIds, int $patientId): Collection;

    /**
     * Ids of the patients whose medical-record number equals one of the given
     * spellings EXACTLY (aliases are the caller's job). Not branch-scoped on
     * its own: the caller re-checks visibility through the unified index.
     *
     * @param  list<string>  $numbers
     * @return list<int>
     */
    public function patientIdsByMedicalRecordNumbers(array $numbers): array;
}
