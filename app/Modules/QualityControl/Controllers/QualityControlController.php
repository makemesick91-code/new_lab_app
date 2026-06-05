<?php

namespace App\Modules\QualityControl\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\LabOrder\Services\LabOrderService;
use App\Modules\Production\Services\AssignmentService;
use App\Modules\QualityControl\Models\QualityControlChecklist;
use App\Modules\QualityControl\Models\RemakeRequest;
use App\Modules\QualityControl\Requests\PassQcRequest;
use App\Modules\QualityControl\Requests\RejectQcRequest;
use App\Modules\QualityControl\Requests\StartQcRequest;
use App\Modules\QualityControl\Requests\UploadQcEvidenceRequest;
use App\Modules\QualityControl\Services\ChecklistService;
use App\Modules\QualityControl\Services\QualityControlService;
use App\Modules\QualityControl\Services\QualityWorkflowService;
use App\Modules\QualityControl\Services\RemakeService;
use App\Modules\Technician\Services\TechnicianService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QualityControlController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly QualityControlService $qcService,
        private readonly QualityWorkflowService $workflow,
        private readonly ChecklistService $checklistService,
        private readonly RemakeService $remakeService,
        private readonly AuditLogService $auditLogService,
        private readonly LabOrderService $labOrderService,
        private readonly ClinicService $clinicService,
        private readonly TechnicianService $technicianService,
        private readonly AssignmentService $assignmentService,
    ) {}

    public function queue(Request $request): View
    {
        $this->authorize('qc.viewAny');

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'priority' => $request->string('priority')->toString() ?: null,
            'clinic_id' => $request->integer('clinic_id') ?: null,
            'doctor_id' => $request->integer('doctor_id') ?: null,
            'technician_id' => $request->integer('technician_id') ?: null,
        ];

        return view('quality-control.queue', [
            'orders' => $this->qcService->queue($filters, 10),
            'filters' => $filters,
            'clinics' => $this->clinicService->listAll(),
            'technicians' => $this->technicianService->listAll(),
            'priorities' => LabOrder::PRIORITIES,
        ]);
    }

    public function show(LabOrder $labOrder): View
    {
        $this->authorize('qc.view', $labOrder);

        $activeReview = $this->qcService->findActive($labOrder->id);

        return view('quality-control.show', [
            'order' => $this->labOrderService->findDetail($labOrder->id),
            'activeReview' => $activeReview,
            'checklists' => $activeReview ? $this->checklistService->listFor($activeReview->id) : collect(),
            'history' => $this->qcService->history($labOrder->id),
            'remakeRequests' => $this->remakeService->forLabOrder($labOrder->id),
            'activeAssignment' => $this->assignmentService->activeForOrder($labOrder->id),
            'auditLogs' => $this->auditLogService->paginateForEntity(LabOrder::ENTITY_TYPE, $labOrder->id, 15),
            'checklistResults' => QualityControlChecklist::RESULTS,
            'evidenceCategories' => UploadQcEvidenceRequest::CATEGORIES,
            'remakeReasons' => RemakeRequest::REASONS,
        ]);
    }

    public function start(StartQcRequest $request, LabOrder $labOrder): RedirectResponse
    {
        $this->authorize('qc.start', $labOrder);
        $this->qcService->start($labOrder, $request->validated()['notes'] ?? null);

        return $this->back($labOrder, 'Review QC berhasil dimulai.');
    }

    public function pass(PassQcRequest $request, LabOrder $labOrder): RedirectResponse
    {
        $this->authorize('qc.pass', $labOrder);
        $this->workflow->pass($labOrder, $request->validated()['notes'] ?? null);

        return $this->back($labOrder, 'QC lulus. Order siap untuk pengiriman.');
    }

    public function reject(RejectQcRequest $request, LabOrder $labOrder): RedirectResponse
    {
        $this->authorize('qc.reject', $labOrder);
        $data = $request->validated();
        $this->workflow->reject($labOrder, $data['result'], $data['reason'], $data['notes']);

        return $this->back($labOrder, 'QC ditolak. Order masuk alur perbaikan.');
    }

    public function evidence(UploadQcEvidenceRequest $request, LabOrder $labOrder): RedirectResponse
    {
        $this->authorize('qc.uploadEvidence', $labOrder);
        $this->workflow->uploadEvidence($labOrder, $request->file('file'), $request->validated()['category']);

        return $this->back($labOrder, 'Bukti QC berhasil diunggah.');
    }

    private function back(LabOrder $labOrder, string $message): RedirectResponse
    {
        return redirect()->route('quality-control.show', $labOrder)->with('status', $message);
    }
}
