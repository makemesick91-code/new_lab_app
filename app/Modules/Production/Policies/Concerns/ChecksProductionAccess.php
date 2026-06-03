<?php

namespace App\Modules\Production\Policies\Concerns;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Technician\Models\Technician;

/**
 * Shared production authorization helpers: management bypass and technician
 * ownership of the active assignment.
 */
trait ChecksProductionAccess
{
    protected function isTechnicianUser(User $user): bool
    {
        return Technician::where('user_id', $user->id)->exists();
    }

    protected function ownsActiveAssignment(User $user, LabOrder $order): bool
    {
        $assignment = $order->activeAssignment()->with('technician')->first();

        return $assignment !== null
            && $assignment->technician !== null
            && (int) $assignment->technician->user_id === (int) $user->id;
    }

    /**
     * Allow when the user has management permission, or holds one of the given
     * work permissions and (if a technician) owns the active assignment.
     *
     * @param  array<int, string>  $permissions
     */
    protected function canActOnProduction(User $user, LabOrder $order, array $permissions): bool
    {
        if ($user->can('manage_production')) {
            return true;
        }

        if (! $user->canAny($permissions)) {
            return false;
        }

        if ($this->isTechnicianUser($user)) {
            return $this->ownsActiveAssignment($user, $order);
        }

        return true;
    }
}
