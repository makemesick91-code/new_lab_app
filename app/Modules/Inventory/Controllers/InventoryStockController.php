<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Requests\InventoryFilterRequest;
use App\Modules\Inventory\Requests\StoreAdjustmentRequest;
use App\Modules\Inventory\Requests\StoreOpeningStockRequest;
use App\Modules\Inventory\Requests\StoreReceiveStockRequest;
use App\Modules\Inventory\Services\InventoryAlertService;
use App\Modules\Inventory\Services\InventoryLocationService;
use App\Modules\Inventory\Services\InventoryStockService;
use App\Modules\Inventory\Services\InventorySupplierService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class InventoryStockController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventoryStockService $stock,
        private readonly InventoryAlertService $alerts,
        private readonly InventoryLocationService $locations,
        private readonly InventorySupplierService $suppliers,
    ) {}

    public function index(InventoryFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $locationId = $request->integer('inventory_location_id') ?: null;

        return $this->renderInventoryView('inventory.stock.index', [
            'stockRows' => $this->stock->getStockRows($locationId),
            'summary' => $this->stock->getBranchSummary($locationId),
            'alertSummary' => $this->alerts->getAlertSummary($locationId),
            'locations' => $this->locations->listActive(),
            'filters' => $request->filters(),
        ]);
    }

    public function openingStock(Product $product): View|Response
    {
        $this->authorizeStockOperation($product);

        return $this->renderInventoryView('inventory.stock.opening', [
            'product' => $product->load(['category', 'unit']),
            'locations' => $this->locations->listActive(),
        ]);
    }

    public function storeOpeningStock(StoreOpeningStockRequest $request, Product $product): RedirectResponse
    {
        $this->authorizeStockOperation($product);

        $this->stock->createOpeningStock(
            $product->id,
            (int) $request->validated('inventory_location_id'),
            (float) $request->validated('quantity'),
            (float) ($request->validated('unit_cost') ?: 0),
            $request->validated('notes'),
        );

        return redirect()->route('inventory.products.stock-card', $product)->with('status', 'Stok awal berhasil ditambahkan.');
    }

    public function receiveStock(Product $product): View|Response
    {
        $this->authorizeStockOperation($product);

        return $this->renderInventoryView('inventory.stock.receive', [
            'product' => $product->load(['category', 'unit']),
            'locations' => $this->locations->listActive(),
            'suppliers' => $this->suppliers->listActive(),
            'batches' => $this->stock->listActiveBatchesForProduct($product->id),
        ]);
    }

    public function storeReceiveStock(StoreReceiveStockRequest $request, Product $product): RedirectResponse
    {
        $this->authorizeStockOperation($product);

        $this->stock->receiveStock(
            $product->id,
            (int) $request->validated('inventory_location_id'),
            (float) $request->validated('quantity'),
            (float) ($request->validated('unit_cost') ?: 0),
            $request->validated('supplier_id') ? (int) $request->validated('supplier_id') : null,
            $request->validated('notes'),
            $request->batchData(),
        );

        return redirect()->route('inventory.products.stock-card', $product)->with('status', 'Stok berhasil diterima.');
    }

    public function adjustIn(Product $product): View|Response
    {
        $this->authorizeStockOperation($product);

        return $this->renderInventoryView('inventory.stock.adjust-in', [
            'product' => $product->load(['category', 'unit']),
            'locations' => $this->locations->listActive(),
            'batches' => $this->stock->listActiveBatchesForProduct($product->id),
        ]);
    }

    public function storeAdjustIn(StoreAdjustmentRequest $request, Product $product): RedirectResponse
    {
        $this->authorizeStockOperation($product);

        $this->stock->adjustIn(
            $product->id,
            (int) $request->validated('inventory_location_id'),
            (float) $request->validated('quantity'),
            $request->validated('notes'),
            $request->batchData(),
        );

        return redirect()->route('inventory.products.stock-card', $product)->with('status', 'Penyesuaian stok masuk berhasil dilakukan.');
    }

    public function adjustOut(Product $product): View|Response
    {
        $this->authorizeStockOperation($product);

        return $this->renderInventoryView('inventory.stock.adjust-out', [
            'product' => $product->load(['category', 'unit']),
            'locations' => $this->locations->listActive(),
            'batches' => $this->stock->listActiveBatchesForProduct($product->id),
        ]);
    }

    public function storeAdjustOut(StoreAdjustmentRequest $request, Product $product): RedirectResponse
    {
        $this->authorizeStockOperation($product);

        $this->stock->adjustOut(
            $product->id,
            (int) $request->validated('inventory_location_id'),
            (float) $request->validated('quantity'),
            $request->validated('notes'),
            $request->validated('inventory_batch_id') ? (int) $request->validated('inventory_batch_id') : null,
        );

        return redirect()->route('inventory.products.stock-card', $product)->with('status', 'Penyesuaian stok keluar berhasil dilakukan.');
    }

    private function authorizeStockOperation(Product $product): void
    {
        $this->authorize('view', $product);
        $this->authorize('create', InventoryMovement::class);

        abort_unless($product->is_active, 403);
    }
}
