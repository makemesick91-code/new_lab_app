<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Policies;

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeRefundSettlement;
use App\Modules\RmeInvoice\Services\RmeRefundRequestService;
use App\Modules\RmeInvoice\Services\RmeRefundSettlementService;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — a COURTESY for routes and views.
 * Super Admin passes every policy through Gate::before, so the binding rules
 * (designation, branch, settler ≠ approver, starter, pilot) live in
 * RmeRefundSettlementService, which refuses again on every mutation.
 */
class RmeRefundSettlementPolicy
{
    public function __construct(
        private readonly RmeRefundSettlementService $settlements,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can(RmeRefundSettlementService::PERMISSION_SETTLE)
            || $user->can(RmeRefundRequestService::PERMISSION_APPROVE);
    }

    public function view(User $user, RmeRefundSettlement $settlement): bool
    {
        return $this->viewAny($user) && $this->settlements->mayView($user, (int) $settlement->branch_id);
    }
}
