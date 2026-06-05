<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\StockOpnameRepositoryInterface;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Requests\CancelStockOpnameRequest;
use App\Modules\Inventory\Requests\FinalizeStockOpnameRequest;
use App\Modules\Inventory\Requests\ReviewStockOpnameRequest;
use App\Modules\Inventory\Requests\StoreStockOpnameRequest;
use App\Modules\Inventory\Requests\UpdateStockOpnameItemRequest;
use App\Modules\Inventory\Services\InventoryLocationService;
use App\Modules\Inventory\Services\StockOpnameService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StockOpnameController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly StockOpnameRepositoryInterface $opnames,
        private readonly StockOpnameService $opnameService,
        private readonly InventoryLocationService $locations,
        private readonly ProductRepositoryInterface $products,
        private readonly BranchContext $branchContext,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', StockOpname::class);

        return $this->renderInventoryView('inventory.stock-opnames.index', [
            'stockOpnames' => $this->opnames->paginate(
                $this->branchContext->requireId(),
                $request->all(),
            ),
            'locations' => $this->locations->listActive(),
            'statuses' => StockOpname::STATUSES,
            'filters' => $request->only(['search', 'inventory_location_id', 'status', 'date_from', 'date_to']),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', StockOpname::class);

        $products = $this->products->listActive($this->branchContext->requireId());

        return $this->renderInventoryView('inventory.stock-opnames.create', [
            'locations' => $this->locations->listActive(),
            'products' => $products,
        ]);
    }

    public function store(StoreStockOpnameRequest $request): RedirectResponse
    {
        $this->authorize('create', StockOpname::class);

        $opname = $this->opnameService->createDraftOpname(
            (int) $request->validated('inventory_location_id'),
            $request->validated('product_ids', []),
            $request->validated('notes'),
            $request->validated('opname_date'),
        );

        return redirect()->route('inventory.stock-opnames.show', $opname)
            ->with('status', 'Stock opname created successfully.');
    }

    public function show(StockOpname $stockOpname): View
    {
        $this->authorize('view', $stockOpname);

        $stockOpname = $this->opnames->loadItems($stockOpname);
        $products = $this->products->listActive($this->branchContext->requireId());

        return $this->renderInventoryView('inventory.stock-opnames.show', [
            'stockOpname' => $stockOpname,
            'products' => $products,
        ]);
    }

    public function updateCountedQuantity(UpdateStockOpnameItemRequest $request, StockOpname $stockOpname, int $productId): RedirectResponse
    {
        $this->authorize('update', $stockOpname);

        $actualProductId = $productId === 0 ? (int) $request->input('product_id') : $productId;

        $this->opnameService->updateCountedQuantity(
            $stockOpname->id,
            $actualProductId,
            (float) $request->validated('counted_quantity'),
            $request->validated('notes'),
        );

        return back()->with('status', 'Counted quantity updated successfully.');
    }

    public function review(ReviewStockOpnameRequest $request, StockOpname $stockOpname): RedirectResponse
    {
        $this->authorize('review', $stockOpname);

        $this->opnameService->reviewOpname($stockOpname->id);

        return back()->with('status', 'Stock opname reviewed successfully.');
    }

    public function finalize(FinalizeStockOpnameRequest $request, StockOpname $stockOpname): RedirectResponse
    {
        $this->authorize('finalize', $stockOpname);

        $this->opnameService->finalizeOpname($stockOpname->id);

        return back()->with('status', 'Stock opname finalized successfully.');
    }

    public function cancel(CancelStockOpnameRequest $request, StockOpname $stockOpname): RedirectResponse
    {
        $this->authorize('cancel', $stockOpname);

        $this->opnameService->cancelOpname($stockOpname->id, $request->validated('notes'));

        return redirect()->route('inventory.stock-opnames.index')
            ->with('status', 'Stock opname cancelled successfully.');
    }

    public function reviewScreen(StockOpname $stockOpname): View
    {
        $this->authorize('view', $stockOpname);

        $stockOpname = $this->opnames->loadItems($stockOpname);

        // Calculate summary data
        $totalProducts = $stockOpname->items->count();
        $totalVariances = $stockOpname->items->filter(fn ($item) => abs((float) $item->variance_quantity) > 0)->count();
        $overages = $stockOpname->items->filter(fn ($item) => (float) $item->variance_quantity > 0)->count();
        $shortages = $stockOpname->items->filter(fn ($item) => (float) $item->variance_quantity < 0)->count();

        return $this->renderInventoryView('inventory.stock-opnames.review', [
            'stockOpname' => $stockOpname,
            'totalProducts' => $totalProducts,
            'totalVariances' => $totalVariances,
            'overages' => $overages,
            'shortages' => $shortages,
        ]);
    }
}
