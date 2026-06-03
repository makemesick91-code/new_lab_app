<?php

namespace App\Modules\LabOrder\Policies;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;

/**
 * Record-level authorization for Lab Orders. Super Admin bypasses via Gate::before.
 */
class LabOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['manage_lab_orders', 'view_lab_orders']);
    }

    public function view(User $user, LabOrder $order): bool
    {
        return $user->canAny(['manage_lab_orders', 'view_lab_orders']);
    }

    public function create(User $user): bool
    {
        return $user->canAny(['manage_lab_orders', 'create_lab_orders']);
    }

    public function update(User $user, LabOrder $order): bool
    {
        return $user->canAny(['manage_lab_orders', 'update_lab_orders']) && $order->isEditable();
    }

    public function cancel(User $user, LabOrder $order): bool
    {
        return $user->canAny(['manage_lab_orders', 'cancel_lab_orders']) && $order->isEditable();
    }

    public function delete(User $user, LabOrder $order): bool
    {
        return $user->can('manage_lab_orders')
            && ! in_array($order->status, ['DELIVERED', 'COMPLETED'], true);
    }

    public function uploadAttachment(User $user, LabOrder $order): bool
    {
        return $user->canAny(['manage_lab_orders', 'create_lab_orders', 'update_lab_orders'])
            && $order->isEditable();
    }

    public function deleteAttachment(User $user, LabOrder $order): bool
    {
        return $user->canAny(['manage_lab_orders', 'update_lab_orders'])
            && $order->isEditable();
    }
}
