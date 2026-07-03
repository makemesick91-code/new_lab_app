<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Requests\InventoryBatchDisposalReportFilterRequest;
use App\Modules\Inventory\Services\InventoryBatchDisposalReportService;
use App\Modules\Inventory\Services\InventoryReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryBatchDisposalReportController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventoryBatchDisposalReportService $report,
        private readonly InventoryReportService $reports,
    ) {}

    public function index(InventoryBatchDisposalReportFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $filters = $this->report->prepareFilters($request->filters(), $request->user());
        $scope = $this->report->resolveBranchScope($request->filters(), $request->user());
        $branchOptions = $this->reports->reportBranchOptions($request->user());
        $reportData = $this->report->getReport($filters, $request->user(), $request->perPage());

        return $this->renderInventoryView('inventory.reports.batch-disposals.index', [
            'filters' => $filters,
            'scope' => $scope,
            'branchOptions' => $branchOptions,
            'selectedBranchId' => $scope['selected_branch_id'],
            'filterOptions' => $this->report->getFilterOptions($filters, $request->user()),
            'summary' => $reportData['summary'],
            'breakdowns' => $reportData['breakdowns'],
            'rows' => $reportData['rows'],
            'generatedAt' => now(),
        ]);
    }

    public function export(InventoryBatchDisposalReportFilterRequest $request): StreamedResponse
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $filters = $this->report->prepareFilters($request->filters(), $request->user());

        return $this->report->exportCsv($filters, $request->user());
    }

    public function print(InventoryBatchDisposalReportFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $filters = $this->report->prepareFilters($request->filters(), $request->user());
        $scope = $this->report->resolveBranchScope($request->filters(), $request->user());
        $branchOptions = $this->reports->reportBranchOptions($request->user());
        $reportData = $this->report->getReport($filters, $request->user(), 500);

        return $this->renderInventoryView('inventory.reports.batch-disposals.print', [
            'filters' => $filters,
            'scope' => $scope,
            'branchOptions' => $branchOptions,
            'selectedBranchId' => $scope['selected_branch_id'],
            'summary' => $reportData['summary'],
            'breakdowns' => $reportData['breakdowns'],
            'rows' => $reportData['rows'],
            'generatedAt' => now(),
            'printedBy' => $request->user(),
        ]);
    }
}
