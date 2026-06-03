<?php

namespace App\Modules\Production\Repositories;

use App\Modules\Production\Interfaces\WorkLogRepositoryInterface;
use App\Modules\Production\Models\WorkLog;
use Illuminate\Support\Collection;

class WorkLogRepository implements WorkLogRepositoryInterface
{
    public function create(array $data): WorkLog
    {
        return WorkLog::create($data);
    }

    public function forAssignment(int $assignmentId): Collection
    {
        return WorkLog::query()
            ->where('assignment_id', $assignmentId)
            ->with('performedBy')
            ->orderByDesc('id')
            ->get();
    }

    public function forLabOrder(int $labOrderId): Collection
    {
        return WorkLog::query()
            ->whereHas('assignment', fn ($q) => $q->where('lab_order_id', $labOrderId))
            ->with(['performedBy', 'assignment.technician'])
            ->orderByDesc('id')
            ->get();
    }

    public function latestForAssignment(int $assignmentId): ?WorkLog
    {
        return WorkLog::query()
            ->where('assignment_id', $assignmentId)
            ->orderByDesc('id')
            ->first();
    }
}
