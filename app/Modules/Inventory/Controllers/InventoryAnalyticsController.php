<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Requests\InventoryAnalyticsFilterRequest;
use App\Modules\Inventory\Services\InventoryAnalyticsPageService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;

class InventoryAnalyticsController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventoryAnalyticsPageService $page,
    ) {}

    public function index(InventoryAnalyticsFilterRequest $request): View|Response
    {
        $this->authorize('viewAnalytics', InventoryMovement::class);

        return $this->renderInventoryView(
            'inventory.analytics.index',
            $this->page->buildPage(
                $request->tab(),
                $request->viewFilters(),
                $request->user(),
            ),
        );
    }
}
