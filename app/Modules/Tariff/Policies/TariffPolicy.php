<?php

namespace App\Modules\Tariff\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Tariff\Models\Tariff;

class TariffPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Tariff $tariff): bool
    {
        return $this->canView($user) && $this->belongsToActiveBranch($tariff->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Tariff $tariff): bool
    {
        return $this->canManage($user) && $this->belongsToActiveBranch($tariff->branch_id);
    }

    public function delete(User $user, Tariff $tariff): bool
    {
        return $this->canManage($user) && $this->belongsToActiveBranch($tariff->branch_id);
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
