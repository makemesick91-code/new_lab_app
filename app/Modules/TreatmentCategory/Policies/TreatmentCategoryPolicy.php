<?php

namespace App\Modules\TreatmentCategory\Policies;

use App\Models\User;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;

class TreatmentCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, TreatmentCategory $treatmentCategory): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, TreatmentCategory $treatmentCategory): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, TreatmentCategory $treatmentCategory): bool
    {
        return $this->canManage($user);
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
}
