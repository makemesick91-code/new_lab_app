<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Requests\InventoryBatchFilterRequest;
use App\Modules\Inventory\Services\InventoryBatchService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;

class InventoryBatchController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventoryBatchService $batches,
    ) {}

    public function index(InventoryBatchFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', InventoryBatch::class);

        return $this->renderInventoryView('inventory.batches.index', [
            'batches' => $this->batches->paginate($request->filters(), $request->perPage()),
            'products' => $this->batches->listActiveProducts(),
            'suppliers' => $this->batches->listActiveSuppliers(),
            'filters' => $request->filters(),
        ]);
    }

    public function show(InventoryBatch $inventoryBatch): View|Response
    {
        $this->authorize('view', $inventoryBatch);

        $showData = $this->batches->showData($inventoryBatch);

        return $this->renderInventoryView('inventory.batches.show', $showData);
    }
}
