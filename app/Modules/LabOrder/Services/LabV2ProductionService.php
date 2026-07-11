<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\Production\Interfaces\AssignmentRepositoryInterface;
use App\Modules\Production\Interfaces\ProductionStepRepositoryInterface;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Production\Models\ProductionStep;
use App\Modules\Production\Models\WorkLog;
use App\Modules\Production\Services\WorkLogService;
use App\Modules\Technician\Services\TechnicianAssignmentEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LAB-WORKFLOW-V2 (Phase 3) — internal production path.
 *
 * Technician assignment + the four canonical production steps, driven by the
 * V2 state machine (which enforces the step sequence via its matrix). Reuses
 * the legacy assignment / step / work-log tables with identical status
 * semantics so existing production reports keep counting V2 rows.
 *
 * V2 tightens the legacy actor rule: a step can only be progressed by the
 * assigned technician's user (or a manage_production supervisor) — the legacy
 * non-technician loophole is deliberately closed here.
 */
class LabV2ProductionService
{
    public function __construct(
        private readonly LabWorkflowStateMachine $stateMachine,
        private readonly AssignmentRepositoryInterface $assignments,
        private readonly ProductionStepRepositoryInterface $steps,
        private readonly WorkLogService $workLogs,
        private readonly AuditLogService $auditLogs,
        private readonly LabWorkflowNotificationService $notifications,
        private readonly TechnicianAssignmentEligibility $technicianEligibility,
    ) {}

    /**
     * Assign a technician to an INTERNAL_APPROVED V2 order. Chains
     * INTERNAL_APPROVED -> TECHNICIAN_ASSIGNMENT_PENDING -> TECHNICIAN_ASSIGNED
     * and seeds the four V2 step rows.
     */
    public function assignTechnician(LabOrder $order, int $technicianId, ?User $actor = null, ?string $notes = null): LabOrderAssignment
    {
        $actor = $actor ?? auth()->user();

        // Assignment target must be an ACTIVE user account holding the
        // canonical Technician role — a crafted technician_id is re-validated
        // here, never trusted from the request.
        $technician = $this->technicianEligibility->assertAssignable($technicianId);

        if ($this->assignments->findActiveByLabOrder($order->id) !== null) {
            throw ValidationException::withMessages([
                'technician_id' => 'Order ini sudah memiliki penugasan teknisi aktif.',
            ]);
        }

        $assignment = DB::transaction(function () use ($order, $technician, $actor, $notes) {
            $this->stateMachine->transition($order, LabWorkflowState::TECHNICIAN_ASSIGNMENT_PENDING, $actor, [
                'reason' => 'Membuka penugasan teknisi',
            ]);

            // Concurrency guard: the state-machine transition above holds the
            // order row lock, so re-checking here makes a double-assign racing
            // through the pre-check impossible.
            if ($this->assignments->findActiveByLabOrder($order->id) !== null) {
                throw ValidationException::withMessages([
                    'technician_id' => 'Order ini sudah memiliki penugasan teknisi aktif.',
                ]);
            }
            $this->stateMachine->transition($order->refresh(), LabWorkflowState::TECHNICIAN_ASSIGNED, $actor, [
                'reason' => 'Teknisi ditugaskan: '.$technician->name,
            ]);

            $assignment = $this->assignments->create([
                'lab_order_id' => $order->id,
                'technician_id' => $technician->id,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'status' => LabOrderAssignment::STATUS_ASSIGNED,
                'notes' => $notes,
            ]);

            // Seed the V2 step template (idempotent via countForLabOrder,
            // mirroring the legacy createDefaults contract).
            if ($this->steps->countForLabOrder($order->id) === 0) {
                $this->steps->createMany($order, array_keys(LabWorkflowState::V2_PRODUCTION_STEPS));
            }

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $order->id,
                AuditLog::ACTION_ASSIGN_TECHNICIAN,
                null,
                ['technician_id' => $technician->id, 'assignment_id' => $assignment->id],
                $actor,
            );

            return $assignment;
        });

        $this->notifications->notifyUsers(
            [$technician->user()->first()],
            'Penugasan produksi baru',
            "Anda ditugaskan mengerjakan order {$order->order_number}.",
            route('lab-v2-orders.show', $order),
            $order,
        );

        return $assignment;
    }

    /**
     * Start a production step (order transitions into the step's state).
     */
    public function startStep(LabOrder $order, string $step, ?User $actor = null, ?string $notes = null): LabOrder
    {
        $actor = $actor ?? auth()->user();
        $this->assertKnownStep($step);
        $assignment = $this->assertActorMayWork($order, $actor);

        return DB::transaction(function () use ($order, $step, $actor, $notes, $assignment) {
            $result = $this->stateMachine->transition($order, $step, $actor, [
                'reason' => $notes ?: 'Mulai '.$step,
            ]);

            $this->updateStepRow($order, $step, ProductionStep::STATUS_IN_PROGRESS, $notes);

            if ($assignment->status === LabOrderAssignment::STATUS_ASSIGNED) {
                $this->assignments->update($assignment, [
                    'status' => LabOrderAssignment::STATUS_IN_PROGRESS,
                    'started_at' => $assignment->started_at ?? now(),
                ]);
                $this->workLogs->record($assignment, WorkLog::EVENT_WORK_STARTED, 'Mulai produksi V2', 0, $actor);
            }

            return $result;
        });
    }

    /**
     * Complete a production step (order transitions to the step's completed state).
     */
    public function completeStep(LabOrder $order, string $step, ?User $actor = null, ?string $notes = null): LabOrder
    {
        $actor = $actor ?? auth()->user();
        $this->assertKnownStep($step);
        $this->assertActorMayWork($order, $actor);

        $completedState = LabWorkflowState::V2_PRODUCTION_STEPS[$step];

        return DB::transaction(function () use ($order, $step, $completedState, $actor, $notes) {
            $result = $this->stateMachine->transition($order, $completedState, $actor, [
                'reason' => $notes ?: 'Selesai '.$step,
            ]);

            $this->updateStepRow($order, $step, ProductionStep::STATUS_COMPLETED, $notes);

            return $result;
        });
    }

    /**
     * Hand the finished work to QC (STEP_4_COMPLETED -> QC_PENDING).
     * Closes the assignment (DONE) so production reports count it.
     */
    public function sendToQc(LabOrder $order, ?User $actor = null, ?string $notes = null): LabOrder
    {
        $actor = $actor ?? auth()->user();
        $assignment = $this->assertActorMayWork($order, $actor);

        $result = DB::transaction(function () use ($order, $actor, $notes, $assignment) {
            $result = $this->stateMachine->transition($order, LabWorkflowState::QC_PENDING, $actor, [
                'reason' => $notes ?: 'Dikirim ke Quality Control',
            ]);

            if (in_array($assignment->status, LabOrderAssignment::ACTIVE_STATUSES, true)) {
                $this->assignments->update($assignment, [
                    'status' => LabOrderAssignment::STATUS_DONE,
                    'completed_at' => now(),
                ]);
                $this->workLogs->record($assignment, WorkLog::EVENT_WORK_COMPLETED, 'Produksi selesai, masuk QC', 0, $actor);
            }

            return $result;
        });

        $this->notifications->notifyPermissionHolders(
            ['pass_qc', 'manage_quality_control'],
            'QC menunggu review',
            "Order {$order->order_number} menunggu keputusan Quality Control.",
            route('lab-v2-orders.show', $order),
            $order,
        );

        return $result;
    }

    /**
     * Re-open production after a QC rework decision: reset the target step and
     * everything after it to PENDING, mark the target step IN_PROGRESS, and
     * reactivate the assignment. Called by LabV2QualityControlService.
     */
    public function reopenForRework(LabOrder $order, string $targetStep, ?User $actor = null): void
    {
        $actor = $actor ?? auth()->user();
        $this->assertKnownStep($targetStep);

        $stepOrder = array_keys(LabWorkflowState::V2_PRODUCTION_STEPS);
        $targetIndex = array_search($targetStep, $stepOrder, true);

        foreach ($this->steps->forLabOrder($order->id) as $row) {
            $rowIndex = array_search($row->step_name, $stepOrder, true);
            if ($rowIndex === false || $rowIndex < $targetIndex) {
                continue;
            }

            $this->steps->update($row, [
                'status' => $rowIndex === $targetIndex
                    ? ProductionStep::STATUS_IN_PROGRESS
                    : ProductionStep::STATUS_PENDING,
                'started_at' => $rowIndex === $targetIndex ? now() : null,
                'completed_at' => null,
            ]);
        }

        $assignment = $this->latestAssignment($order);
        if ($assignment && $assignment->status === LabOrderAssignment::STATUS_DONE) {
            $this->assignments->update($assignment, [
                'status' => LabOrderAssignment::STATUS_IN_PROGRESS,
                'completed_at' => null,
            ]);
            $this->workLogs->record($assignment, WorkLog::EVENT_WORK_RESUMED, 'Rework: kembali ke '.$targetStep, 0, $actor);
        }
    }

    public function latestAssignment(LabOrder $order): ?LabOrderAssignment
    {
        return $this->assignments->findActiveByLabOrder($order->id)
            ?? $this->assignments->forLabOrder($order->id)->sortByDesc('id')->first();
    }

    /**
     * V2 actor rule (strict): the assigned technician's linked user, or a
     * manage_production supervisor. No non-technician loophole.
     */
    private function assertActorMayWork(LabOrder $order, User $actor): LabOrderAssignment
    {
        $assignment = $this->assignments->findActiveByLabOrder($order->id);

        if ($assignment === null) {
            throw ValidationException::withMessages([
                'assignment' => 'Order ini belum memiliki penugasan teknisi aktif.',
            ]);
        }

        if ($actor->can('manage_production')) {
            return $assignment;
        }

        $assignment->loadMissing('technician');
        $ownerUserId = $assignment->technician?->user_id;

        if ($ownerUserId === null || (int) $ownerUserId !== (int) $actor->id) {
            throw ValidationException::withMessages([
                'assignment' => 'Hanya teknisi yang ditugaskan yang dapat memproses step ini.',
            ]);
        }

        return $assignment;
    }

    private function updateStepRow(LabOrder $order, string $stepName, string $status, ?string $notes): void
    {
        $row = $this->steps->forLabOrder($order->id)->firstWhere('step_name', $stepName);

        if ($row === null) {
            return; // Step template absent (defensive) — state machine remains authoritative.
        }

        $this->steps->update($row, [
            'status' => $status,
            'started_at' => $status === ProductionStep::STATUS_IN_PROGRESS ? now() : $row->started_at,
            'completed_at' => $status === ProductionStep::STATUS_COMPLETED ? now() : null,
            'notes' => ($notes !== null && $notes !== '') ? $notes : $row->notes,
        ]);
    }

    private function assertKnownStep(string $step): void
    {
        if (! array_key_exists($step, LabWorkflowState::V2_PRODUCTION_STEPS)) {
            throw ValidationException::withMessages([
                'step' => "Step produksi tidak dikenal: {$step}.",
            ]);
        }
    }
}
