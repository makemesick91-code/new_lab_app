<?php

namespace App\Modules\QualityControl\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\QualityControl\Models\QualityControlChecklist;
use App\Modules\QualityControl\Requests\UpdateChecklistRequest;
use App\Modules\QualityControl\Services\ChecklistService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

class ChecklistController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ChecklistService $checklistService,
    ) {}

    public function update(UpdateChecklistRequest $request, QualityControlChecklist $checklist): RedirectResponse
    {
        $this->authorize('qc.checklists.update', $checklist);

        $this->checklistService->update($checklist, $request->validated());

        $order = $checklist->qualityControl->labOrder;

        return redirect()->route('quality-control.show', $order)->with('status', 'Checklist updated.');
    }
}
