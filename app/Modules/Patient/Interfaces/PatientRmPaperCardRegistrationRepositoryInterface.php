<?php

declare(strict_types=1);

namespace App\Modules\Patient\Interfaces;

use App\Modules\Patient\Models\PatientRmPaperCardRegistration;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §4.3, PR-3b) —
 * persistence of the paper-card reason. Append and read only: there is
 * deliberately no update or delete method (the rows are immutable for the life
 * of the patient record; a future change is a new row with its own audit).
 * Every read takes the caller's server-resolved branch scope.
 */
interface PatientRmPaperCardRegistrationRepositoryInterface
{
    /**
     * Append one registration inside the caller's open transaction (the claim's).
     *
     * @param  array{patient_id: int, branch_id: int, year: int, reason_code: string, reason_note: ?string, actor_user_id: int}  $attributes
     */
    public function record(array $attributes): PatientRmPaperCardRegistration;

    /**
     * A patient's registrations inside the given branches, oldest first, with
     * their actor and branch.
     *
     * @param  array<int, int>  $branchIds
     * @return Collection<int, PatientRmPaperCardRegistration>
     */
    public function forPatient(int $patientId, array $branchIds): Collection;

    /**
     * Every registration inside the given branches, newest first, with its
     * patient, actor and branch.
     *
     * @param  array<int, int>  $branchIds
     */
    public function paginateForBranches(array $branchIds, int $perPage): LengthAwarePaginator;
}
