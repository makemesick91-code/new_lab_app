<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Interfaces\LabOrderRepositoryInterface;
use App\Modules\LabOrder\Interfaces\LabPickupTaskRepositoryInterface;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabPickupTask;
use App\Modules\LabOrder\Models\LabWorkflowEvidence;
use App\Modules\LabOrder\Support\LabWorkflowNotificationDestinationResolver;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\LabService\Models\LabService;
use App\Modules\Patient\Models\Patient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LAB-WORKFLOW-V2 (Phase 2) — Cabang (branch) lab request workflow.
 *
 * The V2 creation entry point. A branch nurse creates a DRAFT V2 order with
 * SPK + model photos, then submits it for pickup (DRAFT -> WAITING_PICKUP via
 * the state machine) which idempotently creates the courier pickup task.
 * The order's branch is ALWAYS resolved server-side (BranchContext) — never
 * from request input.
 */
class LabWorkflowRequestService
{
    public function __construct(
        private readonly LabOrderRepositoryInterface $labOrders,
        private readonly LabPickupTaskRepositoryInterface $pickupTasks,
        private readonly OrderNumberGeneratorService $orderNumbers,
        private readonly StatusLogService $statusLogs,
        private readonly AuditLogService $auditLogs,
        private readonly LabWorkflowEvidenceService $evidence,
        private readonly LabWorkflowStateMachine $stateMachine,
        private readonly BranchContext $branchContext,
        private readonly LabWorkflowNotificationService $notifications,
    ) {}

    /**
     * Bounded local-search catalog size for the patient dropdown (same
     * local-catalog pattern as the Inventory product select — never the whole
     * table into HTML).
     */
    public const PATIENT_OPTION_LIMIT = 500;

    /** Evidence types a branch actor may upload in the request stage. */
    public const BRANCH_EVIDENCE_TYPES = [
        LabWorkflowEvidence::TYPE_SPK_PHOTO,
        LabWorkflowEvidence::TYPE_MODEL_PHOTO_BRANCH,
    ];

    public function listForActiveBranch(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->labOrders->paginateV2ForBranch($this->branchContext->requireId(), $filters, min($perPage, 100));
    }

    public function findDetail(int $id): ?LabOrder
    {
        return $this->labOrders->findDetailById($id);
    }

    /**
     * Create a V2 DRAFT order for the active branch, then attach the mandatory
     * SPK + model photos. The order is created transactionally; photo storage
     * runs after (a DRAFT without complete photos simply cannot be submitted).
     *
     * @param  array<string, mixed>  $data
     */
    public function createDraft(array $data, UploadedFile $spkPhoto, UploadedFile $modelPhoto, User $actor): LabOrder
    {
        $branchId = $this->branchContext->requireId();

        $this->assertRmeBranch($branchId);

        $order = $this->createV2Draft($data, $branchId, $actor);

        $this->evidence->storePhoto($order, LabWorkflowEvidence::TYPE_SPK_PHOTO, $spkPhoto, $actor);
        $this->evidence->storePhoto($order, LabWorkflowEvidence::TYPE_MODEL_PHOTO_BRANCH, $modelPhoto, $actor);

        return $order->refresh();
    }

    /**
     * Core V2 order creation (also used by the RME candidate conversion path
     * once V2 is active). Bypasses the legacy-creation guard by design: this
     * IS the V2 entry point.
     *
     * @param  array<string, mixed>  $data
     */
    public function createV2Draft(array $data, int $branchId, ?User $actor = null): LabOrder
    {
        $actor = $actor ?? auth()->user();

        return DB::transaction(function () use ($data, $branchId, $actor) {
            $orderDate = $data['order_date'] ?? now()->toDateString();

            $order = $this->labOrders->create([
                'order_number' => $this->orderNumbers->generate($orderDate),
                'branch_id' => $branchId,
                // Legacy clinic master reference — nullable since the Klinik of a
                // V2 branch request is the canonical branch_id (Cabang RME).
                'clinic_id' => $data['clinic_id'] ?? null,
                'doctor_id' => $data['doctor_id'],
                'patient_id' => $data['patient_id'] ?? null,
                'medical_record_number' => $data['medical_record_number'] ?? null,
                'order_date' => $orderDate,
                'due_date' => $data['due_date'] ?? null,
                'priority' => $data['priority'] ?? 'NORMAL',
                'status' => LabWorkflowState::DRAFT,
                'workflow_version' => LabOrder::WORKFLOW_V2,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $this->labOrders->syncItems($order, $this->mapItems($data['items'] ?? []));

            $this->statusLogs->log($order->id, null, LabWorkflowState::DRAFT, 'Permintaan lab cabang dibuat (Workflow V2)', $actor);

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $order->id,
                AuditLog::ACTION_CREATE,
                null,
                ['order_number' => $order->order_number, 'workflow_version' => LabOrder::WORKFLOW_V2, 'branch_id' => $branchId],
                $actor,
            );

            return $order->refresh();
        });
    }

    /**
     * Klinik = Cabang RME: a branch lab request may only be created from an
     * active RME-enabled branch (service-level re-assertion of the FormRequest
     * rule — defense in depth, per the ENT-1 3-layer authorization baseline).
     */
    private function assertRmeBranch(int $branchId): void
    {
        $branch = Branch::query()->whereKey($branchId)->first();

        if ($branch === null || ! $branch->is_active || ! $branch->is_rme_enabled) {
            throw ValidationException::withMessages([
                'branch_id' => 'Permintaan lab hanya dapat dibuat dari Cabang RME aktif.',
            ]);
        }
    }

    /**
     * Option catalogs for the create form, scoped server-side to the active
     * Cabang RME so the searchable dropdowns can never present a foreign
     * branch's patients or doctors. Returns safe display fields only — never
     * KTP/NIK, phone, address, or notes.
     *
     * @return array{
     *     branch: Branch|null,
     *     patients: Collection<int, object>,
     *     doctors: Collection<int, object>,
     *     labServices: Collection<int, object>,
     * }
     */
    public function formOptionsForActiveBranch(): array
    {
        $branch = $this->branchContext->branch();

        $isRmeBranch = $branch !== null && $branch->is_active && $branch->is_rme_enabled;

        if (! $isRmeBranch) {
            return [
                'branch' => null,
                'patients' => collect(),
                'doctors' => collect(),
                'labServices' => collect(),
            ];
        }

        $patients = Patient::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('branch_id', $branch->id)->orWhereNull('branch_id'))
            ->orderBy('name')
            ->limit(self::PATIENT_OPTION_LIMIT)
            ->get(['id', 'name', 'medical_record_number'])
            ->map(fn (Patient $patient) => (object) [
                'id' => $patient->id,
                'code' => $patient->medical_record_number,
                'name' => $patient->name,
            ]);

        $doctors = Doctor::query()
            ->where('is_active', true)
            ->where(function ($query) use ($branch) {
                $query->where('branch_id', $branch->id)
                    ->orWhereHas('branches', fn ($q) => $q->where('mst_branches.id', $branch->id))
                    ->orWhere(fn ($q) => $q->whereNull('branch_id')->whereDoesntHave('branches'));
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Doctor $doctor) => (object) [
                'id' => $doctor->id,
                'code' => null,
                'name' => $doctor->name,
            ]);

        $labServices = LabService::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'price'])
            ->map(fn (LabService $service) => (object) [
                'id' => $service->id,
                'code' => $service->code,
                'name' => $service->name,
            ]);

        return [
            'branch' => $branch,
            'patients' => $patients,
            'doctors' => $doctors,
            'labServices' => $labServices,
        ];
    }

    /**
     * Upload / replace-forbidden additional branch evidence on a DRAFT order.
     */
    public function uploadBranchEvidence(LabOrder $order, string $type, UploadedFile $file, User $actor): LabWorkflowEvidence
    {
        $this->assertV2BranchOrder($order);

        if (! in_array($type, self::BRANCH_EVIDENCE_TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => 'Jenis bukti ini tidak dapat diunggah dari cabang.',
            ]);
        }

        if ((string) $order->status !== LabWorkflowState::DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Foto SPK/model hanya dapat diunggah selama order masih draft.',
            ]);
        }

        return $this->evidence->storePhoto($order, $type, $file, $actor);
    }

    /**
     * Submit for pickup: mandatory SPK + model photo, then DRAFT ->
     * WAITING_PICKUP and idempotent pickup-task creation.
     */
    public function submitForPickup(LabOrder $order, User $actor): LabPickupTask
    {
        $this->assertV2BranchOrder($order);

        if (! $this->evidence->has($order, LabWorkflowEvidence::TYPE_SPK_PHOTO)
            || ! $this->evidence->has($order, LabWorkflowEvidence::TYPE_MODEL_PHOTO_BRANCH)) {
            throw ValidationException::withMessages([
                'evidence' => 'Foto SPK dan foto model wajib diunggah sebelum permintaan pickup.',
            ]);
        }

        $task = DB::transaction(function () use ($order, $actor) {
            // State machine re-validates edge/permission/branch under its own row lock.
            $this->stateMachine->transition($order, LabWorkflowState::WAITING_PICKUP, $actor, [
                'reason' => 'Model siap dijemput kurir',
            ]);

            return $this->pickupTasks->firstOrCreateForOrder($order, (int) $order->branch_id, $actor);
        });

        // Post-commit, failure-safe in-app notification (never breaks the flow).
        $this->notifications->notifyPermissionHoldersRouted(
            ['manage_lab_pickups'],
            'Tugas pickup baru',
            "Order {$order->order_number} menunggu penjemputan dari cabang.",
            $order,
            LabWorkflowNotificationDestinationResolver::EVENT_PICKUP_TASK,
            ['pickup_task_id' => $task->id],
        );

        return $task;
    }

    /** Branch actors can only touch V2 orders of their own active branch. */
    private function assertV2BranchOrder(LabOrder $order): void
    {
        if (! $order->isV2Workflow()) {
            throw ValidationException::withMessages([
                'workflow' => 'Order ini bukan Lab Workflow V2.',
            ]);
        }

        if ($order->branch_id !== null
            && (int) $order->branch_id !== $this->branchContext->requireId()) {
            throw ValidationException::withMessages([
                'workflow' => 'Order milik cabang lain tidak dapat diproses.',
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function mapItems(array $items): array
    {
        return array_map(function (array $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);

            return [
                'id' => $item['id'] ?? null,
                'lab_service_id' => $item['lab_service_id'],
                'tooth_number' => $item['tooth_number'] ?? null,
                'shade_color_text' => $item['shade_color_text'] ?? null,
                'material_text' => $item['material_text'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => round($quantity * $unitPrice, 2),
                'notes' => $item['notes'] ?? null,
            ];
        }, $items);
    }
}
