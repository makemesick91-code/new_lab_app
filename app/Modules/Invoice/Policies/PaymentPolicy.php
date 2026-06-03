<?php

namespace App\Modules\Invoice\Policies;

use App\Models\User;
use App\Modules\Invoice\Models\Payment;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['view_payment', 'manage_payment']);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->canAny(['view_payment', 'manage_payment']);
    }

    public function create(User $user): bool
    {
        return $user->canAny(['create_payment', 'manage_payment']);
    }
}
