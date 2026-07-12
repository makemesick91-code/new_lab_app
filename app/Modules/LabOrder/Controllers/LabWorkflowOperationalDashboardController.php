<?php

namespace App\Modules\LabOrder\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LabOrder\Requests\LabWorkflowDashboardRequest;
use App\Modules\LabOrder\Services\LabWorkflowOperationalDashboardService;
use App\Modules\LabOrder\Services\LabWorkflowSlaBaselineService;
use Illuminate\View\View;

/**
 * LAB-WORKFLOW-V2 (Phase 8 Operational Dashboard + Phase 9 SLA baseline).
 *
 * Thin controller: all read-model aggregation lives in the two services.
 * Route-gated by permission:view_lab_orders|manage_lab_orders; the services
 * enforce branch/role scope server-side. Read-only — no writes, no state change.
 */
class LabWorkflowOperationalDashboardController extends Controller
{
    public function __construct(
        private readonly LabWorkflowOperationalDashboardService $dashboard,
        private readonly LabWorkflowSlaBaselineService $sla,
    ) {}

    public function index(LabWorkflowDashboardRequest $request): View
    {
        $from = $request->validated('from');
        $to = $request->validated('to');
        $requestedBranchId = $request->integer('branch_id') ?: null;

        $overview = $this->dashboard->overview($request->user(), $from, $to, $requestedBranchId);

        // SLA baseline is scoped to the SAME fail-closed effective branch the
        // dashboard resolved (server-side), so a branch operator can never widen
        // scope — even when their own branch cannot be resolved (→ 0, empty).
        $baseline = $this->sla->baseline($overview['effective_branch_id'], $from, $to);

        return view('lab-workflow.dashboard.index', [
            'overview' => $overview,
            'baseline' => $baseline,
            'filters' => ['from' => $from, 'to' => $to, 'branch_id' => $overview['branch_id']],
        ]);
    }
}
