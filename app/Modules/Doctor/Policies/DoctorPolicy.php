<?php

namespace App\Modules\Doctor\Policies;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;

class DoctorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage doctors');
    }

    public function view(User $user, Doctor $doctor): bool
    {
        return $user->can('manage doctors');
    }

    public function create(User $user): bool
    {
        return $user->can('manage doctors');
    }

    public function update(User $user, Doctor $doctor): bool
    {
        return $user->can('manage doctors');
    }

    public function delete(User $user, Doctor $doctor): bool
    {
        return $user->can('manage doctors');
    }
}
