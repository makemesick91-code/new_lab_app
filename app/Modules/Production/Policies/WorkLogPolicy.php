<?php

namespace App\Modules\Production\Policies;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Production\Policies\Concerns\ChecksProductionAccess;

class WorkLogPolicy
{
    use ChecksProductionAccess;

    public function viewAny(User $user, LabOrder $order): bool
    {
        return $user->canAny(['view_production', 'manage_production'])
            || $this->ownsActiveAssignment($user, $order);
    }

    public function view(User $user, LabOrder $order): bool
    {
        return $this->viewAny($user, $order);
    }
}
