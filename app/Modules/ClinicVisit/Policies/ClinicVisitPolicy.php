<?php

namespace App\Modules\ClinicVisit\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;

class ClinicVisitPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, ClinicVisit $visit): bool
    {
        return $this->canView($user) && $this->belongsToActiveRmeBranch($visit->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ClinicVisit $visit): bool
    {
        return $this->canManage($user) && $this->belongsToActiveRmeBranch($visit->branch_id);
    }

    public function transition(User $user, ClinicVisit $visit): bool
    {
        return $this->canManage($user) && $this->belongsToActiveRmeBranch($visit->branch_id);
    }

    public function print(User $user, ClinicVisit $visit): bool
    {
        return $this->canView($user) && $this->belongsToActiveRmeBranch($visit->branch_id);
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
     * RME visits are scoped to the operational "Cabang RME" set, not a single
     * BranchContext fallback branch. A visit is viewable when its branch is one
     * of the active RME-enabled branches (MAIN is excluded — not RME-enabled).
     * This intentionally does NOT rely on users.branch_id (Sprint 23 Phase 23.9.3).
     */
    private function belongsToActiveRmeBranch(?int $branchId): bool
    {
        if ($branchId === null) {
            return false;
        }

        return in_array($branchId, app(BranchService::class)->rmeEnabledIds(), true);
    }
}
