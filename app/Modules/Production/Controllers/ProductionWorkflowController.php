<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\LabOrder\Services\LabOrderService;
use App\Modules\Production\Models\ProductionStep;
use App\Modules\Production\Requests\CompleteWorkRequest;
use App\Modules\Production\Requests\PauseWorkRequest;
use App\Modules\Production\Requests\ResumeWorkRequest;
use App\Modules\Production\Requests\SendToQcRequest;
use App\Modules\Production\Requests\StartWorkRequest;
use App\Modules\Production\Services\AssignmentService;
use App\Modules\Production\Services\ProductionStepService;
use App\Modules\Production\Services\ProductionWorkflowService;
use App\Modules\Production\Services\WorkLogService;
use App\Modules\Technician\Services\TechnicianAssignmentEligibility;
use App\Modules\Technician\Services\TechnicianService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductionWorkflowController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ProductionWorkflowService $workflow,
        private readonly AssignmentService $assignmentService,
        private readonly ProductionStepService $stepService,
        private readonly WorkLogService $workLogService,
        private readonly AuditLogService $auditLogService,
        private readonly LabOrderService $labOrderService,
        private readonly ClinicService $clinicService,
        private readonly TechnicianService $technicianService,
        private readonly TechnicianAssignmentEligibility $technicianEligibility,
    ) {}

    public function board(Request $request): View
    {
        $this->authorize('production.viewAny');

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'priority' => $request->string('priority')->toString() ?: null,
            'clinic_id' => $request->integer('clinic_id') ?: null,
            'technician_id' => $request->integer('technician_id') ?: null,
        ];

        return view('production.board', [
            'orders' => $this->assignmentService->board($filters, 10),
            'filters' => $filters,
            'clinics' => $this->clinicService->listAll(),
            'technicians' => $this->technicianService->listAll(),
            'statuses' => LabOrder::STATUSES,
            'priorities' => LabOrder::PRIORITIES,
        ]);
    }

    public function show(LabOrder $labOrder): View
    {
        $this->authorize('production.view', $labOrder);

        return view('production.show', [
            'order' => $this->labOrderService->findDetail($labOrder->id),
            'activeAssignment' => $this->assignmentService->activeForOrder($labOrder->id),
            'assignmentHistory' => $this->assignmentService->historyForOrder($labOrder->id),
            'steps' => $this->stepService->listForLabOrder($labOrder->id),
            'workLogs' => $this->workLogService->forLabOrder($labOrder->id),
            'auditLogs' => $this->auditLogService->paginateForEntity(LabOrder::ENTITY_TYPE, $labOrder->id, 15),
            // Assign/reassign form targets: eligible technicians only (active
            // user account + Technician role). The board filter above keeps
            // listAll() so HISTORICAL assignments stay filterable/readable.
            'technicians' => $this->technicianEligibility->listForAssignment(),
            'holdReasons' => PauseWorkRequest::HOLD_REASONS,
            'stepStatuses' => ProductionStep::STATUSES,
            'attachmentCategories' => Attachment::CATEGORIES,
        ]);
    }

    public function start(StartWorkRequest $request, LabOrder $labOrder): RedirectResponse
    {
        $this->authorize('production.start', $labOrder);
        $this->workflow->startWork($labOrder, $request->validated()['notes'] ?? null);

        return $this->back($labOrder, 'Produksi berhasil dimulai.');
    }

    public function pause(PauseWorkRequest $request, LabOrder $labOrder): RedirectResponse
    {
        $this->authorize('production.pause', $labOrder);
        $data = $request->validated();
        $this->workflow->pauseWork($labOrder, $data['reason'], $data['hold_reason'] ?? null);

        return $this->back($labOrder, 'Produksi berhasil dijeda.');
    }

    public function resume(ResumeWorkRequest $request, LabOrder $labOrder): RedirectResponse
    {
        $this->authorize('production.resume', $labOrder);
        $this->workflow->resumeWork($labOrder, $request->validated()['notes'] ?? null);

        return $this->back($labOrder, 'Produksi berhasil dilanjutkan.');
    }

    public function complete(CompleteWorkRequest $request, LabOrder $labOrder): RedirectResponse
    {
        $this->authorize('production.complete', $labOrder);
        $this->workflow->completeWork($labOrder, $request->validated()['notes'] ?? null);

        return $this->back($labOrder, 'Produksi berhasil diselesaikan.');
    }

    public function sendToQc(SendToQcRequest $request, LabOrder $labOrder): RedirectResponse
    {
        $this->authorize('production.sendToQc', $labOrder);
        $this->workflow->sendToQc($labOrder, $request->validated()['notes'] ?? null);

        return $this->back($labOrder, 'Order berhasil dikirim ke QC.');
    }

    private function back(LabOrder $labOrder, string $message): RedirectResponse
    {
        return redirect()->route('production.show', $labOrder)->with('status', $message);
    }
}
