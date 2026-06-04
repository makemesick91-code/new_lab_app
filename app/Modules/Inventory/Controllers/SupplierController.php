<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Requests\InventoryFilterRequest;
use App\Modules\Inventory\Requests\StoreSupplierRequest;
use App\Modules\Inventory\Requests\UpdateSupplierRequest;
use App\Modules\Inventory\Services\InventorySupplierService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class SupplierController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventorySupplierService $suppliers,
    ) {}

    public function index(InventoryFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', Supplier::class);

        return $this->renderInventoryView('inventory.suppliers.index', [
            'suppliers' => $this->suppliers->paginate($request->filters(), $request->perPage()),
            'filters' => $request->filters(),
        ]);
    }

    public function create(): View|Response
    {
        $this->authorize('create', Supplier::class);

        return $this->renderInventoryView('inventory.suppliers.create');
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $supplier = $this->suppliers->create($request->validated());

        return redirect()->route('inventory.suppliers.show', $supplier)->with('status', 'Supplier created.');
    }

    public function show(Supplier $supplier): View|Response
    {
        $this->authorize('view', $supplier);

        return $this->renderInventoryView('inventory.suppliers.show', [
            'supplier' => $supplier,
        ]);
    }

    public function edit(Supplier $supplier): View|Response
    {
        $this->authorize('update', $supplier);

        return $this->renderInventoryView('inventory.suppliers.edit', [
            'supplier' => $supplier,
        ]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $this->suppliers->update($supplier, $request->validated());

        return redirect()->route('inventory.suppliers.show', $supplier)->with('status', 'Supplier updated.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $this->authorize('delete', $supplier);

        $this->suppliers->deactivate($supplier);

        return redirect()->route('inventory.suppliers.index')->with('status', 'Supplier deactivated.');
    }
}
