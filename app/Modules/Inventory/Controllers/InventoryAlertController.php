<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Requests\InventoryAlertFilterRequest;
use App\Modules\Inventory\Services\InventoryAlertService;
use App\Modules\Inventory\Services\InventoryBatchActionLogService;
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
        private readonly InventoryBatchActionLogService $batchActionLogs,
    ) {}

    public function index(InventoryAlertFilterRequest $request): View|Response
    {
        $this->authorize('viewAlerts', InventoryMovement::class);

        $locationId = $request->locationId();
        $batchExpiryAlerts = $this->alerts->getBatchExpiryAlerts($locationId);
        $batchIds = $batchExpiryAlerts->pluck('inventory_batch_id')->filter()->map(fn ($id) => (int) $id)->all();
        $latestBatchActions = $this->batchActionLogs->latestForBatches($batchIds);
        $expiryAlertBatches = $batchIds === []
            ? collect()
            : InventoryBatch::query()->whereIn('id', $batchIds)->get()->keyBy('id');

        return $this->renderInventoryView('inventory.alerts.index', [
            'summary' => $this->alerts->getAlertSummary($locationId),
            'batchExpiryAlerts' => $batchExpiryAlerts,
            'latestBatchActions' => $latestBatchActions,
            'expiryAlertBatches' => $expiryAlertBatches,
            'alerts' => $this->alerts->getUnifiedAlerts($locationId, $request->filters(), $request->perPage()),
            'locations' => $this->locations->listActive(),
            'filters' => array_merge($request->filters(), [
                'inventory_location_id' => $locationId,
            ]),
        ]);
    }
}
