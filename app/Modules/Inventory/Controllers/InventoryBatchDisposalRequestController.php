<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryBatchDisposalRequest;
use App\Modules\Inventory\Requests\InventoryBatchDisposalFilterRequest;
use App\Modules\Inventory\Requests\RejectInventoryBatchDisposalRequest;
use App\Modules\Inventory\Requests\StoreInventoryBatchDisposalRequest;
use App\Modules\Inventory\Services\InventoryBatchDisposalWorkflowService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class InventoryBatchDisposalRequestController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventoryBatchDisposalWorkflowService $workflow,
    ) {}

    public function index(InventoryBatchDisposalFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', InventoryBatchDisposalRequest::class);

        return $this->renderInventoryView('inventory.batch-disposal-requests.index', [
            'requests' => $this->workflow->paginate($request->filters(), $request->perPage()),
            'filters' => $request->filters(),
        ]);
    }

    public function show(InventoryBatchDisposalRequest $batchDisposalRequest): View|Response
    {
        $this->authorize('view', $batchDisposalRequest);

        $showData = $this->workflow->findForShow($batchDisposalRequest->id);
        abort_if($showData === null, 404);

        return $this->renderInventoryView('inventory.batch-disposal-requests.show', [
            'disposalRequest' => $showData,
        ]);
    }

    public function store(StoreInventoryBatchDisposalRequest $request, InventoryBatch $inventoryBatch): RedirectResponse
    {
        $this->authorize('createForBatch', [InventoryBatchDisposalRequest::class, $inventoryBatch]);

        $disposalRequest = $this->workflow->submitRequest($inventoryBatch, $request->validated());

        return redirect()
            ->route('inventory.batch-disposal-requests.show', $disposalRequest)
            ->with('status', 'Permintaan disposal/adjustment berhasil diajukan. Stok belum berubah — menunggu persetujuan dan finalisasi resmi.');
    }

    public function approve(InventoryBatchDisposalRequest $batchDisposalRequest): RedirectResponse
    {
        $this->authorize('approve', $batchDisposalRequest);

        $this->workflow->approve($batchDisposalRequest);

        return back()->with('status', 'Permintaan disposal/adjustment disetujui. Stok belum berubah — lakukan finalisasi adjustment untuk mencatat pengurangan stok.');
    }

    public function reject(RejectInventoryBatchDisposalRequest $request, InventoryBatchDisposalRequest $batchDisposalRequest): RedirectResponse
    {
        $this->authorize('reject', $batchDisposalRequest);

        $this->workflow->reject($batchDisposalRequest, $request->validated('rejection_reason'));

        return back()->with('status', 'Permintaan disposal/adjustment ditolak.');
    }

    public function finalizeAdjustment(InventoryBatchDisposalRequest $batchDisposalRequest): RedirectResponse
    {
        $this->authorize('finalizeAdjustment', $batchDisposalRequest);

        $this->workflow->finalizeAdjustment($batchDisposalRequest);

        return back()->with('status', 'Finalisasi berhasil — movement ADJUSTMENT_OUT telah dicatat pada ledger.');
    }

    public function cancel(InventoryBatchDisposalRequest $batchDisposalRequest): RedirectResponse
    {
        $this->authorize('cancel', $batchDisposalRequest);

        $this->workflow->cancel($batchDisposalRequest);

        return back()->with('status', 'Permintaan disposal/adjustment dibatalkan.');
    }
}
