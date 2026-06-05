<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Requests\InventoryFilterRequest;
use App\Modules\Inventory\Requests\StoreInventoryLocationRequest;
use App\Modules\Inventory\Requests\UpdateInventoryLocationRequest;
use App\Modules\Inventory\Services\InventoryLocationService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class InventoryLocationController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventoryLocationService $locations,
    ) {}

    public function index(InventoryFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', InventoryLocation::class);

        return $this->renderInventoryView('inventory.locations.index', [
            'locations' => $this->locations->paginate($request->filters(), $request->perPage()),
            'filters' => $request->filters(),
            'types' => InventoryLocation::TYPES,
        ]);
    }

    public function create(): View|Response
    {
        $this->authorize('create', InventoryLocation::class);

        return $this->renderInventoryView('inventory.locations.create', [
            'types' => InventoryLocation::TYPES,
        ]);
    }

    public function store(StoreInventoryLocationRequest $request): RedirectResponse
    {
        $this->authorize('create', InventoryLocation::class);

        $this->locations->create($request->validated());

        return redirect()->route('inventory.locations.index')->with('status', 'Lokasi persediaan berhasil ditambahkan.');
    }

    public function show(InventoryLocation $location): View|Response
    {
        $this->authorize('view', $location);

        return $this->renderInventoryView('inventory.locations.show', [
            'location' => $location,
        ]);
    }

    public function edit(InventoryLocation $location): View|Response
    {
        $this->authorize('update', $location);

        return $this->renderInventoryView('inventory.locations.edit', [
            'location' => $location,
            'types' => InventoryLocation::TYPES,
        ]);
    }

    public function update(UpdateInventoryLocationRequest $request, InventoryLocation $location): RedirectResponse
    {
        $this->authorize('update', $location);

        $this->locations->update($location, $request->validated());

        return redirect()->route('inventory.locations.index')->with('status', 'Lokasi persediaan berhasil diperbarui.');
    }

    public function destroy(InventoryLocation $location): RedirectResponse
    {
        $this->authorize('delete', $location);

        $this->locations->deactivate($location);

        return redirect()->route('inventory.locations.index')->with('status', 'Lokasi persediaan berhasil dinonaktifkan.');
    }
}
