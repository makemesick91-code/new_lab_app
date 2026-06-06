<?php

namespace App\Modules\Delivery\Services;

use App\Models\User;
use App\Modules\Delivery\Interfaces\DeliveryRepositoryInterface;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\LabOrder\Interfaces\LabOrderRepositoryInterface;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\LabOrder\Services\StatusLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryWorkflowService
{
    public function __construct(
        private readonly DeliveryRepositoryInterface $deliveries,
        private readonly LabOrderRepositoryInterface $labOrders,
        private readonly StatusLogService $statusLogs,
        private readonly AuditLogService $auditLogs,
        private readonly PodService $podService,
    ) {}

    public function moveToReady(Delivery $delivery, ?string $notes = null, ?User $actor = null): void
    {
        $this->transitionOrder($delivery, LabOrder::STATUS_QC_PASSED, LabOrder::STATUS_READY_FOR_DELIVERY, $notes, $actor);
    }

    public function start(Delivery $delivery, ?string $notes = null, ?User $actor = null): Delivery
    {
        $actor = $actor ?? auth()->user();
        $delivery = $delivery->refresh();

        if ($delivery->status !== Delivery::STATUS_READY_FOR_DELIVERY || $delivery->labOrder->status !== LabOrder::STATUS_READY_FOR_DELIVERY) {
            throw ValidationException::withMessages(['status' => 'Pengiriman hanya dapat dimulai dari status Siap Dikirim.']);
        }

        if (! $delivery->courier_id) {
            throw ValidationException::withMessages(['courier_id' => 'Kurir wajib ditugaskan sebelum pengiriman dimulai.']);
        }

        return DB::transaction(function () use ($delivery, $notes, $actor) {
            $updated = $this->deliveries->startDelivery($delivery);
            $this->transitionOrder($updated, LabOrder::STATUS_READY_FOR_DELIVERY, LabOrder::STATUS_IN_DELIVERY, $notes, $actor);

            $this->auditLogs->log(
                Delivery::ENTITY_TYPE,
                $updated->id,
                AuditLog::ACTION_START_DELIVERY,
                ['status' => Delivery::STATUS_READY_FOR_DELIVERY],
                ['status' => Delivery::STATUS_IN_DELIVERY, 'started_at' => $updated->started_at?->toDateTimeString()],
                $actor,
            );

            return $updated->refresh();
        });
    }

    public function markDelivered(Delivery $delivery, array $data, ?User $actor = null): Delivery
    {
        $actor = $actor ?? auth()->user();
        $delivery = $delivery->refresh();

        if ($delivery->status !== Delivery::STATUS_IN_DELIVERY || $delivery->labOrder->status !== LabOrder::STATUS_IN_DELIVERY) {
            throw ValidationException::withMessages(['status' => 'Pengiriman hanya dapat ditandai terkirim dari status Dalam Pengiriman.']);
        }

        return DB::transaction(function () use ($delivery, $data, $actor) {
            if (! empty($data['receiver_signature_data'])) {
                $delivery = $this->podService->uploadPod(
                    $delivery,
                    $data['receiver_name'],
                    $data['receiver_signature_data'],
                    $data['received_at'],
                    $data['delivery_notes'] ?? null,
                    $actor,
                    $data['receiver_photo'] ?? null,
                );
            }

            $this->podService->assertComplete($delivery->refresh());
            $updated = $this->deliveries->markDelivered($delivery, [
                'delivery_notes' => $data['delivery_notes'] ?? $delivery->delivery_notes,
            ]);

            $this->transitionOrder($updated, LabOrder::STATUS_IN_DELIVERY, LabOrder::STATUS_DELIVERED, $data['delivery_notes'] ?? null, $actor);

            $this->auditLogs->log(
                Delivery::ENTITY_TYPE,
                $updated->id,
                AuditLog::ACTION_MARK_DELIVERED,
                ['status' => Delivery::STATUS_IN_DELIVERY],
                ['status' => Delivery::STATUS_DELIVERED, 'receiver_name' => $updated->receiver_name],
                $actor,
            );

            return $updated->refresh();
        });
    }

    public function complete(Delivery $delivery, ?string $notes = null, ?User $actor = null): Delivery
    {
        $actor = $actor ?? auth()->user();
        $delivery = $delivery->refresh();

        if ($delivery->status !== Delivery::STATUS_DELIVERED || $delivery->labOrder->status !== LabOrder::STATUS_DELIVERED) {
            throw ValidationException::withMessages(['status' => 'Pengiriman hanya dapat diselesaikan dari status Terkirim.']);
        }

        $this->podService->assertComplete($delivery);

        return DB::transaction(function () use ($delivery, $notes, $actor) {
            $updated = $this->deliveries->completeDelivery($delivery, ['delivery_notes' => $notes ?? $delivery->delivery_notes]);
            $this->transitionOrder($updated, LabOrder::STATUS_DELIVERED, LabOrder::STATUS_COMPLETED, $notes, $actor);

            $this->auditLogs->log(
                Delivery::ENTITY_TYPE,
                $updated->id,
                AuditLog::ACTION_COMPLETE_DELIVERY,
                ['status' => Delivery::STATUS_DELIVERED],
                ['status' => Delivery::STATUS_COMPLETED, 'completed_at' => $updated->completed_at?->toDateTimeString()],
                $actor,
            );

            return $updated->refresh();
        });
    }

    private function transitionOrder(Delivery $delivery, string $expectedOldStatus, string $newStatus, ?string $notes, ?User $actor): void
    {
        $actor = $actor ?? auth()->user();
        $order = $delivery->labOrder()->lockForUpdate()->first();

        if (! $order || $order->status !== $expectedOldStatus) {
            throw ValidationException::withMessages([
                'status' => "Status Order Lab harus {$expectedOldStatus}.",
            ]);
        }

        $this->labOrders->update($order, ['status' => $newStatus, 'updated_by' => $actor?->id]);
        $this->statusLogs->log($order->id, $expectedOldStatus, $newStatus, $notes, $actor);

        $this->auditLogs->log(
            LabOrder::ENTITY_TYPE,
            $order->id,
            AuditLog::ACTION_STATUS_CHANGE,
            ['status' => $expectedOldStatus],
            ['status' => $newStatus],
            $actor,
        );
    }
}
