<?php

namespace App\Modules\AccessControl\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Permission listing (TASK-0103). Permissions are defined in code/seeder;
 * this screen lets an admin review them and see how many roles use each.
 */
class PermissionController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissionService,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('manage permissions');

        return view('settings.permissions.index', [
            'permissions' => $this->permissionService->list(
                ['search' => $request->string('search')->toString() ?: null],
                50
            ),
            'search' => $request->string('search')->toString(),
        ]);
    }
}
