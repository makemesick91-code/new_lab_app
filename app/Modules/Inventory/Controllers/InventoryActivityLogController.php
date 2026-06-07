<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Enums\InventoryActivityAction;
use App\Modules\Inventory\Models\InventoryActivityLog;
use App\Modules\Inventory\Requests\InventoryActivityLogFilterRequest;
use App\Modules\Inventory\Services\InventoryActivityLogService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;

class InventoryActivityLogController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventoryActivityLogService $activityLogs,
        private readonly BranchContext $branchContext,
    ) {}

    public function index(InventoryActivityLogFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', InventoryActivityLog::class);

        $branchId = $this->branchContext->requireId();

        return $this->renderInventoryView('inventory.activity-logs.index', [
            'logs' => $this->activityLogs->listForBranch($branchId, $request->filters()),
            'filters' => $request->validated(),
            'actionOptions' => InventoryActivityAction::all(),
        ]);
    }

    public function show(int $inventoryActivityLog): View|Response
    {
        $branchId = $this->branchContext->requireId();
        $log = $this->activityLogs->findInBranch($branchId, $inventoryActivityLog);

        abort_if($log === null, 404);

        $this->authorize('view', $log);

        return $this->renderInventoryView('inventory.activity-logs.show', [
            'log' => $log,
        ]);
    }
}
