<?php

namespace App\Modules\PaymentMethod\Policies;

use App\Models\User;
use App\Modules\PaymentMethod\Models\PaymentMethod;

class PaymentMethodPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, PaymentMethod $paymentMethod): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, PaymentMethod $paymentMethod): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, PaymentMethod $paymentMethod): bool
    {
        return $this->canManage($user);
    }

    private function canView(User $user): bool
    {
        return $user->canAny([
            'view_clinic_master_data',
            'manage_clinic_master_data',
        ]);
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage_clinic_master_data');
    }
}
