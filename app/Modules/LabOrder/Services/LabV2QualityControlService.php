<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\QualityControl\Models\QualityControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LAB-WORKFLOW-V2 (Phase 3) — quality control with the rework loop.
 *
 * V2 introduces SEGREGATION OF DUTY (new vs legacy): the technician who
 * produced the work can never pass or fail their own QC, regardless of
 * permissions. QC decisions write a QualityControl row (same table as legacy,
 * so QC reports keep working); rework history is append-only (status logs +
 * QC rows + audit metadata carry the revision number and target step).
 */
class LabV2QualityControlService
{
    public function __construct(
        private readonly LabWorkflowStateMachine $stateMachine,
        private readonly LabV2ProductionService $production,
        private readonly AuditLogService $auditLogs,
    ) {}

    /** QC pass: QC_PENDING -> QC_PASSED -> MODEL_DONE. */
    public function pass(LabOrder $order, ?string $notes = null, ?User $actor = null): LabOrder
    {
        $actor = $actor ?? auth()->user();
        $this->assertSegregationOfDuty($order, $actor);

        return DB::transaction(function () use ($order, $notes, $actor) {
            $this->stateMachine->transition($order, LabWorkflowState::QC_PASSED, $actor, [
                'reason' => $notes ?: 'QC lulus',
            ]);

            QualityControl::create([
                'lab_order_id' => $order->id,
                'inspected_by' => $actor->id,
                'result' => QualityControl::RESULT_PASSED,
                'notes' => $notes,
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            $result = $this->stateMachine->transition($order->refresh(), LabWorkflowState::MODEL_DONE, $actor, [
                'reason' => 'Model selesai (QC lulus)',
            ]);

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $order->id,
                AuditLog::ACTION_PASS_QC,
                null,
                ['result' => QualityControl::RESULT_PASSED],
                $actor,
            );

            return $result;
        });
    }

    /**
     * QC fail: reason + explicit rework target mandatory.
     * QC_PENDING -> QC_FAILED -> REWORK_REQUIRED -> target step, with the
     * production side reopened (steps reset, assignment reactivated).
     */
    public function fail(LabOrder $order, string $reason, ?string $targetStep = null, ?User $actor = null): LabOrder
    {
        $actor = $actor ?? auth()->user();
        $targetStep = $targetStep ?: LabWorkflowState::DEFAULT_REWORK_TARGET;

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan QC gagal wajib diisi.',
            ]);
        }

        if (! in_array($targetStep, LabWorkflowState::REWORK_TARGETS, true)) {
            throw ValidationException::withMessages([
                'target_step' => 'Target rework tidak valid.',
            ]);
        }

        $this->assertSegregationOfDuty($order, $actor);

        return DB::transaction(function () use ($order, $reason, $targetStep, $actor) {
            $this->stateMachine->transition($order, LabWorkflowState::QC_FAILED, $actor, [
                'reason' => $reason,
            ]);

            QualityControl::create([
                'lab_order_id' => $order->id,
                'inspected_by' => $actor->id,
                'result' => QualityControl::RESULT_REJECTED,
                'notes' => $reason,
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            $this->stateMachine->transition($order->refresh(), LabWorkflowState::REWORK_REQUIRED, $actor, [
                'reason' => 'Rework menuju '.$targetStep,
            ]);

            $result = $this->stateMachine->transition($order->refresh(), $targetStep, $actor, [
                'reason' => 'Produksi diulang dari '.$targetStep,
            ]);

            $this->production->reopenForRework($order, $targetStep, $actor);

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $order->id,
                AuditLog::ACTION_REJECT_QC,
                null,
                [
                    'result' => QualityControl::RESULT_REJECTED,
                    'rework_target' => $targetStep,
                    'revision_number' => $this->revisionNumber($order),
                ],
                $actor,
            );

            return $result;
        });
    }

    /** Sequential revision counter derived from the append-only status log. */
    public function revisionNumber(LabOrder $order): int
    {
        return LabOrderStatusLog::query()
            ->where('lab_order_id', $order->id)
            ->where('new_status', LabWorkflowState::QC_FAILED)
            ->count();
    }

    /**
     * SEGREGATION OF DUTY: the producing technician (the latest assignment's
     * linked user) can never decide their own QC — even with QC permissions.
     */
    private function assertSegregationOfDuty(LabOrder $order, User $actor): void
    {
        $assignment = $this->production->latestAssignment($order);
        $assignment?->loadMissing('technician');
        $producerUserId = $assignment?->technician?->user_id;

        if ($producerUserId !== null && (int) $producerUserId === (int) $actor->id) {
            throw ValidationException::withMessages([
                'qc' => 'Teknisi yang mengerjakan produksi tidak boleh memutuskan QC atas pekerjaannya sendiri.',
            ]);
        }
    }
}
