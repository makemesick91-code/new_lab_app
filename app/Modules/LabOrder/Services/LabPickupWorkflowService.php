<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\LabOrder\Interfaces\LabPickupTaskRepositoryInterface;
use App\Modules\LabOrder\Models\LabPickupTask;
use App\Modules\LabOrder\Models\LabWorkflowEvidence;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LAB-WORKFLOW-V2 (Phase 2) — courier pickup leg + lab receive confirmation.
 *
 * Task-level guards (row lock, courier ownership, status sequence) run here;
 * the order-level transition is re-validated by LabWorkflowStateMachine under
 * its own row lock. Claiming is first-committed-wins: two couriers cannot end
 * up owning the same task.
 */
class LabPickupWorkflowService
{
    public function __construct(
        private readonly LabPickupTaskRepositoryInterface $tasks,
        private readonly LabWorkflowStateMachine $stateMachine,
        private readonly LabWorkflowEvidenceService $evidence,
    ) {}

    public function queue(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->tasks->queue($filters, min($perPage, 100));
    }

    public function findDetail(int $id): ?LabPickupTask
    {
        return $this->tasks->findDetailById($id);
    }

    /** Courier claims a PENDING task (first-committed-wins). */
    public function accept(LabPickupTask $task, User $actor): LabPickupTask
    {
        return DB::transaction(function () use ($task, $actor) {
            $locked = $this->lock($task);

            // Idempotent retry: the same courier re-accepting is a no-op.
            if ($locked->status === LabPickupTask::STATUS_ACCEPTED && $locked->isClaimedBy($actor)) {
                return $locked;
            }

            $this->assertTaskStatus($locked, LabPickupTask::STATUS_PENDING);

            if ($locked->courier_id !== null) {
                throw ValidationException::withMessages([
                    'courier' => 'Tugas pickup ini sudah diambil kurir lain.',
                ]);
            }

            $this->stateMachine->transition($locked->labOrder, LabWorkflowState::PICKUP_ACCEPTED, $actor, [
                'reason' => 'Kurir menerima tugas pickup',
            ]);

            return $this->tasks->update($locked, [
                'status' => LabPickupTask::STATUS_ACCEPTED,
                'courier_id' => $actor->id,
                'accepted_at' => now(),
            ]);
        });
    }

    /** Courier picks the model up at the branch — pickup photo is mandatory. */
    public function markPickedUp(LabPickupTask $task, User $actor, UploadedFile $photo, ?string $notes = null): LabPickupTask
    {
        return DB::transaction(function () use ($task, $actor, $photo, $notes) {
            $locked = $this->lock($task);

            $this->assertTaskStatus($locked, LabPickupTask::STATUS_ACCEPTED);
            $this->assertClaimedBy($locked, $actor);

            $this->evidence->storePhoto($locked->labOrder, LabWorkflowEvidence::TYPE_PICKUP_PHOTO, $photo, $actor);

            $this->stateMachine->transition($locked->labOrder, LabWorkflowState::PICKED_UP, $actor, [
                'reason' => $notes ?: 'Model diambil dari cabang',
            ]);

            return $this->tasks->update($locked, [
                'status' => LabPickupTask::STATUS_PICKED_UP,
                'picked_up_at' => now(),
                'pickup_notes' => $notes,
            ]);
        });
    }

    /** Courier departs toward the lab. Pickup evidence must already exist. */
    public function startTransit(LabPickupTask $task, User $actor): LabPickupTask
    {
        return DB::transaction(function () use ($task, $actor) {
            $locked = $this->lock($task);

            $this->assertTaskStatus($locked, LabPickupTask::STATUS_PICKED_UP);
            $this->assertClaimedBy($locked, $actor);

            if (! $this->evidence->has($locked->labOrder, LabWorkflowEvidence::TYPE_PICKUP_PHOTO)) {
                throw ValidationException::withMessages([
                    'evidence' => 'Bukti foto pickup wajib ada sebelum perjalanan ke lab.',
                ]);
            }

            $this->stateMachine->transition($locked->labOrder, LabWorkflowState::IN_TRANSIT_TO_LAB, $actor, [
                'reason' => 'Model dalam perjalanan ke lab',
            ]);

            return $this->tasks->update($locked, [
                'status' => LabPickupTask::STATUS_IN_TRANSIT,
                'in_transit_at' => now(),
            ]);
        });
    }

    /**
     * Lab staff confirms physical receipt — never auto-completed by the
     * courier's own action (owner rule).
     */
    public function receiveAtLab(LabPickupTask $task, User $actor, ?string $discrepancyNote = null): LabPickupTask
    {
        return DB::transaction(function () use ($task, $actor, $discrepancyNote) {
            $locked = $this->lock($task);

            // Idempotent retry: already received is a no-op.
            if ($locked->status === LabPickupTask::STATUS_RECEIVED) {
                return $locked;
            }

            $this->assertTaskStatus($locked, LabPickupTask::STATUS_IN_TRANSIT);

            // RECEIVED_AT_LAB maps to manage_lab_orders — the state machine
            // rejects couriers here, enforcing receiver-side confirmation.
            $this->stateMachine->transition($locked->labOrder, LabWorkflowState::RECEIVED_AT_LAB, $actor, [
                'reason' => $discrepancyNote ? 'Model diterima lab (ada catatan ketidaksesuaian)' : 'Model diterima lab',
            ]);

            return $this->tasks->update($locked, [
                'status' => LabPickupTask::STATUS_RECEIVED,
                'received_at' => now(),
                'received_by' => $actor->id,
                'discrepancy_note' => $discrepancyNote,
            ]);
        });
    }

    private function lock(LabPickupTask $task): LabPickupTask
    {
        $locked = $this->tasks->lockById($task->getKey());

        if ($locked === null) {
            throw ValidationException::withMessages([
                'task' => 'Tugas pickup tidak ditemukan.',
            ]);
        }

        $locked->setRelation('labOrder', $locked->labOrder()->first());

        return $locked;
    }

    private function assertTaskStatus(LabPickupTask $task, string $expected): void
    {
        if ($task->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => "Status tugas saat ini ({$task->status}) tidak memungkinkan aksi ini.",
            ]);
        }
    }

    private function assertClaimedBy(LabPickupTask $task, User $actor): void
    {
        if (! $task->isClaimedBy($actor)) {
            throw ValidationException::withMessages([
                'courier' => 'Hanya kurir yang menerima tugas ini yang dapat memprosesnya.',
            ]);
        }
    }
}
