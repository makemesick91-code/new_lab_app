<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Requests\InventoryDashboardFilterRequest;
use App\Modules\Inventory\Services\InventoryAlertService;
use App\Modules\Inventory\Services\InventoryLocationService;
use App\Modules\Inventory\Services\InventoryReportService;
use App\Modules\Inventory\Services\InventoryStockService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;

class InventoryDashboardController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventoryStockService $stock,
        private readonly InventoryAlertService $alerts,
        private readonly InventoryLocationService $locations,
        private readonly InventoryReportService $reports,
    ) {}

    public function index(InventoryDashboardFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $branchOptions = $this->reports->reportBranchOptions($request->user());
        $selectedBranchId = $this->reports->resolveReportBranchId($request->filters(), $request->user());
        $selectedBranch = $branchOptions->firstWhere('id', $selectedBranchId);
        $reportQuery = ['branch_id' => $selectedBranchId];

        return $this->renderInventoryView('inventory.dashboard', [
            'branchOptions' => $branchOptions,
            'selectedBranchId' => $selectedBranchId,
            'selectedBranch' => $selectedBranch,
            'reportQuery' => $reportQuery,
            'summary' => $this->stock->getBranchSummary(branchId: $selectedBranchId),
            'alertSummary' => $this->alerts->getAlertSummary(branchId: $selectedBranchId),
            'stockAlerts' => $this->alerts->getStockAlerts(limit: 8, branchId: $selectedBranchId),
            'batchAlerts' => $this->alerts->getBatchExpiryAlerts(limit: 5, branchId: $selectedBranchId),
            'locations' => $this->locations->listActive($selectedBranchId),
            'stockByLocation' => $this->stock->getStockByLocationSummary($selectedBranchId),
            'recentMovements' => $this->stock->getRecentMovements(branchId: $selectedBranchId),
            'lastUpdatedAt' => now(),
        ]);
    }
}
