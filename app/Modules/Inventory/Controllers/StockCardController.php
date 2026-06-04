<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Requests\StockCardFilterRequest;
use App\Modules\Inventory\Services\InventoryLocationService;
use App\Modules\Inventory\Services\InventoryStockService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;

class StockCardController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventoryStockService $stock,
        private readonly InventoryLocationService $locations,
    ) {}

    public function show(StockCardFilterRequest $request, Product $product): View|Response
    {
        $this->authorize('view', $product);
        $this->authorize('viewAny', InventoryMovement::class);

        $runningBalance = 0;
        $stockCard = $this->stock->getStockCard($product->id, $request->locationId(), $request->filters())
            ->map(function ($movement) use (&$runningBalance) {
                $runningBalance += (float) $movement->quantity_in - (float) $movement->quantity_out;
                $movement->running_balance = $runningBalance;

                return $movement;
            });

        return $this->renderInventoryView('inventory.stock.card', [
            'product' => $product->load(['category', 'unit']),
            'locations' => $this->locations->listActive(),
            'filters' => $request->validated(),
            'stockCard' => $stockCard,
            'currentStock' => $this->stock->getCurrentStock($product->id, $request->locationId()),
        ]);
    }
}
