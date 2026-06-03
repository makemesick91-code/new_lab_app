<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\LabOrder\Interfaces\LabOrderRepositoryInterface;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\LabOrder\Services\StatusLogService;
use App\Modules\Production\Interfaces\AssignmentRepositoryInterface;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Production\Models\WorkLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates the Sprint 4 production status transitions
 * (start → pause → resume → complete → send to QC).
 */
class ProductionWorkflowService
{
    public function __construct(
        private readonly AssignmentRepositoryInterface $assignments,
        private readonly LabOrderRepositoryInterface $labOrders,
        private readonly WorkLogService $workLogs,
        private readonly StatusLogService $statusLogs,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function startWork(LabOrder $order, ?string $notes, ?User $actor = null): LabOrderAssignment
    {
        $actor = $actor ?? auth()->user();
        $this->assertStatus($order, LabOrder::STATUS_ASSIGNED);
        $assignment = $this->requireActiveAssignment($order, LabOrderAssignment::STATUS_ASSIGNED);

        return DB::transaction(function () use ($order, $assignment, $notes, $actor) {
            $this->assignments->update($assignment, [
                'status' => LabOrderAssignment::STATUS_IN_PROGRESS,
                'started_at' => now(),
            ]);

            $this->transitionOrder($order, 'IN_PRODUCTION', $notes, $actor);
            $this->workLogs->record($assignment, WorkLog::EVENT_WORK_STARTED, $notes, 0, $actor);
            $this->audit($order, AuditLog::ACTION_START_WORK, 'ASSIGNED', 'IN_PRODUCTION', $actor);

            return $assignment->refresh();
        });
    }

    public function pauseWork(LabOrder $order, string $reason, ?string $holdReason, ?User $actor = null): LabOrderAssignment
    {
        $actor = $actor ?? auth()->user();
        $this->assertStatus($order, 'IN_PRODUCTION');
        $assignment = $this->requireActiveAssignment($order, LabOrderAssignment::STATUS_IN_PROGRESS);

        return DB::transaction(function () use ($order, $assignment, $reason, $holdReason, $actor) {
            $duration = $this->workLogs->activeMinutesSinceLastStart($assignment);
            $notes = $holdReason ? "[{$holdReason}] {$reason}" : $reason;

            $this->transitionOrder($order, 'ON_HOLD', $notes, $actor);
            $this->workLogs->record($assignment, WorkLog::EVENT_WORK_PAUSED, $notes, $duration, $actor);
            $this->audit($order, AuditLog::ACTION_PAUSE_WORK, 'IN_PRODUCTION', 'ON_HOLD', $actor);

            return $assignment->refresh();
        });
    }

    public function resumeWork(LabOrder $order, ?string $notes, ?User $actor = null): LabOrderAssignment
    {
        $actor = $actor ?? auth()->user();
        $this->assertStatus($order, 'ON_HOLD');
        $assignment = $this->requireActiveAssignment($order, LabOrderAssignment::STATUS_IN_PROGRESS);

        return DB::transaction(function () use ($order, $assignment, $notes, $actor) {
            $this->transitionOrder($order, 'IN_PRODUCTION', $notes, $actor);
            $this->workLogs->record($assignment, WorkLog::EVENT_WORK_RESUMED, $notes, 0, $actor);
            $this->audit($order, AuditLog::ACTION_RESUME_WORK, 'ON_HOLD', 'IN_PRODUCTION', $actor);

            return $assignment->refresh();
        });
    }

    public function completeWork(LabOrder $order, ?string $notes, ?User $actor = null): LabOrderAssignment
    {
        $actor = $actor ?? auth()->user();
        $this->assertStatus($order, 'IN_PRODUCTION');
        $assignment = $this->requireActiveAssignment($order, LabOrderAssignment::STATUS_IN_PROGRESS);

        return DB::transaction(function () use ($order, $assignment, $notes, $actor) {
            $duration = $this->workLogs->activeMinutesSinceLastStart($assignment);

            $this->assignments->update($assignment, [
                'status' => LabOrderAssignment::STATUS_DONE,
                'completed_at' => now(),
            ]);

            $this->workLogs->record($assignment, WorkLog::EVENT_WORK_COMPLETED, $notes, $duration, $actor);

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $order->id,
                AuditLog::ACTION_COMPLETE_WORK,
                ['assignment_status' => LabOrderAssignment::STATUS_IN_PROGRESS],
                ['assignment_status' => LabOrderAssignment::STATUS_DONE, 'notes' => $notes],
                $actor,
            );

            return $assignment->refresh();
        });
    }

    public function sendToQc(LabOrder $order, ?string $notes, ?User $actor = null): LabOrder
    {
        $actor = $actor ?? auth()->user();
        $this->assertStatus($order, 'IN_PRODUCTION');

        $latest = $this->assignments->forLabOrder($order->id)->first();
        if (! $latest || $latest->status !== LabOrderAssignment::STATUS_DONE) {
            throw ValidationException::withMessages([
                'status' => 'Pekerjaan produksi harus diselesaikan (DONE) sebelum dikirim ke QC.',
            ]);
        }

        return DB::transaction(function () use ($order, $notes, $actor) {
            $this->transitionOrder($order, 'QC_PENDING', $notes, $actor);
            $this->audit($order, AuditLog::ACTION_SEND_TO_QC, 'IN_PRODUCTION', 'QC_PENDING', $actor);

            return $order->refresh();
        });
    }

    private function assertStatus(LabOrder $order, string $expected): void
    {
        if ($order->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => "Aksi ini hanya valid untuk order berstatus {$expected}.",
            ]);
        }
    }

    private function requireActiveAssignment(LabOrder $order, string $expectedStatus): LabOrderAssignment
    {
        $assignment = $this->assignments->findActiveByLabOrder($order->id);

        if (! $assignment || $assignment->status !== $expectedStatus) {
            throw ValidationException::withMessages([
                'assignment' => 'Tidak ada assignment aktif yang valid untuk aksi ini.',
            ]);
        }

        return $assignment;
    }

    private function transitionOrder(LabOrder $order, string $newStatus, ?string $notes, ?User $actor): void
    {
        $oldStatus = $order->status;
        $this->labOrders->update($order, ['status' => $newStatus, 'updated_by' => $actor?->id]);
        $this->statusLogs->log($order->id, $oldStatus, $newStatus, $notes, $actor);
    }

    private function audit(LabOrder $order, string $action, string $oldStatus, string $newStatus, ?User $actor): void
    {
        $this->auditLogs->log(
            LabOrder::ENTITY_TYPE,
            $order->id,
            $action,
            ['status' => $oldStatus],
            ['status' => $newStatus],
            $actor,
        );
    }
}
