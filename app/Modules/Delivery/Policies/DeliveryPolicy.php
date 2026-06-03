<?php

namespace App\Modules\Delivery\Policies;

use App\Models\User;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\LabOrder\Models\LabOrder;

class DeliveryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['view_delivery', 'manage_delivery']);
    }

    public function view(User $user, Delivery $delivery): bool
    {
        if ($user->can('manage_delivery')) {
            return true;
        }

        if (! $user->can('view_delivery')) {
            return false;
        }

        if ((int) $delivery->courier_id === (int) $user->id) {
            return true;
        }

        return $user->hasAnyRole(['Admin Lab', 'Delivery Coordinator', 'Quality Control', 'Technician']);
    }

    public function create(User $user, ?LabOrder $order = null): bool
    {
        return $user->canAny(['create_delivery', 'manage_delivery'])
            && (! $order || $order->status === LabOrder::STATUS_QC_PASSED);
    }

    public function assignCourier(User $user, Delivery $delivery): bool
    {
        return $user->canAny(['assign_courier', 'manage_delivery'])
            && ! in_array($delivery->status, [Delivery::STATUS_DELIVERED, Delivery::STATUS_COMPLETED, Delivery::STATUS_CANCELLED], true);
    }

    public function startDelivery(User $user, Delivery $delivery): bool
    {
        return $this->canCourierAct($user, $delivery, ['start_delivery']);
    }

    public function markDelivered(User $user, Delivery $delivery): bool
    {
        return $this->canCourierAct($user, $delivery, ['mark_delivered']);
    }

    public function completeDelivery(User $user, Delivery $delivery): bool
    {
        return $this->canCourierAct($user, $delivery, ['complete_delivery', 'mark_delivered']);
    }

    public function uploadPod(User $user, Delivery $delivery): bool
    {
        return $this->canCourierAct($user, $delivery, ['upload_pod']);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function canCourierAct(User $user, Delivery $delivery, array $permissions): bool
    {
        if ($user->can('manage_delivery')) {
            return true;
        }

        if (! $user->canAny($permissions)) {
            return false;
        }

        return (int) $delivery->courier_id === (int) $user->id;
    }
}
