<?php

namespace App\Modules\LabOrder\Interfaces;

use App\Modules\LabOrder\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface AuditLogRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AuditLog;

    public function forEntity(string $entityType, int $entityId, int $perPage = 15): LengthAwarePaginator;

    public function latestForEntity(string $entityType, int $entityId, int $limit = 10): Collection;
}
