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
        $filters = array_merge($request->filters(), [
            'per_page' => $request->perPage(),
            'report_tab' => $activeTab,
        ]);

        $viewData = [
            'activeTab' => $activeTab,
            'activeTabKebab' => $request->activeTabKebab($activeTab),
            'filters' => $filters,
            'filterOptions' => $this->reports->getReportFilterOptions($filters),
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

        return $this->reports->exportCsv($request->filters());
    }

    public function downloadRoomStockRefillChecklist(InventoryReportFilterRequest $request): Response
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $filters = $request->filters();

        return Pdf::loadView('inventory.reports.room-stock.refill-checklist', [
            'rows' => $this->reports->getRoomStockRefillChecklist($filters),
            'filterOptions' => $this->reports->getReportFilterOptions($filters),
            'filters' => $filters,
            'branch' => $this->branchContext->branch(),
            'printedAt' => now(),
            'printedBy' => $request->user(),
        ])->download('checklist-refill-stok-ruangan-'.now()->toDateString().'.pdf');
    }
}
