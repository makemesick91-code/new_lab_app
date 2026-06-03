<?php

namespace App\Modules\QualityControl\Policies;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;

class RemakePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['view_quality_control', 'manage_quality_control']);
    }

    public function view(User $user, LabOrder $order): bool
    {
        return $user->canAny(['view_quality_control', 'manage_quality_control']);
    }

    public function requestRemake(User $user, LabOrder $order): bool
    {
        return $order->status === LabOrder::STATUS_REMAKE
            && $user->canAny(['request_remake', 'manage_quality_control']);
    }
}
