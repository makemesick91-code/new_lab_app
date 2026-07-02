<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Requests\StoreInventoryBatchActionLogRequest;
use App\Modules\Inventory\Services\InventoryBatchActionLogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

class InventoryBatchActionLogController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly InventoryBatchActionLogService $actionLogs,
    ) {}

    public function store(StoreInventoryBatchActionLogRequest $request, InventoryBatch $inventoryBatch): RedirectResponse
    {
        $this->authorize('recordAction', $inventoryBatch);

        $this->actionLogs->record(
            $inventoryBatch,
            $request->validated('action_type'),
            $request->validated('note'),
        );

        return back()->with('status', 'Tindakan batch berhasil dicatat. Stok tidak berubah — gunakan adjustment/opname resmi untuk pengurangan stok.');
    }
}
