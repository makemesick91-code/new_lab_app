<?php

namespace App\Modules\Odontogram\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;

class OdontogramPolicy
{
    public function view(User $user, Odontogram $odontogram): bool
    {
        return $this->canView($user) && $this->belongsToActiveBranch($odontogram->branch_id);
    }

    public function print(User $user, Odontogram $odontogram): bool
    {
        return $this->canView($user) && $this->belongsToActiveBranch($odontogram->branch_id);
    }

    public function create(User $user, ClinicVisit $clinicVisit): bool
    {
        return $this->canView($user) && $this->belongsToActiveBranch($clinicVisit->branch_id);
    }

    public function update(User $user, Odontogram $odontogram): bool
    {
        return $this->canManage($user)
            && $this->belongsToActiveBranch($odontogram->branch_id)
            && ! $odontogram->isFinalized();
    }

    public function finalize(User $user, Odontogram $odontogram): bool
    {
        return $this->canManage($user) && $this->belongsToActiveBranch($odontogram->branch_id);
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
     * RME odontograms are scoped to the operational "Cabang RME" set (active
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
