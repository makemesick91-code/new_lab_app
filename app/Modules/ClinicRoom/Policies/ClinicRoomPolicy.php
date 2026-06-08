<?php

namespace App\Modules\ClinicRoom\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicRoom\Models\ClinicRoom;

class ClinicRoomPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, ClinicRoom $clinicRoom): bool
    {
        return $this->canView($user) && $this->belongsToActiveBranch($clinicRoom->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ClinicRoom $clinicRoom): bool
    {
        return $this->canManage($user) && $this->belongsToActiveBranch($clinicRoom->branch_id);
    }

    public function delete(User $user, ClinicRoom $clinicRoom): bool
    {
        return $this->canManage($user) && $this->belongsToActiveBranch($clinicRoom->branch_id);
    }

    private function canView(User $user): bool
    {
        return $user->canAny([
            'view_clinic_master_data',
            'manage_clinic_master_data',
        ]);
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage_clinic_master_data');
    }

    private function belongsToActiveBranch(?int $branchId): bool
    {
        $activeBranchId = app(BranchContext::class)->id();

        return $activeBranchId !== null && $branchId === $activeBranchId;
    }
}
