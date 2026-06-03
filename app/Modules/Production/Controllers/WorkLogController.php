<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Production\Services\WorkLogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;

class WorkLogController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly WorkLogService $workLogService,
    ) {}

    public function index(LabOrder $labOrder): View
    {
        $this->authorize('production.worklogs.view', $labOrder);

        return view('production.work-logs', [
            'order' => $labOrder,
            'workLogs' => $this->workLogService->forLabOrder($labOrder->id),
        ]);
    }
}
