<?php

declare(strict_types=1);

namespace App\Modules\MedicalRecord\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LegacyOdontogram\Policies\LegacyOdontogramRecordPolicy;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPatientHistoryService;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramWorkspaceScope;
use App\Modules\LegacyRme\Policies\LegacyRmeRecordPolicy;
use App\Modules\LegacyRme\Services\LegacyRmePatientHistoryService;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use App\Modules\MedicalRecord\Interfaces\UnifiedMedicalRecordIndexRepositoryInterface;
use App\Modules\MedicalRecord\Support\UnifiedMedicalRecordScope;
use App\Modules\MedicalRecord\Support\UnifiedMedicalRecordSource;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Services\DoctorPatientScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * FEATURE-RME-MEDICAL-RECORDS-UNIFIED-NATIVE-LEGACY-1 — the unified clinical
 * read index behind /rme/medical-records.
 *
 * A patient appears when they hold at least one record the actor may READ:
 * a native medical record, a PUBLISHED legacy RME, or a PUBLISHED legacy
 * odontogram. The envelope for each source is the one that source's canonical
 * reader already uses — this service composes them and invents none:
 *
 *  - native   : RME-enabled branches + {@see DoctorPatientScopeService}
 *               (unchanged from the record list this page used to be);
 *  - legacy   : {@see LegacyRmeRecordPolicy::READ_PERMISSIONS} + {@see LegacyRmeWorkspaceScope};
 *  - odonto   : {@see LegacyOdontogramRecordPolicy::READ_PERMISSIONS} + {@see LegacyOdontogramWorkspaceScope}.
 *
 * Read-only. Nothing here creates a visit, a medical record, or copies a
 * legacy document into a native table.
 */
class UnifiedMedicalRecordIndexService
{
    public function __construct(
        private readonly UnifiedMedicalRecordIndexRepositoryInterface $repository,
        private readonly BranchService $branches,
        private readonly DoctorPatientScopeService $doctorScope,
        private readonly LegacyRmeWorkspaceScope $legacyRmeScope,
        private readonly LegacyOdontogramWorkspaceScope $legacyOdontogramScope,
        private readonly LegacyRmePatientHistoryService $legacyRmeHistory,
        private readonly LegacyOdontogramPatientHistoryService $legacyOdontogramHistory,
    ) {}

    public function scopeFor(User $user): UnifiedMedicalRecordScope
    {
        $legacyRmeReadable = $user->canAny(LegacyRmeRecordPolicy::READ_PERMISSIONS);
        $legacyOdontogramReadable = $user->canAny(LegacyOdontogramRecordPolicy::READ_PERMISSIONS);

        return new UnifiedMedicalRecordScope(
            nativeBranchIds: array_values($this->branches->rmeEnabledIds()),
            legacyRmeReadable: $legacyRmeReadable,
            legacyRmeBranchIds: $legacyRmeReadable ? array_values($this->legacyRmeScope->branchIdsFor($user)) : [],
            legacyRmeIncludesUnscoped: $legacyRmeReadable && $this->legacyRmeScope->includesUnscopedRowsFor($user),
            legacyOdontogramReadable: $legacyOdontogramReadable,
            legacyOdontogramBranchIds: $legacyOdontogramReadable ? array_values($this->legacyOdontogramScope->branchIdsFor($user)) : [],
            legacyOdontogramIncludesUnscoped: $legacyOdontogramReadable && $this->legacyOdontogramScope->includesUnscopedRowsFor($user),
            patientScope: $this->doctorScope->shouldApplyDoctorScope($user)
                ? fn (Builder $query) => $this->doctorScope->applyPatientScopeForUser($user, $query)
                : null,
        );
    }

    /**
     * @param  array{search?: ?string, status?: ?string, visit_date_from?: ?string, visit_date_to?: ?string, source?: ?string}  $filters
     * @return array{patients: LengthAwarePaginator, summary: array{total: int, native: int, legacy: int, native_and_legacy: int}, latestNative: Collection, scope: UnifiedMedicalRecordScope}
     */
    public function index(User $user, array $filters, int $perPage = 15): array
    {
        $scope = $this->scopeFor($user);
        $filters['source'] = UnifiedMedicalRecordSource::normalize($filters['source'] ?? null);

        $patients = $this->repository->paginatePatients($scope, $filters, $perPage);

        $ids = collect($patients->items())->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'patients' => $patients,
            'summary' => $this->repository->summarize($scope, $filters),
            'latestNative' => $this->repository->latestNativeRecordsFor($scope, $ids),
            'scope' => $scope,
        ];
    }

    /**
     * The patient-keyed read-only workspace. Null when the patient does not
     * qualify for THIS actor's index, so the caller answers 404 — existence is
     * never confirmed to someone who could not have listed the patient.
     *
     * The legacy lists come from the canonical history services, so the
     * workspace and the archive viewers can never disagree about who may read.
     *
     * @return array{patient: Patient, nativeRecords: Collection, legacyRme: Collection, legacyOdontogram: Collection}|null
     */
    public function patientWorkspace(User $user, int $patientId): ?array
    {
        $scope = $this->scopeFor($user);
        $patient = $this->repository->findVisiblePatient($scope, $patientId);

        if ($patient === null) {
            return null;
        }

        return [
            'patient' => $patient,
            'nativeRecords' => $this->repository->nativeRecordsForPatient($scope, (int) $patient->id),
            'legacyRme' => $this->legacyRmeHistory->publishedRecordsFor($user, (int) $patient->id),
            'legacyOdontogram' => $this->legacyOdontogramHistory->publishedRecordsFor($user, (int) $patient->id),
        ];
    }
}
