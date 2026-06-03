<?php

namespace App\Modules\Technician\Policies;

use App\Models\User;
use App\Modules\Technician\Models\Technician;

class TechnicianPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage technicians');
    }

    public function view(User $user, Technician $technician): bool
    {
        return $user->can('manage technicians');
    }

    public function create(User $user): bool
    {
        return $user->can('manage technicians');
    }

    public function update(User $user, Technician $technician): bool
    {
        return $user->can('manage technicians');
    }

    public function delete(User $user, Technician $technician): bool
    {
        return $user->can('manage technicians');
    }
}
