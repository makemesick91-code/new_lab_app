<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Requests\InventoryReportFilterRequest;
use App\Modules\Inventory\Services\InventoryReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryReportController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventoryReportService $reports,
        private readonly BranchContext $branchContext,
    ) {}

    public function index(InventoryReportFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $activeTab = $request->resolveActiveTab();
        $branchOptions = $this->reports->reportBranchOptions($request->user());
        $selectedBranchId = $this->reports->resolveReportBranchId($request->filters(), $request->user());
        $filters = array_merge($request->filters(), [
            'branch_id' => $selectedBranchId,
            'per_page' => $request->perPage(),
            'report_tab' => $activeTab,
        ]);
        $selectedBranch = $branchOptions->firstWhere('id', $selectedBranchId);

        $viewData = [
            'activeTab' => $activeTab,
            'activeTabKebab' => $request->activeTabKebab($activeTab),
            'filters' => $filters,
            'selectedBranchId' => $selectedBranchId,
            'selectedBranch' => $selectedBranch,
            'filterOptions' => $this->reports->getReportFilterOptions($filters, $branchOptions),
        ];

        match ($activeTab) {
            'current_stock' => $viewData['currentStockReport'] = $this->reports->getCurrentStockReport($filters),
            'stock_card' => $viewData['stockCardReport'] = $this->reports->getStockCardReport($filters),
            'low_stock' => $viewData['lowStockReport'] = $this->reports->getLowStockReport($filters),
            'mutation' => $viewData['stockMutationReport'] = $this->reports->getStockMutationReport($filters),
            'valuation' => $viewData['inventoryValuationReport'] = $this->reports->getInventoryValuationReport($filters),
            'room_stock' => $viewData['roomStockReport'] = $this->reports->getRoomStockReport($filters),
            default => $viewData['currentStockReport'] = $this->reports->getCurrentStockReport($filters),
        };

        return $this->renderInventoryView('inventory.reports.index', $viewData);
    }

    public function export(InventoryReportFilterRequest $request): StreamedResponse
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $filters = array_merge($request->filters(), [
            'branch_id' => $this->reports->resolveReportBranchId($request->filters(), $request->user()),
        ]);

        return $this->reports->exportCsv($filters);
    }

    public function downloadRoomStockRefillChecklist(InventoryReportFilterRequest $request): Response
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $branchOptions = $this->reports->reportBranchOptions($request->user());
        $selectedBranchId = $this->reports->resolveReportBranchId($request->filters(), $request->user());
        $filters = array_merge($request->filters(), [
            'branch_id' => $selectedBranchId,
        ]);
        $selectedBranch = $branchOptions->firstWhere('id', $selectedBranchId) ?? $this->branchContext->branch();

        return Pdf::loadView('inventory.reports.room-stock.refill-checklist', [
            'rows' => $this->reports->getRoomStockRefillChecklist($filters),
            'filterOptions' => $this->reports->getReportFilterOptions($filters, $branchOptions),
            'filters' => $filters,
            'branch' => $selectedBranch,
            'printedAt' => now(),
            'printedBy' => $request->user(),
        ])->download('checklist-refill-stok-ruangan-'.now()->toDateString().'.pdf');
    }
}
