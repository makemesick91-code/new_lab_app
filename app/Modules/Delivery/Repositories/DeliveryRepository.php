<?php

namespace App\Modules\Delivery\Repositories;

use App\Modules\Delivery\Interfaces\DeliveryRepositoryInterface;
use App\Modules\Delivery\Models\Delivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DeliveryRepository implements DeliveryRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return Delivery::query()
            ->with(['labOrder.clinic', 'labOrder.doctor', 'labOrder.patient', 'courier'])
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(delivery_number) LIKE ?', [$term])
                        ->orWhereHas('labOrder', fn ($o) => $o->whereRaw('LOWER(order_number) LIKE ?', [$term]))
                        ->orWhereHas('labOrder.clinic', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('labOrder.doctor', fn ($d) => $d->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('labOrder.patient', fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('courier', fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['courier_id'] ?? null, fn ($q, $v) => $q->where('courier_id', $v))
            ->when($filters['clinic_id'] ?? null, fn ($q, $v) => $q->whereHas('labOrder', fn ($o) => $o->where('clinic_id', $v)))
            ->when($filters['doctor_id'] ?? null, fn ($q, $v) => $q->whereHas('labOrder', fn ($o) => $o->where('doctor_id', $v)))
            ->when($filters['patient_id'] ?? null, fn ($q, $v) => $q->whereHas('labOrder', fn ($o) => $o->where('patient_id', $v)))
            ->when($filters['due_date'] ?? null, fn ($q, $v) => $q->whereHas('labOrder', fn ($o) => $o->whereDate('due_date', $v)))
            // Sprint 9 — Multi Branch Foundation: opt-in branch scope (NOT enforced).
            // Only applies when branch_id is explicitly passed; no caller passes it today.
            // TODO(branch-scope): derive branch_id from the authenticated user's branch
            // (Super Admin bypass) to enforce per-branch delivery isolation.
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->orderByRaw("CASE status WHEN 'IN_DELIVERY' THEN 0 WHEN 'READY_FOR_DELIVERY' THEN 1 WHEN 'DELIVERED' THEN 2 ELSE 3 END")
            ->orderByDesc('id')
            ->paginate(min($perPage, 100))
            ->withQueryString();
    }

    public function find(int $id): ?Delivery
    {
        return Delivery::query()
            ->with([
                'labOrder.clinic',
                'labOrder.doctor',
                'labOrder.patient',
                'labOrder.items.labService',
                'labOrder.statusLogs.changedBy',
                'courier',
                'creator',
                'attachments.uploader',
                'auditLogs.performer',
            ])
            ->find($id);
    }

    public function create(array $data): Delivery
    {
        return Delivery::create($data);
    }

    public function update(Delivery $delivery, array $data): Delivery
    {
        $delivery->update($data);

        return $delivery->refresh();
    }

    public function assignCourier(Delivery $delivery, int $courierId): Delivery
    {
        return $this->update($delivery, ['courier_id' => $courierId]);
    }

    public function reassignCourier(Delivery $delivery, int $courierId): Delivery
    {
        return $this->assignCourier($delivery, $courierId);
    }

    public function startDelivery(Delivery $delivery, array $data = []): Delivery
    {
        return $this->update($delivery, array_merge($data, [
            'status' => Delivery::STATUS_IN_DELIVERY,
            'started_at' => $data['started_at'] ?? now(),
        ]));
    }

    public function markDelivered(Delivery $delivery, array $data): Delivery
    {
        return $this->update($delivery, array_merge($data, [
            'status' => Delivery::STATUS_DELIVERED,
        ]));
    }

    public function completeDelivery(Delivery $delivery, array $data = []): Delivery
    {
        return $this->update($delivery, array_merge($data, [
            'status' => Delivery::STATUS_COMPLETED,
            'completed_at' => $data['completed_at'] ?? now(),
        ]));
    }

    public function latestDeliveryNumberForYear(string $year): ?string
    {
        return Delivery::query()
            ->where('delivery_number', 'like', "DLV-{$year}-%")
            ->orderByDesc('delivery_number')
            ->value('delivery_number');
    }
}
