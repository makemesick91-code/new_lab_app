<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\LabOrder\Interfaces\AuditLogRepositoryInterface;
use App\Modules\LabOrder\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Centralized audit log creation (must not be bypassed for important mutations).
 */
class AuditLogService
{
    public function __construct(
        private readonly AuditLogRepositoryInterface $auditLogs,
    ) {}

    public function paginateForEntity(string $entityType, int $entityId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->auditLogs->forEntity($entityType, $entityId, $perPage);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $entityType,
        ?int $entityId,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $actor = null,
    ): AuditLog {
        $actor = $actor ?? auth()->user();

        return $this->auditLogs->create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'performed_by' => $actor?->id,
            'performed_at' => now(),
            'ip_address' => optional(request())->ip(),
            'user_agent' => optional(request())->userAgent(),
        ]);
    }
}
