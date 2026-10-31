<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Policies;

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeRefundRequest;
use App\Modules\RmeInvoice\Services\RmeRefundRequestService;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215) — a COURTESY for routes
 * and views. Super Admin passes every policy through Gate::before, so the binding
 * rules (role, branch scope, maker ≠ checker, pilot) live in RmeRefundRequestService,
 * which refuses again on every mutation.
 */
class RmeRefundRequestPolicy
{
    public function __construct(
        private readonly RmeRefundRequestService $refunds,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can(RmeRefundRequestService::PERMISSION_REQUEST)
            || $user->can(RmeRefundRequestService::PERMISSION_APPROVE);
    }

    public function view(User $user, RmeRefundRequest $request): bool
    {
        return $this->viewAny($user) && $this->refunds->mayView($user, (int) $request->branch_id);
    }
}
