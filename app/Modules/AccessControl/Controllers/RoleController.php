<?php

namespace App\Modules\AccessControl\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Requests\StoreRoleRequest;
use App\Modules\AccessControl\Requests\UpdateRoleRequest;
use App\Modules\AccessControl\Services\PermissionService;
use App\Modules\AccessControl\Services\RoleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Role management + permission assignment (TASK-0102, TASK-0103).
 */
class RoleController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RoleService $roleService,
        private readonly PermissionService $permissionService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Role::class);

        return view('settings.roles.index', [
            'roles' => $this->roleService->list(
                ['search' => $request->string('search')->toString() ?: null],
                10
            ),
            'search' => $request->string('search')->toString(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Role::class);

        return view('settings.roles.create', [
            'permissions' => $this->permissionService->listAll(),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $this->roleService->create($request->validated());

        return redirect()
            ->route('settings.roles.index')
            ->with('status', 'Role berhasil dibuat.');
    }

    public function edit(Role $role): View
    {
        $this->authorize('update', $role);

        return view('settings.roles.edit', [
            'role' => $role->load('permissions'),
            'permissions' => $this->permissionService->listAll(),
            'assignedPermissions' => $role->permissions->pluck('name')->all(),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);

        $this->roleService->update($role, $request->validated());

        return redirect()
            ->route('settings.roles.index')
            ->with('status', 'Role berhasil diperbarui.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        $this->roleService->delete($role);

        return redirect()
            ->route('settings.roles.index')
            ->with('status', 'Role berhasil dihapus.');
    }
}
