<?php

namespace App\Modules\Reporting\Services;

use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Support\Carbon;

/**
 * Sprint 23 Phase 23.5 — Global (single-branch) Lab KPI service.
 *
 * Laboratory is a single / global laboratory. Every metric here is computed
 * across ALL data with no branch filtering and no branch grouping. This service
 * deliberately accepts no branch parameter so a branch filter can never leak
 * into Lab KPIs. Legacy branch_id columns on lab tables are ignored here.
 */
class LabDashboardKpiService
{
    /**
     * @return array<string, mixed>
     */
    public function metrics(?Carbon $asOf = null): array
    {
        $today = ($asOf ?? now())->toDateString();

        return [
            'lab_orders_total' => LabOrder::query()->count(),
            'lab_orders_created_today' => LabOrder::query()
                ->whereDate('created_at', $today)
                ->count(),
            'lab_orders_from_rme_today' => LabOrder::query()
                ->whereDate('created_at', $today)
                ->whereHas('rmeLabCaseCandidate')
                ->count(),
            'lab_candidates_pending' => LabCaseCandidate::query()
                ->where('status', LabCaseCandidate::STATUS_PENDING_REVIEW)
                ->count(),
            'lab_candidates_converted_today' => LabCaseCandidate::query()
                ->where('status', LabCaseCandidate::STATUS_CONVERTED_TO_LAB_ORDER)
                ->whereDate('reviewed_at', $today)
                ->count(),
            'scope_label' => 'Laboratorium global',
        ];
    }
}
