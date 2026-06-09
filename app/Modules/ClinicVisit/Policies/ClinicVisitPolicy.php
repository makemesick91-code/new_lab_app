<?php

namespace App\Modules\ClinicVisit\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicVisit\Models\ClinicVisit;

class ClinicVisitPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, ClinicVisit $visit): bool
    {
        return $this->canView($user) && $this->belongsToActiveBranch($visit->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ClinicVisit $visit): bool
    {
        return $this->canManage($user) && $this->belongsToActiveBranch($visit->branch_id);
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
