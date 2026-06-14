<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Reporting\Services\OwnerDashboardRmeLabDrilldownService;
use App\Modules\Reporting\Services\OwnerDashboardRmeLabKpiService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeDashboardController extends Controller
{
    public function __construct(
        private readonly OwnerDashboardRmeLabKpiService $ownerRmeLabKpis,
        private readonly OwnerDashboardRmeLabDrilldownService $ownerRmeLabDrilldowns,
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
        }

        return view('dashboard', [
            'ownerRmeLabPilot' => $ownerRmeLabPilot,
            'ownerRmeLabBranchSummary' => $ownerRmeLabBranchSummary,
            'ownerRmeLabBranchReceivableSummary' => $ownerRmeLabBranchReceivableSummary,
            'ownerRmeLabActiveBranches' => $ownerRmeLabActiveBranches,
            'ownerRmeLabSelectedBranchId' => $ownerRmeLabSelectedBranchId,
            'ownerRmeLabDrilldowns' => $ownerRmeLabDrilldowns,
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
