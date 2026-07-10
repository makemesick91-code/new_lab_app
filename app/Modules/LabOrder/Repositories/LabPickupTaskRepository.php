<?php

namespace App\Modules\LabOrder\Repositories;

use App\Models\User;
use App\Modules\LabOrder\Interfaces\LabPickupTaskRepositoryInterface;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabPickupTask;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class LabPickupTaskRepository implements LabPickupTaskRepositoryInterface
{
    public function firstOrCreateForOrder(LabOrder $order, int $branchId, User $creator): LabPickupTask
    {
        return LabPickupTask::query()->firstOrCreate(
            ['lab_order_id' => $order->id],
            [
                'branch_id' => $branchId,
                'status' => LabPickupTask::STATUS_PENDING,
                'created_by' => $creator->id,
            ],
        );
    }

    public function findDetailById(int $id): ?LabPickupTask
    {
        return LabPickupTask::query()
            ->with([
                'labOrder.items.labService',
                'labOrder.clinic',
                'labOrder.workflowEvidence',
                'branch',
                'courier',
                'receiver',
            ])
            ->find($id);
    }

    public function lockById(int $id): ?LabPickupTask
    {
        return LabPickupTask::query()->lockForUpdate()->find($id);
    }

    public function queue(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return LabPickupTask::query()
            ->with(['labOrder:id,order_number,due_date,priority,status', 'branch:id,code,name', 'courier:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['courier_id'] ?? null, fn ($q, $courierId) => $q->where('courier_id', $courierId))
            ->when($filters['branch_id'] ?? null, fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->when(
                ($filters['active_only'] ?? false) === true,
                fn ($q) => $q->whereIn('status', LabPickupTask::ACTIVE_STATUSES),
            )
            ->orderByRaw("case status when 'PENDING' then 0 when 'ACCEPTED' then 1 when 'PICKED_UP' then 2 when 'IN_TRANSIT' then 3 else 4 end")
            ->orderBy('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function update(LabPickupTask $task, array $data): LabPickupTask
    {
        $task->update($data);

        return $task->refresh();
    }
}
