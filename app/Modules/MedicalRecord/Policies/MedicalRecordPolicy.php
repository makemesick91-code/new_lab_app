<?php

namespace App\Modules\MedicalRecord\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;

class MedicalRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

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

    /**
     * RME medical records are scoped to the operational "Cabang RME" set (active
     * RME-enabled branches), mirroring ClinicVisitPolicy. A single BranchContext
     * fallback (MAIN, not RME-enabled) would otherwise forbid every RME-branch
     * visit for doctors in the pilot. Sprint 23 Phase 23.10.
     */
    private function belongsToActiveBranch(?int $branchId): bool
    {
        if ($branchId === null) {
            return false;
        }

        return in_array($branchId, app(BranchService::class)->rmeEnabledIds(), true);
    }
}
