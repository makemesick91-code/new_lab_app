<?php

namespace App\Modules\Clinic\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Clinic\Requests\StoreClinicRequest;
use App\Modules\Clinic\Requests\UpdateClinicRequest;
use App\Modules\Clinic\Services\ClinicService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClinicController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ClinicService $clinicService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Clinic::class);

        return view('settings.clinics.index', [
            'clinics' => $this->clinicService->list(
                ['search' => $request->string('search')->toString() ?: null],
                10
            ),
            'search' => $request->string('search')->toString(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Clinic::class);

        return view('settings.clinics.create');
    }

    public function store(StoreClinicRequest $request): RedirectResponse
    {
        $this->authorize('create', Clinic::class);

        $this->clinicService->create($request->validated());

        return redirect()->route('settings.clinics.index')->with('status', 'Clinic created successfully.');
    }

    public function edit(Clinic $clinic): View
    {
        $this->authorize('update', $clinic);

        return view('settings.clinics.edit', ['clinic' => $clinic]);
    }

    public function update(UpdateClinicRequest $request, Clinic $clinic): RedirectResponse
    {
        $this->authorize('update', $clinic);

        $this->clinicService->update($clinic, $request->validated());

        return redirect()->route('settings.clinics.index')->with('status', 'Clinic updated successfully.');
    }

    public function destroy(Clinic $clinic): RedirectResponse
    {
        $this->authorize('delete', $clinic);

        $this->clinicService->delete($clinic);

        return redirect()->route('settings.clinics.index')->with('status', 'Clinic deleted successfully.');
    }

    public function activate(Clinic $clinic): RedirectResponse
    {
        $this->authorize('update', $clinic);

        $this->clinicService->activate($clinic);

        return redirect()->route('settings.clinics.index')->with('status', 'Clinic activated.');
    }

    public function deactivate(Clinic $clinic): RedirectResponse
    {
        $this->authorize('update', $clinic);

        $this->clinicService->deactivate($clinic);

        return redirect()->route('settings.clinics.index')->with('status', 'Clinic deactivated.');
    }
}
