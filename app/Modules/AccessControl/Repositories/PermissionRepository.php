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
    /**
     * Alphabetical, paginated permission listing.
     *
     * CICD-FIX-4 — `name` is NOT unique on its own: the permissions table is
     * unique on (name, guard_name), so two rows can legitimately share a name.
     * Ordering by `name` alone therefore leaves the relative order of those rows
     * undefined, and a row could be served on two pages or on none. `id` is the
     * stable unique tie-breaker; the business ordering stays alphabetical.
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return Permission::query()
            ->withCount('roles')
            ->when($search, fn ($query, $search) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listAll(): Collection
    {
        return Permission::orderBy('name')->get();
    }
}
