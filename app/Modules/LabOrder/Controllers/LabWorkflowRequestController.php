<?php

namespace App\Modules\LabOrder\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Requests\StoreLabWorkflowRequestRequest;
use App\Modules\LabOrder\Requests\UploadLabWorkflowEvidenceRequest;
use App\Modules\LabOrder\Services\LabWorkflowRequestService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * LAB-WORKFLOW-V2 (Phase 2) — Cabang (branch) lab request workspace.
 *
 * Thin controller: all business rules live in LabWorkflowRequestService.
 * Routes are gated by permission:create_lab_branch_requests; every mutation is
 * additionally branch-guarded inside the service (never trusts request branch).
 */
class LabWorkflowRequestController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LabWorkflowRequestService $requests,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ];

        return view('lab-workflow.requests.index', [
            'orders' => $this->requests->listForActiveBranch($filters),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        // Klinik = Cabang RME: all option catalogs are resolved and scoped
        // server-side to the active RME branch (never from request input).
        return view('lab-workflow.requests.create', $this->requests->formOptionsForActiveBranch());
    }

    public function store(StoreLabWorkflowRequestRequest $request): RedirectResponse
    {
        $order = $this->requests->createDraft(
            $request->safe()->except(['spk_photo', 'model_photo']),
            $request->file('spk_photo'),
            $request->file('model_photo'),
            $request->user(),
        );

        return redirect()
            ->route('lab-workflow-requests.show', $order)
            ->with('success', 'Permintaan lab dibuat. Periksa foto lalu kirim permintaan pickup.');
    }

    public function show(LabOrder $labWorkflowRequest): View
    {
        $order = $this->requests->findDetail($labWorkflowRequest->id) ?? abort(404);

        // Branch isolation for the read side: non-V2 or foreign-branch orders 404.
        abort_unless($order->isV2Workflow(), 404);
        abort_if(
            $order->branch_id !== null
            && (int) $order->branch_id !== app(BranchContext::class)->requireId(),
            404,
        );

        return view('lab-workflow.requests.show', [
            'order' => $order->load(['workflowEvidence.uploader', 'pickupTask.courier']),
        ]);
    }

    public function storeEvidence(UploadLabWorkflowEvidenceRequest $request, LabOrder $labWorkflowRequest): RedirectResponse
    {
        $this->requests->uploadBranchEvidence(
            $labWorkflowRequest,
            $request->validated('type'),
            $request->file('file'),
            $request->user(),
        );

        return back()->with('success', 'Foto bukti berhasil diunggah.');
    }

    public function submitPickup(Request $request, LabOrder $labWorkflowRequest): RedirectResponse
    {
        $this->requests->submitForPickup($labWorkflowRequest, $request->user());

        return back()->with('success', 'Permintaan pickup dikirim. Menunggu kurir.');
    }
}
