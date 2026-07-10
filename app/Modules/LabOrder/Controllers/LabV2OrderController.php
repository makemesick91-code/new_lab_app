<?php

namespace App\Modules\LabOrder\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LabOrder\Models\ExternalLab;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Requests\AnalyzeModelRequest;
use App\Modules\LabOrder\Requests\AssignTechnicianV2Request;
use App\Modules\LabOrder\Requests\ExternalDispatchActionRequest;
use App\Modules\LabOrder\Requests\ProductionStepActionRequest;
use App\Modules\LabOrder\Requests\QcDecisionRequest;
use App\Modules\LabOrder\Services\LabExternalDispatchService;
use App\Modules\LabOrder\Services\LabModelAnalysisService;
use App\Modules\LabOrder\Services\LabV2ProductionService;
use App\Modules\LabOrder\Services\LabV2QualityControlService;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\Technician\Models\Technician;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * LAB-WORKFLOW-V2 (Phase 3) — lab-side V2 pipeline hub.
 *
 * Thin controller: every action delegates to a workflow service, and every
 * transition is re-validated server-side by LabWorkflowStateMachine (status,
 * edge, permission) plus per-service business guards. Route middleware is only
 * the first authorization layer.
 */
class LabV2OrderController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LabModelAnalysisService $analysis,
        private readonly LabV2ProductionService $production,
        private readonly LabV2QualityControlService $qc,
        private readonly LabExternalDispatchService $external,
    ) {}

    public function index(Request $request): View
    {
        $status = $request->string('status')->toString() ?: null;
        $search = $request->string('search')->toString() ?: null;

        $orders = LabOrder::query()
            ->with(['patient:id,name', 'clinic:id,name', 'branch:id,name', 'latestModelAnalysis'])
            ->where('workflow_version', LabOrder::WORKFLOW_V2)
            ->when($status, fn ($q, $v) => $q->where('status', $v))
            ->when($search, function ($q, $v) {
                $term = '%'.mb_strtolower($v).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(order_number) LIKE ?', [$term]));
            })
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('lab-workflow.orders.index', [
            'orders' => $orders,
            'status' => $status,
            'search' => $search,
            'kpis' => $this->pipelineKpis(),
        ]);
    }

    /**
     * Pipeline stage counters (single grouped query, PHP bucketing).
     *
     * @return array<string, int>
     */
    private function pipelineKpis(): array
    {
        $byStatus = LabOrder::query()
            ->where('workflow_version', LabOrder::WORKFLOW_V2)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $sum = fn (array $states) => (int) collect($states)->sum(fn ($s) => (int) ($byStatus[$s] ?? 0));

        return [
            'Menunggu Pickup' => $sum([LabWorkflowState::WAITING_PICKUP]),
            'Menuju Lab' => $sum([LabWorkflowState::PICKUP_ACCEPTED, LabWorkflowState::PICKED_UP, LabWorkflowState::IN_TRANSIT_TO_LAB]),
            'Perlu Analisa' => $sum([LabWorkflowState::RECEIVED_AT_LAB, LabWorkflowState::MODEL_REGISTERED, LabWorkflowState::MODEL_ANALYSIS_PENDING]),
            'Produksi Internal' => $sum([
                LabWorkflowState::INTERNAL_APPROVED, LabWorkflowState::TECHNICIAN_ASSIGNMENT_PENDING, LabWorkflowState::TECHNICIAN_ASSIGNED,
                LabWorkflowState::STEP_1_BLOCKOUT_DUPLICATE, LabWorkflowState::STEP_1_COMPLETED,
                LabWorkflowState::STEP_2_TEETH_SETUP, LabWorkflowState::STEP_2_COMPLETED,
                LabWorkflowState::STEP_3_PROCESSING, LabWorkflowState::STEP_3_COMPLETED,
                LabWorkflowState::STEP_4_FITTING_POLISH, LabWorkflowState::STEP_4_COMPLETED,
                LabWorkflowState::QC_FAILED, LabWorkflowState::REWORK_REQUIRED,
            ]),
            'QC Pending' => $sum([LabWorkflowState::QC_PENDING]),
            'Lab Eksternal' => $sum([
                LabWorkflowState::EXTERNAL_LAB_REQUIRED, LabWorkflowState::EXTERNAL_LAB_PREPARATION,
                LabWorkflowState::EXTERNAL_LAB_SENT, LabWorkflowState::EXTERNAL_LAB_IN_PROGRESS,
                LabWorkflowState::EXTERNAL_LAB_RETURNED, LabWorkflowState::EXTERNAL_LAB_RESULT_REVIEW,
            ]),
            'Pengiriman' => $sum([
                LabWorkflowState::MODEL_DONE, LabWorkflowState::DELIVERY_PENDING, LabWorkflowState::COURIER_NOTIFIED,
                LabWorkflowState::DELIVERY_ACCEPTED, LabWorkflowState::LAB_HANDOVER_PENDING,
                LabWorkflowState::PRE_DELIVERY_PHOTO_CAPTURED, LabWorkflowState::COURIER_SIGNATURE_CAPTURED,
                LabWorkflowState::READY_FOR_TRANSIT_TO_BRANCH, LabWorkflowState::IN_TRANSIT_TO_BRANCH,
                LabWorkflowState::ARRIVED_AT_BRANCH, LabWorkflowState::RECIPIENT_SIGNATURE_CAPTURED,
                LabWorkflowState::DELIVERY_LOCATION_PHOTO_CAPTURED,
            ]),
            'Terkirim' => $sum([LabWorkflowState::DELIVERED]),
        ];
    }

    public function show(LabOrder $labV2Order): View
    {
        abort_unless($labV2Order->isV2Workflow(), 404);

        $labV2Order->load([
            'items.labService', 'patient', 'clinic', 'branch',
            'statusLogs.changedBy', 'workflowEvidence',
            'modelAnalyses.externalLab', 'modelAnalyses.analyst',
            'externalDispatches.externalLab',
            'assignments.technician', 'productionSteps',
            'qualityControls', 'pickupTask.courier', 'deliveryTask.courier',
        ]);

        return view('lab-workflow.orders.show', [
            'order' => $labV2Order,
            'technicians' => Technician::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'externalLabs' => ExternalLab::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'reworkTargets' => LabWorkflowState::REWORK_TARGETS,
            'productionSteps' => array_keys(LabWorkflowState::V2_PRODUCTION_STEPS),
        ]);
    }

    public function registerModel(Request $request, LabOrder $labV2Order): RedirectResponse
    {
        $this->analysis->registerModel($labV2Order, $request->user());

        return back()->with('success', 'Model terdaftar dan siap dianalisa.');
    }

    public function analyze(AnalyzeModelRequest $request, LabOrder $labV2Order): RedirectResponse
    {
        $this->analysis->decide($labV2Order, $request->validated(), $request->user());

        return back()->with('success', 'Keputusan analisa dicatat.');
    }

    public function assignTechnician(AssignTechnicianV2Request $request, LabOrder $labV2Order): RedirectResponse
    {
        $this->production->assignTechnician(
            $labV2Order,
            (int) $request->validated('technician_id'),
            $request->user(),
            $request->validated('notes'),
        );

        return back()->with('success', 'Teknisi ditugaskan. Produksi dapat dimulai.');
    }

    public function startStep(ProductionStepActionRequest $request, LabOrder $labV2Order): RedirectResponse
    {
        $this->production->startStep($labV2Order, $request->validated('step'), $request->user(), $request->validated('notes'));

        return back()->with('success', 'Step produksi dimulai.');
    }

    public function completeStep(ProductionStepActionRequest $request, LabOrder $labV2Order): RedirectResponse
    {
        $this->production->completeStep($labV2Order, $request->validated('step'), $request->user(), $request->validated('notes'));

        return back()->with('success', 'Step produksi selesai.');
    }

    public function sendToQc(Request $request, LabOrder $labV2Order): RedirectResponse
    {
        $this->production->sendToQc($labV2Order, $request->user());

        return back()->with('success', 'Pekerjaan dikirim ke Quality Control.');
    }

    public function qcPass(QcDecisionRequest $request, LabOrder $labV2Order): RedirectResponse
    {
        $this->qc->pass($labV2Order, $request->validated('notes'), $request->user());

        return back()->with('success', 'QC lulus — model selesai.');
    }

    public function qcFail(QcDecisionRequest $request, LabOrder $labV2Order): RedirectResponse
    {
        $this->qc->fail(
            $labV2Order,
            (string) $request->validated('reason'),
            $request->validated('target_step'),
            $request->user(),
        );

        return back()->with('success', 'QC gagal dicatat — produksi diulang pada step target.');
    }

    public function externalDispatch(ExternalDispatchActionRequest $request, LabOrder $labV2Order): RedirectResponse
    {
        $this->external->createDispatch($labV2Order, $request->validated(), $request->user());

        return back()->with('success', 'Pengiriman lab eksternal disiapkan.');
    }

    public function externalSent(ExternalDispatchActionRequest $request, LabOrder $labV2Order): RedirectResponse
    {
        $this->external->markSent($labV2Order, $request->validated(), $request->user());

        return back()->with('success', 'Model tercatat terkirim ke lab eksternal.');
    }

    public function externalInProgress(Request $request, LabOrder $labV2Order): RedirectResponse
    {
        $this->external->markInProgress($labV2Order, $request->user());

        return back()->with('success', 'Status lab eksternal: sedang dikerjakan.');
    }

    public function externalReturned(ExternalDispatchActionRequest $request, LabOrder $labV2Order): RedirectResponse
    {
        $this->external->markReturned($labV2Order, $request->validated('notes'), $request->user());

        return back()->with('success', 'Model kembali — masuk review hasil.');
    }

    public function externalReview(ExternalDispatchActionRequest $request, LabOrder $labV2Order): RedirectResponse
    {
        $this->external->review(
            $labV2Order,
            (string) $request->validated('result'),
            $request->validated('notes'),
            $request->user(),
        );

        return back()->with('success', 'Review hasil lab eksternal dicatat.');
    }
}
