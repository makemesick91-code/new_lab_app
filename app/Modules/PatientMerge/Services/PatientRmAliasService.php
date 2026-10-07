<?php

namespace App\Modules\PatientMerge\Services;

use App\Models\User;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use App\Modules\PatientMerge\Interfaces\PatientRmAliasRepositoryInterface;
use App\Modules\PatientMerge\Models\PatientRmAlias;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Old Nomor RM → the patient it belongs to today.
 *
 * Every alternate spelling of a Nomor RM (revised branch codes) is tried, the
 * same way the patient search treats a typed card number.
 */
class PatientRmAliasService
{
    public function __construct(
        private readonly PatientRmAliasRepositoryInterface $aliases,
        private readonly PatientMedicalRecordNumberService $numbers,
        private readonly PatientMergeScope $scope,
    ) {}

    public function findAlias(?string $medicalRecordNumber): ?PatientRmAlias
    {
        $medicalRecordNumber = trim((string) $medicalRecordNumber);

        if ($medicalRecordNumber === '') {
            return null;
        }

        return $this->aliases->findActiveByNumbers($this->numbers->equivalentNumbers($medicalRecordNumber));
    }

    /** The canonical patient an old number resolves to, or null. */
    public function resolveCanonical(?string $medicalRecordNumber): ?Patient
    {
        $alias = $this->findAlias($medicalRecordNumber);

        return $alias?->canonicalPatient()->first();
    }

    /** @param  array<string, mixed>  $filters */
    public function paginate(?User $user, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->aliases->paginateScoped($this->scope->branchIdsFor($user), $filters, $perPage);
    }
}
