<?php

namespace App\Modules\AccessControl\Services;

use App\Modules\AccessControl\Interfaces\PermissionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Business logic for permission listing (TASK-0103).
 */
class PermissionService
{
    public function __construct(
        private readonly PermissionRepositoryInterface $permissions,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->permissions->paginate($filters, $perPage);
    }

    public function listAll(): Collection
    {
        return $this->permissions->listAll();
    }
}
