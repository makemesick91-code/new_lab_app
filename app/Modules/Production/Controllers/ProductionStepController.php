<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Production\Models\ProductionStep;
use App\Modules\Production\Requests\UpdateProductionStepRequest;
use App\Modules\Production\Services\ProductionStepService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

class ProductionStepController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ProductionStepService $stepService,
    ) {}

    public function update(UpdateProductionStepRequest $request, LabOrder $labOrder, ProductionStep $step): RedirectResponse
    {
        $this->authorize('production.steps.update', $labOrder);

        abort_unless((int) $step->lab_order_id === $labOrder->id, 404);

        $this->stepService->update($step, $request->validated());

        return redirect()->route('production.show', $labOrder)->with('status', 'Tahap produksi berhasil diperbarui.');
    }
}
