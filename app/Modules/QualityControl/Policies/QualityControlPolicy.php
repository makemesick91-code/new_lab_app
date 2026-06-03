<?php

namespace App\Modules\QualityControl\Policies;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;

/**
 * Authorization for QC review actions. Super Admin bypasses via Gate::before.
 */
class QualityControlPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['view_quality_control', 'manage_quality_control']);
    }

    public function view(User $user, LabOrder $order): bool
    {
        return $user->canAny(['view_quality_control', 'manage_quality_control']);
    }

    public function start(User $user, LabOrder $order): bool
    {
        return $order->status === LabOrder::STATUS_QC_PENDING
            && $user->canAny(['start_qc', 'manage_quality_control']);
    }

    public function pass(User $user, LabOrder $order): bool
    {
        return $order->status === LabOrder::STATUS_QC_PENDING
            && $user->canAny(['pass_qc', 'manage_quality_control']);
    }

    public function reject(User $user, LabOrder $order): bool
    {
        return $order->status === LabOrder::STATUS_QC_PENDING
            && $user->canAny(['reject_qc', 'manage_quality_control']);
    }

    public function uploadEvidence(User $user, LabOrder $order): bool
    {
        return $order->status !== LabOrder::STATUS_CANCELLED
            && $user->canAny(['upload_qc_evidence', 'manage_quality_control']);
    }
}
