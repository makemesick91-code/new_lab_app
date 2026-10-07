<?php

namespace App\Modules\PatientMerge\Interfaces;

use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Collection;

/**
 * Bounded reads behind duplicate detection. Candidate pairs are formed only
 * inside "blocks" of patients sharing an indexed key (birth date, phone), so
 * detection never scans or fuzzy-matches the whole patient table.
 */
interface PatientDuplicateCandidateRepositoryInterface
{
    /**
     * Values of an indexed column shared by more than one active, unmerged
     * patient inside the branch scope.
     *
     * @param  array<int, int>  $branchIds
     * @return array<int, string>
     */
    public function sharedValues(array $branchIds, string $column, int $limit): array;

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<int, string>  $values
     * @return Collection<int, Patient>
     */
    public function patientsWithValues(array $branchIds, string $column, array $values, int $limit): Collection;

    /**
     * Active, unmerged patients born on a date, or holding one of these phone
     * numbers (either phone column). Used by the registration duplicate check.
     *
     * @param  array<int, int>  $branchIds
     * @param  array<int, string>  $phones
     * @return Collection<int, Patient>
     */
    public function registrationCandidates(array $branchIds, ?string $birthDate, array $phones, int $limit): Collection;
}
