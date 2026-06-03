<?php

namespace App\Modules\Production\Policies;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Production\Policies\Concerns\ChecksProductionAccess;

/**
 * Authorization for the production workflow actions on a Lab Order.
 * Super Admin bypasses via Gate::before.
 */
class ProductionPolicy
{
    use ChecksProductionAccess;

    public function viewAny(User $user): bool
    {
        return $user->canAny(['view_production', 'manage_production']);
    }

    public function view(User $user, LabOrder $order): bool
    {
        return $user->canAny(['view_production', 'manage_production'])
            || $this->ownsActiveAssignment($user, $order);
    }

    public function start(User $user, LabOrder $order): bool
    {
        return $order->status === LabOrder::STATUS_ASSIGNED
            && $this->canActOnProduction($user, $order, ['start_production_work']);
    }

    public function pause(User $user, LabOrder $order): bool
    {
        return $order->status === 'IN_PRODUCTION'
            && $this->canActOnProduction($user, $order, ['pause_production_work']);
    }

    public function resume(User $user, LabOrder $order): bool
    {
        return $order->status === 'ON_HOLD'
            && $this->canActOnProduction($user, $order, ['resume_production_work']);
    }

    public function complete(User $user, LabOrder $order): bool
    {
        return $order->status === 'IN_PRODUCTION'
            && $this->canActOnProduction($user, $order, ['complete_production_work']);
    }

    public function sendToQc(User $user, LabOrder $order): bool
    {
        return $order->status === 'IN_PRODUCTION'
            && $this->canActOnProduction($user, $order, ['send_to_qc', 'complete_production_work']);
    }
}
