<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\InventoryAlertService;
use App\Modules\Inventory\Services\InventoryLocationService;
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
    ) {}

    public function index(): View|Response
    {
        $this->authorize('viewAny', InventoryMovement::class);

        return $this->renderInventoryView('inventory.dashboard', [
            'summary' => $this->stock->getBranchSummary(),
            'alertSummary' => $this->alerts->getAlertSummary(),
            'stockAlerts' => $this->alerts->getStockAlerts(limit: 8),
            'batchAlerts' => $this->alerts->getBatchExpiryAlerts(limit: 5),
            'locations' => $this->locations->listActive(),
            'stockByLocation' => $this->stock->getStockByLocationSummary(),
            'recentMovements' => $this->stock->getRecentMovements(),
        ]);
    }
}
