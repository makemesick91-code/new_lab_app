<?php

namespace App\Modules\Odontogram\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
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

    private function belongsToActiveBranch(?int $branchId): bool
    {
        $activeBranchId = app(BranchContext::class)->id();

        return $activeBranchId !== null && $branchId === $activeBranchId;
    }
}
