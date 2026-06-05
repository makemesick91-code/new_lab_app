<?php

namespace App\Modules\QualityControl\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\QualityControl\Requests\RequestRemakeRequest;
use App\Modules\QualityControl\Services\QualityWorkflowService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

class RemakeController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly QualityWorkflowService $workflow,
    ) {}

    public function store(RequestRemakeRequest $request, LabOrder $labOrder): RedirectResponse
    {
        $this->authorize('qc.requestRemake', $labOrder);

        $data = $request->validated();
        $this->workflow->requestRemake($labOrder, $data['reason'], $data['notes']);

        return redirect()->route('quality-control.show', $labOrder)->with('status', 'Permintaan perbaikan berhasil dibuat.');
    }
}
