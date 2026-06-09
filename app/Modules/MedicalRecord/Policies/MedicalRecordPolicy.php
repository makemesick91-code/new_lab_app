<?php

namespace App\Modules\MedicalRecord\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;

class MedicalRecordPolicy
{
    public function view(User $user, MedicalRecord $medicalRecord): bool
    {
        return $this->canView($user) && $this->belongsToActiveBranch($medicalRecord->branch_id);
    }

    public function create(User $user, ClinicVisit $clinicVisit): bool
    {
        return $this->canManage($user) && $this->belongsToActiveBranch($clinicVisit->branch_id);
    }

    public function update(User $user, MedicalRecord $medicalRecord): bool
    {
        return $this->canManage($user) && $this->belongsToActiveBranch($medicalRecord->branch_id);
    }

    public function finalize(User $user, MedicalRecord $medicalRecord): bool
    {
        return $this->canManage($user) && $this->belongsToActiveBranch($medicalRecord->branch_id);
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
        $activeBranchId = app(BranchContext::class)->id();

        return $activeBranchId !== null && $branchId === $activeBranchId;
    }
}
