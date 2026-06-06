<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\StockTransferRepositoryInterface;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Requests\CancelStockTransferRequest;
use App\Modules\Inventory\Requests\ReceiveStockTransferRequest;
use App\Modules\Inventory\Requests\ShipStockTransferRequest;
use App\Modules\Inventory\Requests\StoreStockTransferRequest;
use App\Modules\Inventory\Requests\SubmitStockTransferRequest;
use App\Modules\Inventory\Requests\UpdateStockTransferRequest;
use App\Modules\Inventory\Services\InventoryLocationService;
use App\Modules\Inventory\Services\InventoryStockService;
use App\Modules\Inventory\Services\StockTransferService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StockTransferController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly StockTransferRepositoryInterface $transfers,
        private readonly StockTransferService $transferService,
        private readonly InventoryLocationService $locations,
        private readonly ProductRepositoryInterface $products,
        private readonly InventoryStockService $stock,
        private readonly BranchContext $branchContext,
    ) {}

    public function index(Request $request): View|Response
    {
        $this->authorize('viewAny', StockTransfer::class);

        return $this->renderInventoryView('inventory.stock-transfers.index', [
            'stockTransfers' => $this->transfers->paginate(
                $this->branchContext->requireId(),
                $request->all(),
            ),
            'locations' => $this->locations->listActive(),
            'statuses' => StockTransfer::STATUSES,
            'filters' => $request->only([
                'search',
                'source_inventory_location_id',
                'destination_inventory_location_id',
                'status',
                'date_from',
                'date_to',
            ]),
        ]);
    }

    public function create(): View|Response
    {
        $this->authorize('create', StockTransfer::class);

        return $this->renderInventoryView('inventory.stock-transfers.create', [
            'locations' => $this->locations->listActive(),
            'products' => $this->products->listActive($this->branchContext->requireId()),
            'batchOptions' => $this->stock->batchOptionsMatrixForTransfer(),
        ]);
    }

    public function store(StoreStockTransferRequest $request): RedirectResponse
    {
        $this->authorize('create', StockTransfer::class);

        $transfer = $this->transferService->createTransfer(
            (int) $request->validated('source_inventory_location_id'),
            (int) $request->validated('destination_inventory_location_id'),
            $request->validated('items'),
            $request->validated('notes'),
            $request->validated('transfer_date'),
        );

        return redirect()->route('inventory.stock-transfers.show', $transfer)
            ->with('status', 'Transfer stok berhasil dibuat.');
    }

    public function show(StockTransfer $stockTransfer): View|Response
    {
        $this->authorize('view', $stockTransfer);

        $stockTransfer = $this->transfers->loadDetails($stockTransfer);

        $ledgerMovements = $stockTransfer->isInTransit() || $stockTransfer->isReceived()
            ? InventoryMovement::query()
                ->with(['product', 'inventoryLocation', 'inventoryBatch'])
                ->where('branch_id', $stockTransfer->branch_id)
                ->where('reference_type', $stockTransfer->getTable())
                ->where('reference_id', $stockTransfer->id)
                ->orderBy('id')
                ->get()
            : collect();

        return $this->renderInventoryView('inventory.stock-transfers.show', [
            'stockTransfer' => $stockTransfer,
            'ledgerMovements' => $ledgerMovements,
        ]);
    }

    public function edit(StockTransfer $stockTransfer): View|Response
    {
        $this->authorize('update', $stockTransfer);

        $stockTransfer = $this->transfers->loadDetails($stockTransfer);

        return $this->renderInventoryView('inventory.stock-transfers.edit', [
            'stockTransfer' => $stockTransfer,
            'locations' => $this->locations->listActive(),
            'products' => $this->products->listActive($this->branchContext->requireId()),
            'batchOptions' => $this->stock->batchOptionsMatrixForTransfer(),
        ]);
    }

    public function update(UpdateStockTransferRequest $request, StockTransfer $stockTransfer): RedirectResponse
    {
        $this->authorize('update', $stockTransfer);

        $transfer = $this->transferService->updateTransfer(
            $stockTransfer->id,
            (int) $request->validated('source_inventory_location_id'),
            (int) $request->validated('destination_inventory_location_id'),
            $request->validated('items'),
            $request->validated('notes'),
            $request->validated('transfer_date'),
        );

        return redirect()->route('inventory.stock-transfers.show', $transfer)
            ->with('status', 'Transfer stok berhasil diperbarui.');
    }

    public function submit(SubmitStockTransferRequest $request, StockTransfer $stockTransfer): RedirectResponse
    {
        $this->authorize('submit', $stockTransfer);

        $this->transferService->submitTransfer($stockTransfer->id);

        return back()->with('status', 'Transfer stok berhasil diajukan.');
    }

    public function ship(ShipStockTransferRequest $request, StockTransfer $stockTransfer): RedirectResponse
    {
        $this->authorize('ship', $stockTransfer);

        $this->transferService->shipTransfer($stockTransfer->id);

        return back()->with('status', 'Transfer stok berhasil dikirim.');
    }

    public function receive(ReceiveStockTransferRequest $request, StockTransfer $stockTransfer): RedirectResponse
    {
        $this->authorize('receive', $stockTransfer);

        $this->transferService->receiveTransfer($stockTransfer->id);

        return back()->with('status', 'Transfer stok berhasil diterima.');
    }

    public function cancel(CancelStockTransferRequest $request, StockTransfer $stockTransfer): RedirectResponse
    {
        $this->authorize('cancel', $stockTransfer);

        $this->transferService->cancelTransfer($stockTransfer->id, $request->validated('notes'));

        return redirect()->route('inventory.stock-transfers.index')
            ->with('status', 'Transfer stok berhasil dibatalkan.');
    }
}
