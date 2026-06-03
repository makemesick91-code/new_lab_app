<?php

namespace App\Modules\LabService\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LabService\Models\LabService;
use App\Modules\LabService\Requests\StoreLabServiceRequest;
use App\Modules\LabService\Requests\UpdateLabServiceRequest;
use App\Modules\LabService\Services\LabServiceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LabServiceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LabServiceService $labServiceService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', LabService::class);

        return view('settings.lab-services.index', [
            'labServices' => $this->labServiceService->list(
                ['search' => $request->string('search')->toString() ?: null],
                10
            ),
            'search' => $request->string('search')->toString(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', LabService::class);

        return view('settings.lab-services.create');
    }

    public function store(StoreLabServiceRequest $request): RedirectResponse
    {
        $this->authorize('create', LabService::class);

        $this->labServiceService->create($request->validated());

        return redirect()->route('settings.lab-services.index')->with('status', 'Lab service created successfully.');
    }

    public function edit(LabService $labService): View
    {
        $this->authorize('update', $labService);

        return view('settings.lab-services.edit', ['labService' => $labService]);
    }

    public function update(UpdateLabServiceRequest $request, LabService $labService): RedirectResponse
    {
        $this->authorize('update', $labService);

        $this->labServiceService->update($labService, $request->validated());

        return redirect()->route('settings.lab-services.index')->with('status', 'Lab service updated successfully.');
    }

    public function destroy(LabService $labService): RedirectResponse
    {
        $this->authorize('delete', $labService);

        $this->labServiceService->delete($labService);

        return redirect()->route('settings.lab-services.index')->with('status', 'Lab service deleted successfully.');
    }

    public function activate(LabService $labService): RedirectResponse
    {
        $this->authorize('update', $labService);

        $this->labServiceService->activate($labService);

        return redirect()->route('settings.lab-services.index')->with('status', 'Lab service activated.');
    }

    public function deactivate(LabService $labService): RedirectResponse
    {
        $this->authorize('update', $labService);

        $this->labServiceService->deactivate($labService);

        return redirect()->route('settings.lab-services.index')->with('status', 'Lab service deactivated.');
    }
}
