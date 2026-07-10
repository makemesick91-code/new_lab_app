<?php

namespace App\Modules\LabOrder\Policies;

use App\Models\User;
use App\Modules\LabOrder\Models\LabDeliveryTask;

/**
 * LAB-WORKFLOW-V2 — delivery task authorization. Super Admin bypasses via Gate::before.
 */
class LabDeliveryTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['view_delivery', 'manage_delivery', 'manage_lab_orders']);
    }

    public function view(User $user, LabDeliveryTask $task): bool
    {
        return $user->canAny(['view_delivery', 'manage_delivery', 'manage_lab_orders']);
    }

    /** Create the delivery task once the model is done (lab side). */
    public function create(User $user): bool
    {
        return $user->canAny(['create_delivery', 'manage_delivery', 'manage_lab_orders']);
    }

    /** Claim a pending delivery (courier action). */
    public function accept(User $user, LabDeliveryTask $task): bool
    {
        return $user->canAny(['start_delivery', 'manage_delivery']);
    }

    /** Progress an owned delivery (handover proof / transit / arrival). Ownership re-checked under lock. */
    public function progress(User $user, LabDeliveryTask $task): bool
    {
        return $user->canAny(['start_delivery', 'manage_delivery']) && $task->isClaimedBy($user);
    }

    /** Complete with recipient proofs. Ownership re-checked under lock. */
    public function complete(User $user, LabDeliveryTask $task): bool
    {
        return $user->canAny(['mark_delivered', 'manage_delivery']) && $task->isClaimedBy($user);
    }
}
