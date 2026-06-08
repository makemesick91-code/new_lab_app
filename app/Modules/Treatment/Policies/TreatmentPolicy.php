<?php

namespace App\Modules\Treatment\Policies;

use App\Models\User;
use App\Modules\Treatment\Models\Treatment;

class TreatmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Treatment $treatment): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Treatment $treatment): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Treatment $treatment): bool
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
