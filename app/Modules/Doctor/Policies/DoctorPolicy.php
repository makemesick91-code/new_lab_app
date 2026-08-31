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

    /**
     * FEATURE-DOCTOR-ACCOUNT-PERFORMANCE-INCOME-LINKAGE-1
     *
     * Linking an account to a doctor decides whose clinical history and whose
     * income that account can read, so it is deliberately a separate, narrower
     * permission than ordinary doctor master-data maintenance. Someone trusted
     * to correct a doctor's phone number is not thereby trusted to hand out
     * access to another doctor's earnings.
     */
    public function manageAccountLink(User $user): bool
    {
        return $user->can('manage_doctor_account_links');
    }
}
