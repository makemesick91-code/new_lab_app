<?php

namespace App\Modules\Production\Policies;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;

/**
 * Authorization for assignment / reassignment / cancel (management-level).
 */
class AssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['view_production', 'manage_production']);
    }

    public function view(User $user, LabOrder $order): bool
    {
        return $user->canAny(['view_production', 'manage_production']);
    }

    public function assign(User $user, LabOrder $order): bool
    {
        return $order->status === LabOrder::STATUS_RECEIVED
            && $user->canAny(['assign_technicians', 'manage_production']);
    }

    public function reassign(User $user, LabOrder $order): bool
    {
        return in_array($order->status, [LabOrder::STATUS_ASSIGNED, 'IN_PRODUCTION', 'ON_HOLD'], true)
            && $user->canAny(['reassign_technicians', 'manage_production']);
    }

    public function cancel(User $user, LabOrder $order): bool
    {
        return $user->canAny(['reassign_technicians', 'manage_production']);
    }
}
