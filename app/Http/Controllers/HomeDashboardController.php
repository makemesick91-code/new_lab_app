<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Reporting\Services\OwnerDashboardKpiService;
use App\Modules\Reporting\Services\OwnerDashboardRmeLabDrilldownService;
use App\Modules\Reporting\Services\OwnerDashboardRmeLabKpiService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeDashboardController extends Controller
{
    public function __construct(
        private readonly OwnerDashboardRmeLabKpiService $ownerRmeLabKpis,
        private readonly OwnerDashboardRmeLabDrilldownService $ownerRmeLabDrilldowns,
        private readonly OwnerDashboardKpiService $ownerKpis,
    ) {}

    public function index(Request $request): View
    {
        $user = auth()->user();
        $ownerRmeLabPilot = null;
        $ownerRmeLabBranchSummary = [];
        $ownerRmeLabBranchReceivableSummary = [];
        $ownerRmeLabActiveBranches = collect();
        $ownerRmeLabSelectedBranchId = null;
        $ownerRmeLabDrilldowns = [];

        // Sprint 62.0 — executive period-based Owner KPI block.
        $ownerKpi = null;

        if ($user instanceof User && $this->shouldLoadOwnerRmeLabPilot($user)) {
            $requestedBranchId = $request->filled('branch_id')
                ? (int) $request->input('branch_id')
                : null;

            $ownerRmeLabSelectedBranchId = $this->ownerRmeLabKpis->resolveSelectedBranchId($requestedBranchId);
            $ownerRmeLabActiveBranches = $this->ownerRmeLabKpis->activeBranches();
            $ownerRmeLabPilot = $this->ownerRmeLabKpis->metrics($ownerRmeLabSelectedBranchId);
            $ownerRmeLabBranchSummary = $this->ownerRmeLabKpis->branchSummary($ownerRmeLabSelectedBranchId);
            $ownerRmeLabBranchReceivableSummary = $this->ownerRmeLabKpis->branchReceivableSummary($ownerRmeLabSelectedBranchId);
            $ownerRmeLabDrilldowns = $this->ownerRmeLabDrilldowns->linksFor($user);

            $period = $this->ownerKpis->resolvePeriod(
                $request->input('range'),
                $request->input('date_from'),
                $request->input('date_to'),
            );
            $kpiBranchId = $this->ownerKpis->resolveSelectedBranchId($requestedBranchId);

            $ownerKpi = [
                'period' => $period,
                'selected_branch_id' => $kpiBranchId,
                'metrics' => $this->ownerKpis->metrics($kpiBranchId, $period['from'], $period['to']),
                'branch_performance' => $this->ownerKpis->branchPerformance($kpiBranchId, $period['from'], $period['to']),
                'visit_trend' => $this->ownerKpis->dailyVisitTrend($kpiBranchId, $period['from'], $period['to']),
                'payment_trend' => $this->ownerKpis->dailyPaymentTrend($kpiBranchId, $period['from'], $period['to']),
                'top_unpaid' => $this->ownerKpis->topUnpaidReceivables($kpiBranchId),
                'drilldowns' => $this->ownerKpis->drilldownLinks($user, $period['from'], $period['to']),
                // FIX-PRE-68-45 Scope B — permission-gated module/report shortcuts.
                'module_shortcuts' => $this->ownerKpis->moduleShortcuts($user),
            ];
        }

        return view('dashboard', [
            'ownerRmeLabPilot' => $ownerRmeLabPilot,
            'ownerRmeLabBranchSummary' => $ownerRmeLabBranchSummary,
            'ownerRmeLabBranchReceivableSummary' => $ownerRmeLabBranchReceivableSummary,
            'ownerRmeLabActiveBranches' => $ownerRmeLabActiveBranches,
            'ownerRmeLabSelectedBranchId' => $ownerRmeLabSelectedBranchId,
            'ownerRmeLabDrilldowns' => $ownerRmeLabDrilldowns,
            'ownerKpi' => $ownerKpi,
        ]);
    }

    private function shouldLoadOwnerRmeLabPilot(User $user): bool
    {
        if ($this->hasBranchOperationalDashboard($user)) {
            return false;
        }

        return $user->can('view_owner_dashboard') || $user->can('manage_report');
    }

    private function hasBranchOperationalDashboard(User $user): bool
    {
        return $user->canAny([
            'view_lab_orders',
            'manage_lab_orders',
            'view_production',
            'manage_production',
            'view_quality_control',
            'manage_quality_control',
            'view_delivery',
            'manage_delivery',
            'view_inventory',
            'manage_inventory',
            'view_invoice',
            'manage_invoice',
        ]);
    }
}
