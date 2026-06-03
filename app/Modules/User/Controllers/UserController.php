<?php

namespace App\Modules\User\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AccessControl\Services\RoleService;
use App\Modules\User\Requests\StoreUserRequest;
use App\Modules\User\Requests\UpdateUserRequest;
use App\Modules\User\Services\UserService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Thin controller: validates input, delegates to services, returns response
 * (PROJECT_RULES §7). No business logic, no direct DB queries.
 */
class UserController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly UserService $userService,
        private readonly RoleService $roleService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $users = $this->userService->list(
            ['search' => $request->string('search')->toString() ?: null],
            10
        );

        return view('settings.users.index', [
            'users' => $users,
            'search' => $request->string('search')->toString(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('settings.users.create', [
            'roles' => $this->roleService->listAll(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $this->userService->create($request->validated());

        return redirect()
            ->route('settings.users.index')
            ->with('status', 'User created successfully.');
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('settings.users.edit', [
            'user' => $user->load('roles'),
            'roles' => $this->roleService->listAll(),
            'currentRole' => $user->getRoleNames()->first(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $this->userService->update($user, $request->validated());

        return redirect()
            ->route('settings.users.index')
            ->with('status', 'User updated successfully.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $this->userService->delete($user);

        return redirect()
            ->route('settings.users.index')
            ->with('status', 'User deleted successfully.');
    }

    public function activate(User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $this->userService->activate($user);

        return redirect()
            ->route('settings.users.index')
            ->with('status', 'User activated.');
    }

    public function deactivate(User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $this->userService->deactivate($user);

        return redirect()
            ->route('settings.users.index')
            ->with('status', 'User deactivated.');
    }
}
