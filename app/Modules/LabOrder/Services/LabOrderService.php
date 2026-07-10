<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\LabOrder\Interfaces\LabOrderRepositoryInterface;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns Lab Order business rules and transactions (PROJECT_RULES §8).
 */
class LabOrderService
{
    public function __construct(
        private readonly LabOrderRepositoryInterface $labOrders,
        private readonly OrderNumberGeneratorService $orderNumbers,
        private readonly StatusLogService $statusLogs,
        private readonly AuditLogService $auditLogs,
        private readonly LabWorkflowResolver $workflowResolver,
    ) {}

    public function list(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->labOrders->paginate($filters, min($perPage, 100));
    }

    public function findDetail(int $id): ?LabOrder
    {
        return $this->labOrders->findDetailById($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): LabOrder
    {
        $actor = $actor ?? auth()->user();

        // LAB-WORKFLOW-V2: legacy creation is disabled once V2 is active.
        $this->workflowResolver->assertLegacyCreationAllowed();

        return DB::transaction(function () use ($data, $actor) {
            $orderDate = $data['order_date'] ?? now()->toDateString();

            $order = $this->labOrders->create([
                'order_number' => $this->orderNumbers->generate($orderDate),
                'clinic_id' => $data['clinic_id'],
                'doctor_id' => $data['doctor_id'],
                'patient_id' => $data['patient_id'] ?? null,
                'medical_record_number' => $data['medical_record_number'] ?? null,
                'order_date' => $orderDate,
                'due_date' => $data['due_date'] ?? null,
                'priority' => $data['priority'] ?? 'NORMAL',
                'status' => LabOrder::STATUS_RECEIVED,
                'workflow_version' => LabOrder::WORKFLOW_LEGACY,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $this->labOrders->syncItems($order, $this->mapItems($data['items'] ?? []));

            $this->statusLogs->log($order->id, null, LabOrder::STATUS_RECEIVED, 'Order created', $actor);

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $order->id,
                AuditLog::ACTION_CREATE,
                null,
                $this->snapshot($order),
                $actor,
            );

            return $order->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LabOrder $order, array $data, ?User $actor = null): LabOrder
    {
        $actor = $actor ?? auth()->user();

        $this->workflowResolver->assertLegacyMutable($order);

        if (! $order->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Order yang sudah selesai atau dibatalkan tidak dapat diedit.',
            ]);
        }

        return DB::transaction(function () use ($order, $data, $actor) {
            $oldValues = $this->snapshot($order);

            $this->labOrders->update($order, [
                'clinic_id' => $data['clinic_id'],
                'doctor_id' => $data['doctor_id'],
                'patient_id' => $data['patient_id'] ?? null,
                'medical_record_number' => $data['medical_record_number'] ?? null,
                'order_date' => $data['order_date'],
                'due_date' => $data['due_date'] ?? null,
                'priority' => $data['priority'],
                'notes' => $data['notes'] ?? null,
                'updated_by' => $actor?->id,
            ]);

            $this->labOrders->syncItems($order, $this->mapItems($data['items'] ?? []));

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $order->id,
                AuditLog::ACTION_UPDATE,
                $oldValues,
                $this->snapshot($order->refresh()),
                $actor,
            );

            return $order->refresh();
        });
    }

    public function cancel(LabOrder $order, string $reason, ?User $actor = null): LabOrder
    {
        $actor = $actor ?? auth()->user();

        $this->workflowResolver->assertLegacyMutable($order);

        if (! $order->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Order yang sudah selesai atau dibatalkan tidak dapat dibatalkan lagi.',
            ]);
        }

        return DB::transaction(function () use ($order, $reason, $actor) {
            $oldStatus = $order->status;

            $this->labOrders->update($order, [
                'status' => LabOrder::STATUS_CANCELLED,
                'updated_by' => $actor?->id,
            ]);

            $this->statusLogs->log($order->id, $oldStatus, LabOrder::STATUS_CANCELLED, $reason, $actor);

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $order->id,
                AuditLog::ACTION_CANCEL,
                ['status' => $oldStatus],
                ['status' => LabOrder::STATUS_CANCELLED, 'reason' => $reason],
                $actor,
            );

            return $order->refresh();
        });
    }

    public function delete(LabOrder $order): bool
    {
        return DB::transaction(fn () => $this->labOrders->softDelete($order));
    }

    /**
     * Normalize item payload and compute subtotal = quantity * unit_price.
     *
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

    /**
     * @return array<string, mixed>
     */
    private function snapshot(LabOrder $order): array
    {
        return [
            'order_number' => $order->order_number,
            'clinic_id' => $order->clinic_id,
            'doctor_id' => $order->doctor_id,
            'patient_id' => $order->patient_id,
            'medical_record_number' => $order->medical_record_number,
            'order_date' => optional($order->order_date)->toDateString(),
            'due_date' => optional($order->due_date)->toDateString(),
            'priority' => $order->priority,
            'status' => $order->status,
            'notes' => $order->notes,
        ];
    }
}
