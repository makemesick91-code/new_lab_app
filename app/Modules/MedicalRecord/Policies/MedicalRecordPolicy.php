<?php

namespace App\Modules\MedicalRecord\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RME\Services\DoctorPatientScopeService;
use Illuminate\Auth\Access\Response;

class MedicalRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, MedicalRecord $medicalRecord): Response|bool
    {
        if (! $this->canView($user) || ! $this->belongsToActiveBranch($medicalRecord->branch_id)) {
            return false;
        }

        return $this->authorizePatientForRecord($user, $medicalRecord);
    }

    public function create(User $user, ClinicVisit $clinicVisit): Response|bool
    {
        if (! $this->canManage($user) || ! $this->belongsToActiveBranch($clinicVisit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $clinicVisit);
    }

    public function update(User $user, MedicalRecord $medicalRecord): Response|bool
    {
        if (! $this->canManage($user) || ! $this->belongsToActiveBranch($medicalRecord->branch_id)) {
            return false;
        }

        return $this->authorizePatientForRecord($user, $medicalRecord);
    }

    public function finalize(User $user, MedicalRecord $medicalRecord): Response|bool
    {
        if (! $this->canManage($user) || ! $this->belongsToActiveBranch($medicalRecord->branch_id)) {
            return false;
        }

        return $this->authorizePatientForRecord($user, $medicalRecord);
    }

    private function authorizePatientForRecord(User $user, MedicalRecord $medicalRecord): Response|bool
    {
        $patient = $medicalRecord->patient;

        if ($patient !== null) {
            return app(DoctorPatientScopeService::class)->authorizePatientAccess($user, $patient);
        }

        return false;
    }

    private function canView(User $user): bool
    {
        return $user->canAny(['view_clinic_visits', 'manage_clinic_visits']);
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage_clinic_visits');
    }

    private function belongsToActiveBranch(?int $branchId): bool
    {
        if ($branchId === null) {
            return false;
        }

        return in_array($branchId, app(BranchService::class)->rmeEnabledIds(), true);
    }
}
