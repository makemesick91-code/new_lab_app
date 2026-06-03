<?php

namespace App\Modules\LabOrder\Repositories;

use App\Modules\LabOrder\Interfaces\StatusLogRepositoryInterface;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use Illuminate\Support\Collection;

class StatusLogRepository implements StatusLogRepositoryInterface
{
    public function create(array $data): LabOrderStatusLog
    {
        return LabOrderStatusLog::create($data);
    }

    public function forLabOrder(int $labOrderId): Collection
    {
        return LabOrderStatusLog::query()
            ->where('lab_order_id', $labOrderId)
            ->with('changedBy')
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->get();
    }

    public function latestForLabOrder(int $labOrderId): ?LabOrderStatusLog
    {
        return LabOrderStatusLog::query()
            ->where('lab_order_id', $labOrderId)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->first();
    }
}
