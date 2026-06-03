<?php

namespace App\Modules\Invoice\Policies;

use App\Models\User;
use App\Modules\Invoice\Models\Invoice;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['view_invoice', 'manage_invoice']);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->canAny(['view_invoice', 'manage_invoice']);
    }

    public function create(User $user): bool
    {
        return $user->canAny(['create_invoice', 'manage_invoice']);
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $user->canAny(['issue_invoice', 'manage_invoice'])
            && $invoice->status === Invoice::STATUS_DRAFT;
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $user->canAny(['void_invoice', 'manage_invoice']);
    }
}
