<?php

namespace App\Modules\Prescription\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Prescription\Models\RmePrescription;

class RmePrescriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, RmePrescription $prescription): bool
    {
        return $this->canView($user) && $this->belongsToActiveBranch($prescription->branch_id);
    }

    public function print(User $user, RmePrescription $prescription): bool
    {
        return $this->canView($user) && $this->belongsToActiveBranch($prescription->branch_id);
    }

    public function viewForVisit(User $user, ClinicVisit $clinicVisit): bool
    {
        return $this->canView($user) && $this->belongsToActiveBranch($clinicVisit->branch_id);
    }

    public function create(User $user, ClinicVisit $clinicVisit): bool
    {
        return $this->canManage($user) && $this->belongsToActiveBranch($clinicVisit->branch_id);
    }

    public function update(User $user, RmePrescription $prescription): bool
    {
        return $this->canManage($user) && $this->belongsToActiveBranch($prescription->branch_id);
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
