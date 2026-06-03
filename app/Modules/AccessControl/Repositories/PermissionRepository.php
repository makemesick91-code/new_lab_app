<?php

namespace App\Modules\AccessControl\Repositories;

use App\Modules\AccessControl\Interfaces\PermissionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/**
 * Data-access only for permissions (PROJECT_RULES §9).
 */
class PermissionRepository implements PermissionRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return Permission::query()
            ->withCount('roles')
            ->when($search, fn ($query, $search) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listAll(): Collection
    {
        return Permission::orderBy('name')->get();
    }
}
