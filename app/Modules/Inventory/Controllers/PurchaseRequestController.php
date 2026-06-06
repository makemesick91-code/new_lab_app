<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\PurchaseRequestRepositoryInterface;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Requests\RejectPurchaseRequestRequest;
use App\Modules\Inventory\Requests\StorePurchaseRequestRequest;
use App\Modules\Inventory\Requests\UpdatePurchaseRequestRequest;
use App\Modules\Inventory\Services\InventoryLocationService;
use App\Modules\Inventory\Services\PurchaseRequestService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PurchaseRequestController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly PurchaseRequestRepositoryInterface $purchaseRequests,
        private readonly PurchaseRequestService $purchaseRequestService,
        private readonly InventoryLocationService $locations,
        private readonly ProductRepositoryInterface $products,
        private readonly BranchContext $branchContext,
    ) {}

    public function index(Request $request): View|Response
    {
        $this->authorize('viewAny', PurchaseRequest::class);

        return $this->renderInventoryView('inventory.purchase-requests.index', [
            'purchaseRequests' => $this->purchaseRequestService->listForBranch(
                $this->branchContext->requireId(),
                $request->all(),
            ),
            'statuses' => PurchaseRequest::STATUSES,
            'filters' => $request->only(['search', 'status', 'date_from', 'date_to']),
        ]);
    }

    public function create(Request $request): View|Response
    {
        $this->authorize('create', PurchaseRequest::class);

        $prefillItem = null;

        if ($request->filled('product_id')) {
            $prefillItem = [
                'product_id' => (int) $request->query('product_id'),
                'inventory_location_id' => $request->filled('inventory_location_id')
                    ? (int) $request->query('inventory_location_id')
                    : '',
                'quantity_requested' => $request->filled('suggested_quantity')
                    ? (float) $request->query('suggested_quantity')
                    : 1,
                'estimated_unit_price' => '',
                'notes' => '',
            ];
        }

        return $this->renderInventoryView('inventory.purchase-requests.create', [
            'locations' => $this->locations->listActive(),
            'products' => $this->products->listActive($this->branchContext->requireId()),
            'prefillItem' => $prefillItem,
        ]);
    }

    public function store(StorePurchaseRequestRequest $request): RedirectResponse
    {
        $this->authorize('create', PurchaseRequest::class);

        $purchaseRequest = $this->purchaseRequestService->createDraft(
            $request->validated(),
            $request->user(),
        );

        return redirect()->route('inventory.purchase-requests.show', $purchaseRequest)
            ->with('status', 'Permintaan pembelian berhasil dibuat.');
    }

    public function show(PurchaseRequest $purchaseRequest): View|Response
    {
        $this->authorize('view', $purchaseRequest);

        $purchaseRequest = $this->purchaseRequests->loadDetails($purchaseRequest);

        return $this->renderInventoryView('inventory.purchase-requests.show', [
            'purchaseRequest' => $purchaseRequest,
        ]);
    }

    public function edit(PurchaseRequest $purchaseRequest): View|Response
    {
        $this->authorize('update', $purchaseRequest);

        $purchaseRequest = $this->purchaseRequests->loadDetails($purchaseRequest);

        return $this->renderInventoryView('inventory.purchase-requests.edit', [
            'purchaseRequest' => $purchaseRequest,
            'locations' => $this->locations->listActive(),
            'products' => $this->products->listActive($this->branchContext->requireId()),
        ]);
    }

    public function update(UpdatePurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('update', $purchaseRequest);

        $purchaseRequest = $this->purchaseRequestService->updateDraft(
            $purchaseRequest,
            $request->validated(),
            $request->user(),
        );

        return redirect()->route('inventory.purchase-requests.show', $purchaseRequest)
            ->with('status', 'Permintaan pembelian berhasil diperbarui.');
    }

    public function submit(PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('submit', $purchaseRequest);

        $purchaseRequest = $this->purchaseRequestService->submit($purchaseRequest, request()->user());

        return redirect()->route('inventory.purchase-requests.show', $purchaseRequest)
            ->with('status', 'Permintaan pembelian berhasil diajukan.');
    }

    public function approve(PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('approve', $purchaseRequest);

        $purchaseRequest = $this->purchaseRequestService->approve($purchaseRequest, request()->user());

        return redirect()->route('inventory.purchase-requests.show', $purchaseRequest)
            ->with('status', 'Permintaan pembelian berhasil disetujui.');
    }

    public function reject(RejectPurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('reject', $purchaseRequest);

        $purchaseRequest = $this->purchaseRequestService->reject(
            $purchaseRequest,
            $request->user(),
            $request->validated('rejection_reason'),
        );

        return redirect()->route('inventory.purchase-requests.show', $purchaseRequest)
            ->with('status', 'Permintaan pembelian ditolak.');
    }

    public function cancel(PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('cancel', $purchaseRequest);

        $purchaseRequest = $this->purchaseRequestService->cancel($purchaseRequest, request()->user());

        return redirect()->route('inventory.purchase-requests.show', $purchaseRequest)
            ->with('status', 'Permintaan pembelian dibatalkan.');
    }
}
