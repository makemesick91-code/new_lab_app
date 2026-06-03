<?php

namespace App\Modules\Production\Policies;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Production\Policies\Concerns\ChecksProductionAccess;

class ProductionStepPolicy
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

    public function update(User $user, LabOrder $order): bool
    {
        // Steps can be updated while the order is in an active production state.
        return in_array($order->status, [LabOrder::STATUS_ASSIGNED, 'IN_PRODUCTION', 'ON_HOLD'], true)
            && $this->canActOnProduction($user, $order, [
                'start_production_work',
                'pause_production_work',
                'resume_production_work',
                'complete_production_work',
            ]);
    }
}
