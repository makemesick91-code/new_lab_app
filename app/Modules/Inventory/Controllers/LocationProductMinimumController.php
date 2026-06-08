<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\LocationProductMinimum;
use App\Modules\Inventory\Requests\InventoryFilterRequest;
use App\Modules\Inventory\Requests\StoreLocationProductMinimumRequest;
use App\Modules\Inventory\Requests\UpdateLocationProductMinimumRequest;
use App\Modules\Inventory\Services\InventoryLocationService;
use App\Modules\Inventory\Services\InventoryProductService;
use App\Modules\Inventory\Services\LocationProductMinimumService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Sprint 17.8 — CRUD for per-room minimum/maximum stock thresholds.
 *
 * Manages threshold CONFIGURATION only. branch_id is never accepted from input;
 * the service forces it through BranchContext. This controller never reads or
 * writes stock balances — current stock stays ledger-derived from
 * trx_inventory_movements.
 */
class LocationProductMinimumController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly LocationProductMinimumService $minimums,
        private readonly InventoryProductService $products,
        private readonly InventoryLocationService $locations,
    ) {}

    public function index(InventoryFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', LocationProductMinimum::class);

        return $this->renderInventoryView('inventory.location-minimums.index', [
            'minimums' => $this->minimums->paginate($request->filters(), $request->perPage()),
            'filters' => $request->filters(),
        ]);
    }

    public function create(): View|Response
    {
        $this->authorize('create', LocationProductMinimum::class);

        return $this->renderInventoryView('inventory.location-minimums.create', [
            'products' => $this->products->listActive(),
            'locations' => $this->locations->listActive(),
        ]);
    }

    public function store(StoreLocationProductMinimumRequest $request): RedirectResponse
    {
        $this->authorize('create', LocationProductMinimum::class);

        $this->minimums->create($request->validated());

        return redirect()
            ->route('inventory.location-minimums.index')
            ->with('status', 'Konfigurasi stok minimum berhasil ditambahkan.');
    }

    public function edit(LocationProductMinimum $locationProductMinimum): View|Response
    {
        $this->authorize('update', $locationProductMinimum);

        return $this->renderInventoryView('inventory.location-minimums.edit', [
            'minimum' => $locationProductMinimum,
            'products' => $this->products->listActive(),
            'locations' => $this->locations->listActive(),
        ]);
    }

    public function update(UpdateLocationProductMinimumRequest $request, LocationProductMinimum $locationProductMinimum): RedirectResponse
    {
        $this->authorize('update', $locationProductMinimum);

        $this->minimums->update($locationProductMinimum, $request->validated());

        return redirect()
            ->route('inventory.location-minimums.index')
            ->with('status', 'Konfigurasi stok minimum berhasil diperbarui.');
    }

    public function destroy(LocationProductMinimum $locationProductMinimum): RedirectResponse
    {
        $this->authorize('delete', $locationProductMinimum);

        $this->minimums->delete($locationProductMinimum);

        return redirect()
            ->route('inventory.location-minimums.index')
            ->with('status', 'Konfigurasi stok minimum berhasil dinonaktifkan.');
    }
}
