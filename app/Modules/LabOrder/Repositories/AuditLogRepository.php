<?php

namespace App\Modules\LabOrder\Repositories;

use App\Modules\LabOrder\Interfaces\AuditLogRepositoryInterface;
use App\Modules\LabOrder\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AuditLogRepository implements AuditLogRepositoryInterface
{
    public function create(array $data): AuditLog
    {
        return AuditLog::create($data);
    }

    public function forEntity(string $entityType, int $entityId, int $perPage = 15): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->with('performer')
            ->orderByDesc('performed_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function latestForEntity(string $entityType, int $entityId, int $limit = 10): Collection
    {
        return AuditLog::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->with('performer')
            ->orderByDesc('performed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
