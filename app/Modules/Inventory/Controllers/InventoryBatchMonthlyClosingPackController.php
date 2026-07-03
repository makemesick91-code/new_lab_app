<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Requests\InventoryBatchMonthlyClosingPackFilterRequest;
use App\Modules\Inventory\Services\InventoryBatchMonthlyClosingPackService;
use App\Modules\Inventory\Services\InventoryReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryBatchMonthlyClosingPackController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventoryBatchMonthlyClosingPackService $closingPack,
        private readonly InventoryReportService $reports,
    ) {}

    public function index(InventoryBatchMonthlyClosingPackFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $filters = $this->closingPack->prepareFilters($request->filters(), $request->user());
        $scope = $this->closingPack->resolveBranchScope($request->filters(), $request->user());
        $pack = $this->closingPack->getClosingPack($filters, $request->user(), $request->perPage());

        return $this->renderInventoryView('inventory.reports.batch-monthly-closing.index', [
            'filters' => $filters,
            'scope' => $scope,
            'branchOptions' => $this->reports->reportBranchOptions($request->user()),
            'selectedBranchId' => $scope['selected_branch_id'],
            'filterOptions' => $this->closingPack->getFilterOptions($filters, $request->user()),
            'summary' => $pack['summary'],
            'breakdowns' => $pack['breakdowns'],
            'expiryRiskRows' => $pack['expiry_risk_rows'],
            'actionLogRows' => $pack['action_log_rows'],
            'disposalRows' => $pack['disposal_rows'],
            'ledgerEvidenceRows' => $pack['ledger_evidence_rows'],
            'exceptionRows' => $pack['exception_rows'],
            'checklist' => $pack['checklist'],
            'periodLabel' => $pack['period_label'],
            'generatedAt' => now(),
        ]);
    }

    public function export(InventoryBatchMonthlyClosingPackFilterRequest $request): StreamedResponse
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $filters = $this->closingPack->prepareFilters($request->filters(), $request->user());

        return $this->closingPack->exportCsv($filters, $request->user());
    }

    public function print(InventoryBatchMonthlyClosingPackFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $filters = $this->closingPack->prepareFilters($request->filters(), $request->user());
        $scope = $this->closingPack->resolveBranchScope($request->filters(), $request->user());
        $pack = $this->closingPack->getClosingPack($filters, $request->user(), 500);

        return $this->renderInventoryView('inventory.reports.batch-monthly-closing.print', [
            'filters' => $filters,
            'scope' => $scope,
            'branchOptions' => $this->reports->reportBranchOptions($request->user()),
            'selectedBranchId' => $scope['selected_branch_id'],
            'summary' => $pack['summary'],
            'breakdowns' => $pack['breakdowns'],
            'expiryRiskRows' => $pack['expiry_risk_rows'],
            'actionLogRows' => $pack['action_log_rows'],
            'disposalRows' => $pack['disposal_rows'],
            'ledgerEvidenceRows' => $pack['ledger_evidence_rows'],
            'exceptionRows' => $pack['exception_rows'],
            'checklist' => $pack['checklist'],
            'periodLabel' => $pack['period_label'],
            'generatedAt' => now(),
            'printedBy' => $request->user(),
        ]);
    }
}
