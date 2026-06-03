<?php

namespace App\Modules\QualityControl\Policies;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\QualityControl\Models\QualityControlChecklist;

class ChecklistPolicy
{
    public function updateChecklist(User $user, QualityControlChecklist $checklist): bool
    {
        $order = $checklist->qualityControl?->labOrder;

        return $order !== null
            && $order->status === LabOrder::STATUS_QC_PENDING
            && ! $checklist->qualityControl->isCompleted()
            && $user->canAny(['update_qc_checklist', 'manage_quality_control']);
    }
}
