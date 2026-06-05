<?php

namespace App\Modules\Delivery\Services;

use App\Models\User;
use App\Modules\Delivery\Interfaces\DeliveryRepositoryInterface;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryService
{
    public function __construct(
        private readonly DeliveryRepositoryInterface $deliveries,
        private readonly DeliveryNumberGeneratorService $numberGenerator,
        private readonly DeliveryWorkflowService $workflow,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->deliveries->paginate($filters, $perPage);
    }

    public function readyOrders(array $filters = [], int $limit = 25)
    {
        $search = $filters['search'] ?? null;

        return LabOrder::query()
            ->with(['clinic', 'doctor', 'patient'])
            ->where('status', LabOrder::STATUS_QC_PASSED)
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(order_number) LIKE ?', [$term])
                        ->orWhereHas('clinic', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('doctor', fn ($d) => $d->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('patient', fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->orderByRaw("CASE priority WHEN 'SUPER_URGENT' THEN 0 WHEN 'URGENT' THEN 1 ELSE 2 END")
            ->orderBy('due_date')
            ->limit($limit)
            ->get();
    }

    public function find(int $id): ?Delivery
    {
        return $this->deliveries->find($id);
    }

    public function create(LabOrder $order, ?int $courierId, ?string $notes, ?User $actor = null): Delivery
    {
        $actor = $actor ?? auth()->user();

        if ($order->status !== LabOrder::STATUS_QC_PASSED) {
            throw ValidationException::withMessages(['lab_order_id' => 'Hanya order yang lulus QC yang dapat masuk pengiriman.']);
        }

        return DB::transaction(function () use ($order, $courierId, $notes, $actor) {
            $delivery = $this->deliveries->create([
                'lab_order_id' => $order->id,
                'delivery_number' => $this->numberGenerator->generate(),
                'courier_id' => $courierId,
                'status' => Delivery::STATUS_READY_FOR_DELIVERY,
                'delivery_notes' => $notes,
                'created_by' => $actor?->id,
            ]);

            $this->workflow->moveToReady($delivery, $notes, $actor);

            $this->auditLogs->log(
                Delivery::ENTITY_TYPE,
                $delivery->id,
                AuditLog::ACTION_CREATE_DELIVERY,
                null,
                ['lab_order_id' => $order->id, 'delivery_number' => $delivery->delivery_number],
                $actor,
            );

            if ($courierId) {
                $this->auditLogs->log(
                    Delivery::ENTITY_TYPE,
                    $delivery->id,
                    AuditLog::ACTION_ASSIGN_COURIER,
                    null,
                    ['courier_id' => $courierId],
                    $actor,
                );
            }

            return $delivery->refresh();
        });
    }

    public function assignCourier(Delivery $delivery, int $courierId, ?string $notes = null, ?User $actor = null): Delivery
    {
        $actor = $actor ?? auth()->user();

        if (! in_array($delivery->status, [Delivery::STATUS_READY_FOR_DELIVERY, Delivery::STATUS_IN_DELIVERY], true)) {
            throw ValidationException::withMessages(['status' => 'Kurir hanya dapat ditugaskan sebelum pengiriman selesai.']);
        }

        return DB::transaction(function () use ($delivery, $courierId, $notes, $actor) {
            $oldCourier = $delivery->courier_id;
            $updated = $this->deliveries->assignCourier($delivery, $courierId);
            $action = $oldCourier ? AuditLog::ACTION_REASSIGN_COURIER : AuditLog::ACTION_ASSIGN_COURIER;

            $this->auditLogs->log(
                Delivery::ENTITY_TYPE,
                $delivery->id,
                $action,
                ['courier_id' => $oldCourier],
                ['courier_id' => $courierId, 'notes' => $notes],
                $actor,
            );

            return $updated->refresh();
        });
    }

    public function reassignCourier(Delivery $delivery, int $courierId, string $notes, ?User $actor = null): Delivery
    {
        if (! $notes) {
            throw ValidationException::withMessages(['notes' => 'Catatan pergantian kurir wajib diisi.']);
        }

        return $this->assignCourier($delivery, $courierId, $notes, $actor);
    }
}
