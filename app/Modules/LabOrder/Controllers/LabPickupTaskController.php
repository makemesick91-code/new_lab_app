<?php

namespace App\Modules\LabOrder\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LabOrder\Models\LabPickupTask;
use App\Modules\LabOrder\Requests\MarkPickupPickedUpRequest;
use App\Modules\LabOrder\Requests\ReceivePickupAtLabRequest;
use App\Modules\LabOrder\Services\LabPickupWorkflowService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * LAB-WORKFLOW-V2 (Phase 2) — courier pickup queue + lab receive.
 *
 * Thin controller; sequencing/ownership/evidence guards live in
 * LabPickupWorkflowService and the state machine (server-side, never UI-only).
 */
class LabPickupTaskController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LabPickupWorkflowService $pickups,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', LabPickupTask::class);

        $scope = $request->string('scope')->toString() ?: 'active';

        $filters = match ($scope) {
            'mine' => ['courier_id' => $request->user()->id, 'active_only' => true],
            'all' => [],
            default => ['active_only' => true],
        };

        if ($status = $request->string('status')->toString()) {
            $filters['status'] = $status;
            unset($filters['active_only']);
        }

        return view('lab-workflow.pickups.index', [
            'tasks' => $this->pickups->queue($filters),
            'scope' => $scope,
            'status' => $status ?: null,
        ]);
    }

    public function show(LabPickupTask $pickupTask): View
    {
        $this->authorize('view', $pickupTask);

        return view('lab-workflow.pickups.show', [
            'task' => $this->pickups->findDetail($pickupTask->id) ?? abort(404),
        ]);
    }

    public function accept(Request $request, LabPickupTask $pickupTask): RedirectResponse
    {
        $this->authorize('accept', $pickupTask);

        $this->pickups->accept($pickupTask, $request->user());

        return back()->with('success', 'Tugas pickup diterima.');
    }

    public function pickedUp(MarkPickupPickedUpRequest $request, LabPickupTask $pickupTask): RedirectResponse
    {
        $this->authorize('progress', $pickupTask);

        $this->pickups->markPickedUp(
            $pickupTask,
            $request->user(),
            $request->file('pickup_photo'),
            $request->validated('notes'),
        );

        return back()->with('success', 'Pickup dikonfirmasi dengan bukti foto.');
    }

    public function startTransit(Request $request, LabPickupTask $pickupTask): RedirectResponse
    {
        $this->authorize('progress', $pickupTask);

        $this->pickups->startTransit($pickupTask, $request->user());

        return back()->with('success', 'Perjalanan ke lab dimulai.');
    }

    public function receive(ReceivePickupAtLabRequest $request, LabPickupTask $pickupTask): RedirectResponse
    {
        $this->authorize('receive', $pickupTask);

        $this->pickups->receiveAtLab(
            $pickupTask,
            $request->user(),
            $request->validated('discrepancy_note'),
        );

        return back()->with('success', 'Model diterima di lab.');
    }
}
