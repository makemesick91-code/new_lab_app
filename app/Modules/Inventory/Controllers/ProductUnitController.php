<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Interfaces\ProductUnitRepositoryInterface;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Requests\InventoryFilterRequest;
use App\Modules\Inventory\Requests\StoreProductUnitRequest;
use App\Modules\Inventory\Requests\UpdateProductUnitRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class ProductUnitController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly ProductUnitRepositoryInterface $units,
    ) {}

    public function index(InventoryFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', ProductUnit::class);

        return $this->renderInventoryView('inventory.product-units.index', [
            'units' => $this->units->paginate($request->filters(), $request->perPage()),
            'filters' => $request->filters(),
        ]);
    }

    public function create(): View|Response
    {
        $this->authorize('create', ProductUnit::class);

        return $this->renderInventoryView('inventory.product-units.create');
    }

    public function store(StoreProductUnitRequest $request): RedirectResponse
    {
        $this->authorize('create', ProductUnit::class);

        $this->units->create($request->validated());

        return redirect()
            ->route('inventory.product-units.index')
            ->with('status', 'Satuan produk berhasil ditambahkan.');
    }

    public function edit(ProductUnit $productUnit): View|Response
    {
        $this->authorize('update', $productUnit);

        return $this->renderInventoryView('inventory.product-units.edit', [
            'unit' => $productUnit,
        ]);
    }

    public function update(UpdateProductUnitRequest $request, ProductUnit $productUnit): RedirectResponse
    {
        $this->authorize('update', $productUnit);

        $this->units->update($productUnit, $request->validated());

        return redirect()
            ->route('inventory.product-units.index')
            ->with('status', 'Satuan produk berhasil diperbarui.');
    }

    public function destroy(ProductUnit $productUnit): RedirectResponse
    {
        $this->authorize('delete', $productUnit);

        $this->units->deactivate($productUnit);

        return redirect()
            ->route('inventory.product-units.index')
            ->with('status', 'Satuan produk berhasil dinonaktifkan.');
    }
}
