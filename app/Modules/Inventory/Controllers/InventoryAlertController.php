<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Requests\InventoryAlertFilterRequest;
use App\Modules\Inventory\Services\InventoryAlertService;
use App\Modules\Inventory\Services\InventoryLocationService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;

class InventoryAlertController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventoryAlertService $alerts,
        private readonly InventoryLocationService $locations,
    ) {}

    public function index(InventoryAlertFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $locationId = $request->locationId();

        return $this->renderInventoryView('inventory.alerts.index', [
            'summary' => $this->alerts->getAlertSummary($locationId),
            'alerts' => $this->alerts->getUnifiedAlerts($locationId, $request->filters(), $request->perPage()),
            'locations' => $this->locations->listActive(),
            'filters' => array_merge($request->filters(), [
                'inventory_location_id' => $locationId,
            ]),
        ]);
    }
}
