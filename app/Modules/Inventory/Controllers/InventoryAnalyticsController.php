<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Requests\InventoryAnalyticsFilterRequest;
use App\Modules\Inventory\Services\InventoryAnalyticsService;
use App\Modules\Inventory\Services\InventoryLocationService;
use App\Modules\Inventory\Services\InventoryProductService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;

class InventoryAnalyticsController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventoryAnalyticsService $analytics,
        private readonly InventoryLocationService $locations,
        private readonly InventoryProductService $products,
        private readonly BranchContext $branchContext,
    ) {}

    public function index(InventoryAnalyticsFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $branchId = $this->branchContext->requireId();
        $filters = $request->serviceFilters();
        $tab = $request->tab();

        return $this->renderInventoryView('inventory.analytics.index', [
            'tab' => $tab,
            'filters' => $request->viewFilters(),
            'summary' => $this->analytics->getAnalyticsSummary($branchId, $filters),
            'fastMoving' => $this->analytics->getFastMovingProducts($branchId, $filters),
            'slowMoving' => $this->analytics->getSlowMovingProducts($branchId, $filters),
            'deadStock' => $this->analytics->getDeadStockProducts($branchId, $filters),
            'aging' => $this->analytics->getInventoryAging($branchId, $filters),
            'turnover' => $this->analytics->getInventoryTurnover($branchId, $filters),
            'valueByCategory' => $this->analytics->getInventoryValueByCategory($branchId, $filters),
            'valueByLocation' => $this->analytics->getInventoryValueByLocation($branchId, $filters),
            'outboundTrend' => $this->analytics->getMonthlyOutboundValueTrend($branchId, $filters),
            'locations' => $this->locations->listActive(),
            'categories' => $this->products->listActiveCategories(),
        ]);
    }
}
