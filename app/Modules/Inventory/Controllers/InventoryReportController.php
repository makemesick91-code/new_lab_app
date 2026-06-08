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

        $filters = array_merge($request->filters(), [
            'per_page' => $request->perPage(),
        ]);

        return $this->renderInventoryView('inventory.reports.index', [
            'filters' => $filters,
            'filterOptions' => $this->reports->getReportFilterOptions($filters),
            'currentStockReport' => $this->reports->getCurrentStockReport($filters),
            'stockCardReport' => $this->reports->getStockCardReport($filters),
            'lowStockReport' => $this->reports->getLowStockReport($filters),
            'stockMutationReport' => $this->reports->getStockMutationReport($filters),
            'inventoryValuationReport' => $this->reports->getInventoryValuationReport($filters),
            'roomStockReport' => $this->reports->getRoomStockReport($filters),
        ]);
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
