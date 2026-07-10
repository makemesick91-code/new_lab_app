<?php

namespace App\Modules\LabOrder\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LabOrder\Models\LabDeliveryTask;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Requests\CompleteDeliveryRequest;
use App\Modules\LabOrder\Requests\SubmitDeliveryHandoverRequest;
use App\Modules\LabOrder\Services\LabDeliveryWorkflowService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * LAB-WORKFLOW-V2 (Phase 4) — courier delivery queue with mandatory proof gates.
 *
 * Thin controller; the photo/signature gates are enforced in
 * LabDeliveryWorkflowService + the state machine (server-side, never UI-only).
 */
class LabDeliveryTaskController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LabDeliveryWorkflowService $deliveries,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', LabDeliveryTask::class);

        $scope = $request->string('scope')->toString() ?: 'active';

        $tasks = LabDeliveryTask::query()
            ->with(['labOrder:id,order_number,due_date,priority,status', 'branch:id,code,name,address', 'courier:id,name'])
            ->when($scope === 'mine', fn ($q) => $q->where('courier_id', $request->user()->id)->whereIn('status', LabDeliveryTask::ACTIVE_STATUSES))
            ->when($scope === 'active', fn ($q) => $q->whereIn('status', LabDeliveryTask::ACTIVE_STATUSES))
            ->orderByRaw("case status when 'PENDING' then 0 when 'ACCEPTED' then 1 when 'READY_FOR_TRANSIT' then 2 when 'IN_TRANSIT' then 3 when 'ARRIVED' then 4 else 5 end")
            ->orderBy('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('lab-workflow.deliveries.index', [
            'tasks' => $tasks,
            'scope' => $scope,
        ]);
    }

    public function show(LabDeliveryTask $deliveryTask): View
    {
        $this->authorize('view', $deliveryTask);

        return view('lab-workflow.deliveries.show', [
            'task' => $deliveryTask->load([
                'labOrder.items.labService',
                'labOrder.workflowEvidence',
                'branch',
                'courier',
            ]),
        ]);
    }

    /** Lab side: create/activate the delivery task after MODEL_DONE. */
    public function store(Request $request, LabOrder $labV2Order): RedirectResponse
    {
        $this->authorize('create', LabDeliveryTask::class);

        $task = $this->deliveries->createTask($labV2Order, $request->user());

        return redirect()
            ->route('lab-delivery-tasks.show', $task)
            ->with('success', 'Tugas pengiriman dibuat. Kurir dapat menerima tugas.');
    }

    public function accept(Request $request, LabDeliveryTask $deliveryTask): RedirectResponse
    {
        $this->authorize('accept', $deliveryTask);

        $this->deliveries->accept($deliveryTask, $request->user());

        return back()->with('success', 'Tugas pengiriman diterima.');
    }

    public function submitHandover(SubmitDeliveryHandoverRequest $request, LabDeliveryTask $deliveryTask): RedirectResponse
    {
        $this->authorize('progress', $deliveryTask);

        $this->deliveries->submitHandoverProof(
            $deliveryTask,
            $request->user(),
            $request->file('handover_photo'),
            (string) $request->validated('courier_signature'),
        );

        return back()->with('success', 'Bukti serah terima lengkap — siap berangkat ke cabang.');
    }

    public function startTransit(Request $request, LabDeliveryTask $deliveryTask): RedirectResponse
    {
        $this->authorize('progress', $deliveryTask);

        $this->deliveries->startTransit($deliveryTask, $request->user());

        return back()->with('success', 'Perjalanan ke cabang dimulai.');
    }

    public function markArrived(Request $request, LabDeliveryTask $deliveryTask): RedirectResponse
    {
        $this->authorize('progress', $deliveryTask);

        $this->deliveries->markArrived($deliveryTask, $request->user());

        return back()->with('success', 'Tiba di cabang. Lengkapi bukti serah terima penerima.');
    }

    public function complete(CompleteDeliveryRequest $request, LabDeliveryTask $deliveryTask): RedirectResponse
    {
        $this->authorize('complete', $deliveryTask);

        $this->deliveries->completeDelivery(
            $deliveryTask,
            $request->user(),
            (string) $request->validated('recipient_signature'),
            $request->file('location_photo'),
            [
                'recipient_name' => (string) $request->validated('recipient_name'),
                'recipient_role' => $request->validated('recipient_role'),
                'notes' => $request->validated('notes'),
            ],
        );

        return back()->with('success', 'Model terkirim — semua bukti serah terima tersimpan.');
    }
}
