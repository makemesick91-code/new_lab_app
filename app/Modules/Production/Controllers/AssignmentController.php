<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Production\Requests\AssignTechnicianRequest;
use App\Modules\Production\Requests\ReassignTechnicianRequest;
use App\Modules\Production\Services\AssignmentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

class AssignmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AssignmentService $assignmentService,
    ) {}

    public function store(AssignTechnicianRequest $request, LabOrder $labOrder): RedirectResponse
    {
        $this->authorize('production.assign', $labOrder);

        $data = $request->validated();
        $this->assignmentService->assign($labOrder, (int) $data['technician_id'], $data['notes'] ?? null);

        return redirect()->route('production.show', $labOrder)->with('status', 'Teknisi berhasil ditugaskan.');
    }

    public function reassign(ReassignTechnicianRequest $request, LabOrder $labOrder): RedirectResponse
    {
        $this->authorize('production.reassign', $labOrder);

        $data = $request->validated();
        $this->assignmentService->reassign($labOrder, (int) $data['technician_id'], $data['reason']);

        return redirect()->route('production.show', $labOrder)->with('status', 'Teknisi berhasil diganti.');
    }
}
