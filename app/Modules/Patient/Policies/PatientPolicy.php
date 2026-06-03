<?php

namespace App\Modules\Patient\Policies;

use App\Models\User;
use App\Modules\Patient\Models\Patient;

class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage patients');
    }

    public function view(User $user, Patient $patient): bool
    {
        return $user->can('manage patients');
    }

    public function create(User $user): bool
    {
        return $user->can('manage patients');
    }

    public function update(User $user, Patient $patient): bool
    {
        return $user->can('manage patients');
    }

    public function delete(User $user, Patient $patient): bool
    {
        return $user->can('manage patients');
    }
}
