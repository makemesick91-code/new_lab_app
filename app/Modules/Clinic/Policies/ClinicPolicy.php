<?php

namespace App\Modules\Clinic\Policies;

use App\Models\User;
use App\Modules\Clinic\Models\Clinic;

class ClinicPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage clinics');
    }

    public function view(User $user, Clinic $clinic): bool
    {
        return $user->can('manage clinics');
    }

    public function create(User $user): bool
    {
        return $user->can('manage clinics');
    }

    public function update(User $user, Clinic $clinic): bool
    {
        return $user->can('manage clinics');
    }

    public function delete(User $user, Clinic $clinic): bool
    {
        return $user->can('manage clinics');
    }
}
