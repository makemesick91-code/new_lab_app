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
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assignment & reassignment business rules (PROJECT_RULES §8).
 */
class AssignmentService
{
    public function __construct(
        private readonly AssignmentRepositoryInterface $assignments,
        private readonly LabOrderRepositoryInterface $labOrders,
        private readonly StatusLogService $statusLogs,
        private readonly AuditLogService $auditLogs,
        private readonly ProductionStepService $productionSteps,
    ) {}

    public function board(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->assignments->paginateBoard($filters, $perPage);
    }

    public function historyForOrder(int $labOrderId): Collection
    {
        return $this->assignments->forLabOrder($labOrderId);
    }

    public function activeForOrder(int $labOrderId): ?LabOrderAssignment
    {
        return $this->assignments->findActiveByLabOrder($labOrderId);
    }

    public function assign(LabOrder $order, int $technicianId, ?string $notes, ?User $actor = null): LabOrderAssignment
    {
        $actor = $actor ?? auth()->user();

        if ($order->status !== LabOrder::STATUS_RECEIVED) {
            throw ValidationException::withMessages([
                'status' => 'Hanya order berstatus RECEIVED yang dapat di-assign.',
            ]);
        }

        if ($this->assignments->findActiveByLabOrder($order->id)) {
            throw ValidationException::withMessages([
                'technician_id' => 'Order ini sudah memiliki assignment aktif.',
            ]);
        }

        return DB::transaction(function () use ($order, $technicianId, $notes, $actor) {
            $assignment = $this->assignments->create([
                'lab_order_id' => $order->id,
                'technician_id' => $technicianId,
                'assigned_by' => $actor?->id,
                'assigned_at' => now(),
                'status' => LabOrderAssignment::STATUS_ASSIGNED,
                'notes' => $notes,
            ]);

            $this->transitionOrder($order, LabOrder::STATUS_ASSIGNED, $notes, $actor);

            $this->productionSteps->createDefaults($order, $actor);

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $order->id,
                AuditLog::ACTION_ASSIGN_TECHNICIAN,
                ['status' => LabOrder::STATUS_RECEIVED, 'technician_id' => null],
                ['status' => LabOrder::STATUS_ASSIGNED, 'technician_id' => $technicianId, 'assignment_id' => $assignment->id],
                $actor,
            );

            return $assignment;
        });
    }

    public function reassign(LabOrder $order, int $newTechnicianId, string $reason, ?User $actor = null): LabOrderAssignment
    {
        $actor = $actor ?? auth()->user();

        if (! in_array($order->status, [LabOrder::STATUS_ASSIGNED, 'IN_PRODUCTION', 'ON_HOLD'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Order tidak dapat di-reassign pada status saat ini.',
            ]);
        }

        $active = $this->assignments->findActiveByLabOrder($order->id);

        if (! $active) {
            throw ValidationException::withMessages([
                'technician_id' => 'Tidak ada assignment aktif untuk di-reassign.',
            ]);
        }

        if ((int) $active->technician_id === $newTechnicianId) {
            throw ValidationException::withMessages([
                'technician_id' => 'Teknisi baru harus berbeda dari teknisi saat ini.',
            ]);
        }

        return DB::transaction(function () use ($order, $active, $newTechnicianId, $reason, $actor) {
            $this->assignments->update($active, ['status' => LabOrderAssignment::STATUS_REASSIGNED]);

            $new = $this->assignments->create([
                'lab_order_id' => $order->id,
                'technician_id' => $newTechnicianId,
                'assigned_by' => $actor?->id,
                'assigned_at' => now(),
                'status' => LabOrderAssignment::STATUS_ASSIGNED,
                'notes' => $reason,
            ]);

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $order->id,
                AuditLog::ACTION_REASSIGN_TECHNICIAN,
                ['technician_id' => $active->technician_id, 'assignment_id' => $active->id],
                ['technician_id' => $newTechnicianId, 'assignment_id' => $new->id, 'reason' => $reason],
                $actor,
            );

            return $new;
        });
    }

    public function cancel(LabOrderAssignment $assignment, string $notes, ?User $actor = null): LabOrderAssignment
    {
        $actor = $actor ?? auth()->user();

        if (! $assignment->isActive()) {
            throw ValidationException::withMessages([
                'status' => 'Hanya assignment aktif yang dapat dibatalkan.',
            ]);
        }

        return DB::transaction(function () use ($assignment, $notes, $actor) {
            $this->assignments->update($assignment, [
                'status' => LabOrderAssignment::STATUS_CANCELLED,
                'notes' => $notes,
            ]);

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $assignment->lab_order_id,
                'CANCEL_ASSIGNMENT',
                ['assignment_id' => $assignment->id, 'status' => LabOrderAssignment::STATUS_ASSIGNED],
                ['assignment_id' => $assignment->id, 'status' => LabOrderAssignment::STATUS_CANCELLED, 'notes' => $notes],
                $actor,
            );

            return $assignment->refresh();
        });
    }

    private function transitionOrder(LabOrder $order, string $newStatus, ?string $notes, ?User $actor): void
    {
        $oldStatus = $order->status;

        $this->labOrders->update($order, ['status' => $newStatus, 'updated_by' => $actor?->id]);

        $this->statusLogs->log($order->id, $oldStatus, $newStatus, $notes, $actor);

        $this->auditLogs->log(
            LabOrder::ENTITY_TYPE,
            $order->id,
            AuditLog::ACTION_STATUS_CHANGE,
            ['status' => $oldStatus],
            ['status' => $newStatus],
            $actor,
        );
    }
}
