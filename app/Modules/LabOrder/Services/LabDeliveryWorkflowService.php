<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\LabOrder\Models\LabDeliveryTask;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabWorkflowEvidence;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LAB-WORKFLOW-V2 (Phase 4) — delivery leg with the owner-mandated proof gates.
 *
 * HARD SERVER-SIDE RULES (never UI-only):
 *  1. Before IN_TRANSIT_TO_BRANCH the order MUST hold BOTH a pre-delivery
 *     handover photo (lab -> courier) AND the courier's signature. The state
 *     machine matrix already makes the milestones sequential; this service
 *     additionally re-verifies the evidence rows exist before transit.
 *  2. DELIVERED requires recipient signature + recipient name + a delivery
 *     location/proof photo — all three re-verified before the final edge.
 * Task-level guards (row lock, courier ownership, status sequence) mirror the
 * Phase 2 pickup service; the order-level transition is re-validated by
 * LabWorkflowStateMachine under its own row lock.
 */
class LabDeliveryWorkflowService
{
    public function __construct(
        private readonly LabWorkflowStateMachine $stateMachine,
        private readonly LabWorkflowEvidenceService $evidence,
        private readonly LabWorkflowNotificationService $notifications,
    ) {}

    /**
     * Create/activate the delivery task after MODEL_DONE.
     * Chains MODEL_DONE -> DELIVERY_PENDING -> COURIER_NOTIFIED; idempotent
     * task creation (UNIQUE lab_order_id).
     */
    public function createTask(LabOrder $order, ?User $actor = null): LabDeliveryTask
    {
        $actor = $actor ?? auth()->user();

        $task = DB::transaction(function () use ($order, $actor) {
            $existing = LabDeliveryTask::query()->where('lab_order_id', $order->id)->first();
            if ($existing) {
                return $existing;
            }

            $this->stateMachine->transition($order, LabWorkflowState::DELIVERY_PENDING, $actor, [
                'reason' => 'Tugas pengiriman ke cabang dibuat',
            ]);
            $this->stateMachine->transition($order->refresh(), LabWorkflowState::COURIER_NOTIFIED, $actor, [
                'reason' => 'Kurir dinotifikasi untuk pengiriman',
            ]);

            return LabDeliveryTask::create([
                'lab_order_id' => $order->id,
                'branch_id' => (int) $order->branch_id,
                'status' => LabDeliveryTask::STATUS_PENDING,
                'created_by' => $actor->id,
            ]);
        });

        $this->notifications->notifyPermissionHolders(
            ['start_delivery'],
            'Tugas pengiriman baru',
            "Order {$order->order_number} siap diantar kembali ke cabang.",
            route('lab-delivery-tasks.show', $task),
            $order,
        );

        return $task;
    }

    /** Courier claims the delivery (first-committed-wins). */
    public function accept(LabDeliveryTask $task, User $actor): LabDeliveryTask
    {
        return DB::transaction(function () use ($task, $actor) {
            $locked = $this->lock($task);

            if ($locked->status === LabDeliveryTask::STATUS_ACCEPTED && $locked->isClaimedBy($actor)) {
                return $locked; // idempotent retry
            }

            $this->assertTaskStatus($locked, LabDeliveryTask::STATUS_PENDING);

            if ($locked->courier_id !== null) {
                throw ValidationException::withMessages([
                    'courier' => 'Tugas pengiriman ini sudah diambil kurir lain.',
                ]);
            }

            $this->stateMachine->transition($locked->labOrder, LabWorkflowState::DELIVERY_ACCEPTED, $actor, [
                'reason' => 'Kurir menerima tugas pengiriman',
            ]);
            $this->stateMachine->transition($locked->labOrder->refresh(), LabWorkflowState::LAB_HANDOVER_PENDING, $actor, [
                'reason' => 'Menunggu serah terima model dari lab',
            ]);

            return $this->update($locked, [
                'status' => LabDeliveryTask::STATUS_ACCEPTED,
                'courier_id' => $actor->id,
                'accepted_at' => now(),
            ]);
        });
    }

    /**
     * OWNER RULE: the lab -> courier handover requires BOTH the handover photo
     * AND the courier signature in one atomic action. Only then does the order
     * become READY_FOR_TRANSIT_TO_BRANCH.
     */
    public function submitHandoverProof(LabDeliveryTask $task, User $actor, UploadedFile $photo, string $signatureDataUrl): LabDeliveryTask
    {
        return DB::transaction(function () use ($task, $actor, $photo, $signatureDataUrl) {
            $locked = $this->lock($task);

            $this->assertTaskStatus($locked, LabDeliveryTask::STATUS_ACCEPTED);
            $this->assertClaimedBy($locked, $actor);

            $order = $locked->labOrder;

            // Store both artifacts FIRST — a failure on either aborts the whole action.
            $this->evidence->storePhoto($order, LabWorkflowEvidence::TYPE_PRE_DELIVERY_HANDOVER_PHOTO, $photo, $actor);
            $this->evidence->storePng($order, LabWorkflowEvidence::TYPE_COURIER_SIGNATURE, $this->decodeSignature($signatureDataUrl), $actor);

            $this->stateMachine->transition($order, LabWorkflowState::PRE_DELIVERY_PHOTO_CAPTURED, $actor, [
                'reason' => 'Foto serah terima lab → kurir tersimpan',
            ]);
            $this->stateMachine->transition($order->refresh(), LabWorkflowState::COURIER_SIGNATURE_CAPTURED, $actor, [
                'reason' => 'Tanda tangan kurir tersimpan',
            ]);

            // Both artifacts must exist before READY (owner rule, re-verified).
            $this->assertEvidence($order, [
                LabWorkflowEvidence::TYPE_PRE_DELIVERY_HANDOVER_PHOTO,
                LabWorkflowEvidence::TYPE_COURIER_SIGNATURE,
            ], 'Foto serah terima dan tanda tangan kurir wajib lengkap sebelum berangkat.');

            $this->stateMachine->transition($order->refresh(), LabWorkflowState::READY_FOR_TRANSIT_TO_BRANCH, $actor, [
                'reason' => 'Bukti pra-pengantaran lengkap',
            ]);

            return $this->update($locked, [
                'status' => LabDeliveryTask::STATUS_READY_FOR_TRANSIT,
                'ready_at' => now(),
            ]);
        });
    }

    /**
     * Depart to the branch. HARD GATE: refuses unless BOTH pre-transit proofs
     * exist — even if the status was somehow reached another way.
     */
    public function startTransit(LabDeliveryTask $task, User $actor): LabDeliveryTask
    {
        return DB::transaction(function () use ($task, $actor) {
            $locked = $this->lock($task);

            $this->assertTaskStatus($locked, LabDeliveryTask::STATUS_READY_FOR_TRANSIT);
            $this->assertClaimedBy($locked, $actor);

            $this->assertEvidence($locked->labOrder, [
                LabWorkflowEvidence::TYPE_PRE_DELIVERY_HANDOVER_PHOTO,
                LabWorkflowEvidence::TYPE_COURIER_SIGNATURE,
            ], 'Perjalanan ditolak: foto serah terima dan tanda tangan kurir wajib ada.');

            $this->stateMachine->transition($locked->labOrder, LabWorkflowState::IN_TRANSIT_TO_BRANCH, $actor, [
                'reason' => 'Model dalam perjalanan ke cabang',
            ]);

            return $this->update($locked, [
                'status' => LabDeliveryTask::STATUS_IN_TRANSIT,
                'in_transit_at' => now(),
            ]);
        });
    }

    public function markArrived(LabDeliveryTask $task, User $actor): LabDeliveryTask
    {
        return DB::transaction(function () use ($task, $actor) {
            $locked = $this->lock($task);

            $this->assertTaskStatus($locked, LabDeliveryTask::STATUS_IN_TRANSIT);
            $this->assertClaimedBy($locked, $actor);

            $this->stateMachine->transition($locked->labOrder, LabWorkflowState::ARRIVED_AT_BRANCH, $actor, [
                'reason' => 'Kurir tiba di cabang',
            ]);

            return $this->update($locked, [
                'status' => LabDeliveryTask::STATUS_ARRIVED,
                'arrived_at' => now(),
            ]);
        });
    }

    /**
     * OWNER RULE: DELIVERED requires recipient signature + recipient name +
     * a delivery location/proof photo — all captured atomically here.
     *
     * @param  array{recipient_name: string, recipient_role?: string|null, notes?: string|null}  $data
     */
    public function completeDelivery(LabDeliveryTask $task, User $actor, string $recipientSignatureDataUrl, UploadedFile $locationPhoto, array $data): LabDeliveryTask
    {
        $justDelivered = false;

        $result = DB::transaction(function () use ($task, $actor, $recipientSignatureDataUrl, $locationPhoto, $data, &$justDelivered) {
            $locked = $this->lock($task);

            // Idempotent double-submit: already delivered -> safe no-op,
            // no duplicate evidence rows.
            if ($locked->status === LabDeliveryTask::STATUS_DELIVERED) {
                return $locked;
            }

            $justDelivered = true;

            $this->assertTaskStatus($locked, LabDeliveryTask::STATUS_ARRIVED);
            $this->assertClaimedBy($locked, $actor);

            $recipientName = trim((string) ($data['recipient_name'] ?? ''));
            if ($recipientName === '') {
                throw ValidationException::withMessages([
                    'recipient_name' => 'Nama penerima wajib diisi.',
                ]);
            }

            $order = $locked->labOrder;

            $this->evidence->storePng($order, LabWorkflowEvidence::TYPE_RECIPIENT_SIGNATURE, $this->decodeSignature($recipientSignatureDataUrl), $actor);
            $this->evidence->storePhoto($order, LabWorkflowEvidence::TYPE_DELIVERY_LOCATION_PHOTO, $locationPhoto, $actor);

            $this->stateMachine->transition($order, LabWorkflowState::RECIPIENT_SIGNATURE_CAPTURED, $actor, [
                'reason' => 'Tanda tangan penerima: '.$recipientName,
            ]);
            $this->stateMachine->transition($order->refresh(), LabWorkflowState::DELIVERY_LOCATION_PHOTO_CAPTURED, $actor, [
                'reason' => 'Foto bukti serah terima tersimpan',
            ]);

            // Final gate: all mandatory proofs re-verified before DELIVERED.
            $this->assertEvidence($order, [
                LabWorkflowEvidence::TYPE_RECIPIENT_SIGNATURE,
                LabWorkflowEvidence::TYPE_DELIVERY_LOCATION_PHOTO,
            ], 'DELIVERED ditolak: tanda tangan penerima dan foto bukti wajib lengkap.');

            $this->stateMachine->transition($order->refresh(), LabWorkflowState::DELIVERED, $actor, [
                'reason' => 'Model diterima '.$recipientName,
            ]);

            return $this->update($locked, [
                'status' => LabDeliveryTask::STATUS_DELIVERED,
                'delivered_at' => now(),
                'recipient_name' => $recipientName,
                'recipient_role' => $data['recipient_role'] ?? null,
                'delivery_notes' => $data['notes'] ?? null,
            ]);
        });

        if ($justDelivered) {
            $this->notifications->notifyPermissionHolders(
                ['create_lab_branch_requests'],
                'Model tiba di cabang',
                "Order {$result->labOrder->order_number} telah diterima {$result->recipient_name}.",
                route('lab-workflow-requests.show', $result->lab_order_id),
                $result->labOrder,
            );
        }

        return $result;
    }

    /**
     * Decode an Alpine signature-canvas data URL into raw PNG bytes.
     * storePng re-validates the PNG magic bytes + size afterwards.
     */
    private function decodeSignature(string $dataUrl): string
    {
        if (! preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $dataUrl, $matches)) {
            throw ValidationException::withMessages([
                'signature' => 'Format tanda tangan tidak valid.',
            ]);
        }

        $binary = base64_decode($matches[1], true);

        if ($binary === false || $binary === '') {
            throw ValidationException::withMessages([
                'signature' => 'Tanda tangan tidak dapat dibaca.',
            ]);
        }

        return $binary;
    }

    /**
     * @param  list<string>  $types
     */
    private function assertEvidence(LabOrder $order, array $types, string $message): void
    {
        foreach ($types as $type) {
            if (! $this->evidence->has($order, $type)) {
                throw ValidationException::withMessages(['evidence' => $message]);
            }
        }
    }

    private function lock(LabDeliveryTask $task): LabDeliveryTask
    {
        $locked = LabDeliveryTask::query()->lockForUpdate()->find($task->getKey());

        if ($locked === null) {
            throw ValidationException::withMessages([
                'task' => 'Tugas pengiriman tidak ditemukan.',
            ]);
        }

        $locked->setRelation('labOrder', $locked->labOrder()->first());

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function update(LabDeliveryTask $task, array $data): LabDeliveryTask
    {
        $task->update($data);

        return $task->refresh();
    }

    private function assertTaskStatus(LabDeliveryTask $task, string $expected): void
    {
        if ($task->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => "Status tugas saat ini ({$task->status}) tidak memungkinkan aksi ini.",
            ]);
        }
    }

    private function assertClaimedBy(LabDeliveryTask $task, User $actor): void
    {
        if (! $task->isClaimedBy($actor)) {
            throw ValidationException::withMessages([
                'courier' => 'Hanya kurir yang menerima tugas ini yang dapat memprosesnya.',
            ]);
        }
    }
}
