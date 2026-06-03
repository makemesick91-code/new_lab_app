<?php

namespace App\Modules\Technician\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Technician\Models\Technician;
use App\Modules\Technician\Requests\StoreTechnicianRequest;
use App\Modules\Technician\Requests\UpdateTechnicianRequest;
use App\Modules\Technician\Services\TechnicianService;
use App\Modules\User\Services\UserService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TechnicianController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly TechnicianService $technicianService,
        private readonly UserService $userService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Technician::class);

        return view('settings.technicians.index', [
            'technicians' => $this->technicianService->list(
                ['search' => $request->string('search')->toString() ?: null],
                10
            ),
            'search' => $request->string('search')->toString(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Technician::class);

        return view('settings.technicians.create', ['users' => $this->userService->listAll()]);
    }

    public function store(StoreTechnicianRequest $request): RedirectResponse
    {
        $this->authorize('create', Technician::class);

        $this->technicianService->create($request->validated());

        return redirect()->route('settings.technicians.index')->with('status', 'Technician created successfully.');
    }

    public function edit(Technician $technician): View
    {
        $this->authorize('update', $technician);

        return view('settings.technicians.edit', [
            'technician' => $technician,
            'users' => $this->userService->listAll(),
        ]);
    }

    public function update(UpdateTechnicianRequest $request, Technician $technician): RedirectResponse
    {
        $this->authorize('update', $technician);

        $this->technicianService->update($technician, $request->validated());

        return redirect()->route('settings.technicians.index')->with('status', 'Technician updated successfully.');
    }

    public function destroy(Technician $technician): RedirectResponse
    {
        $this->authorize('delete', $technician);

        $this->technicianService->delete($technician);

        return redirect()->route('settings.technicians.index')->with('status', 'Technician deleted successfully.');
    }

    public function activate(Technician $technician): RedirectResponse
    {
        $this->authorize('update', $technician);

        $this->technicianService->activate($technician);

        return redirect()->route('settings.technicians.index')->with('status', 'Technician activated.');
    }

    public function deactivate(Technician $technician): RedirectResponse
    {
        $this->authorize('update', $technician);

        $this->technicianService->deactivate($technician);

        return redirect()->route('settings.technicians.index')->with('status', 'Technician deactivated.');
    }
}
