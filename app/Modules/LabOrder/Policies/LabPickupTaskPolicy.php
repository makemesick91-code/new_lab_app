<?php

namespace App\Modules\LabOrder\Policies;

use App\Models\User;
use App\Modules\LabOrder\Models\LabPickupTask;

/**
 * LAB-WORKFLOW-V2 — pickup task authorization. Super Admin bypasses via Gate::before.
 */
class LabPickupTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['manage_lab_pickups', 'manage_lab_orders']);
    }

    public function view(User $user, LabPickupTask $task): bool
    {
        return $user->canAny(['manage_lab_pickups', 'manage_lab_orders']);
    }

    /** Claim a pending task (courier action). */
    public function accept(User $user, LabPickupTask $task): bool
    {
        return $user->can('manage_lab_pickups');
    }

    /** Progress an owned task (pickup / transit). Ownership re-checked in the service under lock. */
    public function progress(User $user, LabPickupTask $task): bool
    {
        return $user->can('manage_lab_pickups') && $task->isClaimedBy($user);
    }

    /** Lab-side receive confirmation — never the courier's own action. */
    public function receive(User $user, LabPickupTask $task): bool
    {
        return $user->can('manage_lab_orders');
    }
}
