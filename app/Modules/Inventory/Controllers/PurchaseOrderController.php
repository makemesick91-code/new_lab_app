<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\PurchaseOrderRepositoryInterface;
use App\Modules\Inventory\Interfaces\SupplierRepositoryInterface;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Requests\StorePurchaseOrderRequest;
use App\Modules\Inventory\Requests\UpdatePurchaseOrderRequest;
use App\Modules\Inventory\Services\InventoryLocationService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly PurchaseOrderRepositoryInterface $purchaseOrders,
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly InventoryLocationService $locations,
        private readonly ProductRepositoryInterface $products,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly BranchContext $branchContext,
    ) {}

    public function index(Request $request): View|Response
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $branchId = $this->branchContext->requireId();

        return $this->renderInventoryView('inventory.purchase-orders.index', [
            'purchaseOrders' => $this->purchaseOrderService->listForBranch($branchId, $request->all()),
            'statuses' => PurchaseOrder::STATUSES,
            'suppliers' => $this->suppliers->listActive($branchId),
            'filters' => $request->only([
                'search',
                'status',
                'supplier_id',
                'purchase_request_id',
                'date_from',
                'date_to',
            ]),
        ]);
    }

    public function create(Request $request): View|Response|RedirectResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $branchId = $this->branchContext->requireId();
        $purchaseRequest = null;
        $prefillItems = [];

        if ($request->filled('purchase_request_id')) {
            try {
                $purchaseRequest = $this->purchaseOrderService->findEligiblePurchaseRequestForCreate(
                    (int) $request->query('purchase_request_id'),
                );
                $prefillItems = $this->purchaseOrderService->buildPrefillItemsFromPurchaseRequest($purchaseRequest);
            } catch (ValidationException $exception) {
                return redirect()
                    ->route('inventory.purchase-orders.create')
                    ->withErrors($exception->errors());
            }
        }

        return $this->renderInventoryView('inventory.purchase-orders.create', [
            'locations' => $this->locations->listActive(),
            'products' => $this->products->listActive($branchId),
            'suppliers' => $this->suppliers->listActive($branchId),
            'purchaseRequest' => $purchaseRequest,
            'prefillItems' => $prefillItems,
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): RedirectResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $validated = $request->validated();

        if (! empty($validated['purchase_request_id'])) {
            $purchaseRequest = $this->purchaseOrderService->findEligiblePurchaseRequestForCreate(
                (int) $validated['purchase_request_id'],
            );

            $purchaseOrder = $this->purchaseOrderService->createDraftFromPurchaseRequest(
                $purchaseRequest,
                $validated,
                $request->user(),
            );
        } else {
            $purchaseOrder = $this->purchaseOrderService->createDraft(
                $validated,
                $request->user(),
            );
        }

        return redirect()->route('inventory.purchase-orders.show', $purchaseOrder)
            ->with('status', 'Pesanan pembelian berhasil dibuat.');
    }

    public function show(PurchaseOrder $purchaseOrder): View|Response
    {
        $this->authorize('view', $purchaseOrder);

        $purchaseOrder = $this->purchaseOrders->findForBranch(
            $this->branchContext->requireId(),
            $purchaseOrder->id,
        ) ?? abort(404);

        return $this->renderInventoryView('inventory.purchase-orders.show', [
            'purchaseOrder' => $purchaseOrder,
        ]);
    }

    public function supplierPdf(PurchaseOrder $purchaseOrder, Supplier $supplier): Response
    {
        $this->authorize('view', $purchaseOrder);

        $branchId = $this->branchContext->requireId();

        $purchaseOrder = $this->purchaseOrders->findForBranch($branchId, $purchaseOrder->id) ?? abort(404);

        // Branch ownership of the supplier is enforced server-side; a supplier
        // from another branch (or one with no line on this PO) must never yield
        // a PDF that could leak or fabricate data.
        abort_if($supplier->branch_id !== $branchId, 404);

        try {
            $data = $this->purchaseOrderService->buildSupplierPdfData($purchaseOrder, $supplier);
        } catch (ValidationException) {
            abort(404);
        }

        $filename = sprintf(
            '%s-%s.pdf',
            $purchaseOrder->purchase_order_number,
            Str::slug($supplier->name) ?: 'supplier',
        );

        return Pdf::loadView('inventory.purchase-orders.supplier-pdf', $data)->download($filename);
    }

    public function edit(PurchaseOrder $purchaseOrder): View|Response
    {
        $this->authorize('update', $purchaseOrder);

        $branchId = $this->branchContext->requireId();
        $purchaseOrder = $this->purchaseOrders->findForBranch($branchId, $purchaseOrder->id) ?? abort(404);

        return $this->renderInventoryView('inventory.purchase-orders.edit', [
            'purchaseOrder' => $purchaseOrder,
            'locations' => $this->locations->listActive(),
            'products' => $this->products->listActive($branchId),
            'suppliers' => $this->suppliers->listActive($branchId),
        ]);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('update', $purchaseOrder);

        $purchaseOrder = $this->purchaseOrderService->updateDraft(
            $purchaseOrder,
            $request->validated(),
            $request->user(),
        );

        return redirect()->route('inventory.purchase-orders.show', $purchaseOrder)
            ->with('status', 'Pesanan pembelian berhasil diperbarui.');
    }

    public function submit(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('submit', $purchaseOrder);

        $purchaseOrder = $this->purchaseOrderService->submit($purchaseOrder, request()->user());

        return redirect()->route('inventory.purchase-orders.show', $purchaseOrder)
            ->with('status', 'Pesanan pembelian berhasil diajukan.');
    }

    public function approve(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('approve', $purchaseOrder);

        $purchaseOrder = $this->purchaseOrderService->approve($purchaseOrder, request()->user());

        return redirect()->route('inventory.purchase-orders.show', $purchaseOrder)
            ->with('status', 'Pesanan pembelian berhasil disetujui.');
    }

    public function send(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('send', $purchaseOrder);

        $purchaseOrder = $this->purchaseOrderService->markAsSent($purchaseOrder, request()->user());

        return redirect()->route('inventory.purchase-orders.show', $purchaseOrder)
            ->with('status', 'Pesanan pembelian berhasil dikirim ke supplier.');
    }

    public function cancel(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('cancel', $purchaseOrder);

        $purchaseOrder = $this->purchaseOrderService->cancel($purchaseOrder, request()->user());

        return redirect()->route('inventory.purchase-orders.show', $purchaseOrder)
            ->with('status', 'Pesanan pembelian berhasil dibatalkan.');
    }
}
