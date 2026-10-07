<?php

namespace App\Modules\PatientMerge\Services;

use App\Models\User;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Builder;

/**
 * Manual patient selection for a merge ("Pilih Pasien Manual").
 *
 * Reuses the canonical bounded patient search (minimum query length, result
 * ceiling, escaped LIKE) inside the duplicate-resolution branch scope, and
 * never offers a merged patient: typing a merged patient's old Nomor RM
 * returns the patient it was merged INTO, through its alias.
 */
class PatientMergeSelectionService
{
    public const MIN_QUERY_LENGTH = 2;

    public const RESULT_LIMIT = 15;

    public function __construct(
        private readonly PatientRepositoryInterface $patients,
        private readonly PatientMergeScope $scope,
        private readonly PatientRmAliasService $aliases,
    ) {}

    /**
     * @return array<int, array{id: int, name: string, medical_record_number: ?string, branch_label: string, via_alias: ?string}>
     */
    public function search(?User $user, ?string $term): array
    {
        $term = trim((string) $term);

        if (mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $branchIds = $this->scope->branchIdsFor($user);
        $results = $this->patients
            ->searchSelectable($branchIds, $term, self::RESULT_LIMIT, fn (Builder $q): Builder => $q->whereNull('merged_into_patient_id'))
            ->map(fn (Patient $patient): array => $this->option($patient, null))
            ->keyBy('id');

        $alias = $this->aliases->findAlias($term);

        if ($alias !== null && ! $results->has($alias->canonical_patient_id)) {
            $canonical = $this->patients->findSelectable(
                $branchIds,
                (int) $alias->canonical_patient_id,
                fn (Builder $q): Builder => $q->whereNull('merged_into_patient_id'),
            );

            if ($canonical !== null) {
                $results->prepend($this->option($canonical, $alias->alias_medical_record_number), $canonical->id);
            }
        }

        return $results->values()->all();
    }

    /** A patient the user may choose, fully loaded, or null. */
    public function findSelectable(?User $user, ?int $patientId): ?Patient
    {
        if ($patientId === null || $patientId <= 0) {
            return null;
        }

        $patient = $this->patients->findById($patientId)?->loadMissing('branch');

        if ($patient === null || $patient->isMerged() || ! $this->scope->covers($user, $patient)) {
            return null;
        }

        return $patient;
    }

    /** @return array{id: int, name: string, medical_record_number: ?string, branch_label: string, via_alias: ?string} */
    private function option(Patient $patient, ?string $viaAlias): array
    {
        return [
            'id' => $patient->id,
            'name' => $patient->name,
            'medical_record_number' => $patient->medical_record_number,
            'branch_label' => $patient->branchLabel(),
            'via_alias' => $viaAlias,
        ];
    }
}
