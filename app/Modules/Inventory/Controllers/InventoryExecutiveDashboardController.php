<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\InventoryExecutiveDashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;

class InventoryExecutiveDashboardController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventoryExecutiveDashboardService $dashboard,
        private readonly BranchContext $branchContext,
    ) {}

    public function index(): View|Response
    {
        $this->authorize('viewExecutiveDashboard', InventoryMovement::class);

        $branchId = $this->branchContext->requireId();

        return $this->renderInventoryView('inventory.executive-dashboard', [
            'dashboard' => $this->dashboard->getExecutiveDashboard($branchId),
        ]);
    }
}
