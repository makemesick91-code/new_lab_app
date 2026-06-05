<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Requests\InventoryFilterRequest;
use App\Modules\Inventory\Requests\StoreProductRequest;
use App\Modules\Inventory\Requests\UpdateProductRequest;
use App\Modules\Inventory\Services\InventoryProductService;
use App\Modules\Inventory\Services\InventoryStockService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly InventoryProductService $products,
        private readonly InventoryStockService $stock,
    ) {}

    public function index(InventoryFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', Product::class);

        $products = $this->products->paginate($request->filters(), $request->perPage());
        $currentStocks = $this->stock->getCurrentStockByBranch()->pluck('current_stock', 'product_id');

        return $this->renderInventoryView('inventory.products.index', [
            'products' => $products,
            'currentStocks' => $currentStocks,
            'filters' => $request->filters(),
        ]);
    }

    public function create(): View|Response
    {
        $this->authorize('create', Product::class);

        return $this->renderInventoryView('inventory.products.create', [
            'categories' => $this->products->listActiveCategories(),
            'units' => $this->products->listActiveUnits(),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $product = $this->products->create($request->validated());

        return redirect()->route('inventory.products.show', $product)->with('status', 'Produk berhasil ditambahkan.');
    }

    public function show(Product $product): View|Response
    {
        $this->authorize('view', $product);

        return $this->renderInventoryView('inventory.products.show', [
            'product' => $product->load(['category', 'unit']),
            'currentStock' => $this->stock->getCurrentStock($product->id),
        ]);
    }

    public function edit(Product $product): View|Response
    {
        $this->authorize('update', $product);

        return $this->renderInventoryView('inventory.products.edit', [
            'product' => $product->load(['category', 'unit']),
            'categories' => $this->products->listActiveCategories(),
            'units' => $this->products->listActiveUnits(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $this->products->update($product, $request->validated());

        return redirect()->route('inventory.products.show', $product)->with('status', 'Produk berhasil diperbarui.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        $this->products->deactivate($product);

        return redirect()->route('inventory.products.index')->with('status', 'Produk berhasil dinonaktifkan.');
    }
}
