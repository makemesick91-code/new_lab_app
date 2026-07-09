<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Services\PurchaseRequestService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * FIX-PRE-68-45 Scope G — branch Purchase Request workflow board.
 *
 * Read-only board for the "Kepala Cabang → Admin Warehouse" flow: the branch head
 * raises PRs (Reguler / Darurat) and the warehouse processes them. Everything is
 * scoped to the caller's active branch (BranchContext), so a Kepala Cabang pinned
 * to their branch sees only their own requests. This page never creates a Purchase
 * Order — PO creation stays gated by PurchaseOrderPolicy::create (which the Kepala
 * Cabang role deliberately cannot satisfy).
 */
class PurchaseRequestWorkflowController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PurchaseRequestService $purchaseRequests,
        private readonly BranchContext $branchContext,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', PurchaseRequest::class);

        $user = auth()->user();
        $branchId = $this->branchContext->requireId();

        return view('inventory.purchase-requests.workflow', [
            'board' => $this->purchaseRequests->branchWorkflowBoard($branchId),
            'canCreatePr' => $user?->can('create', PurchaseRequest::class) ?? false,
            // "Process" = approve/reject a submitted branch PR (Admin Warehouse).
            'canProcessPr' => $user?->can('approve_inventory_purchase_request') ?? false,
        ]);
    }
}
