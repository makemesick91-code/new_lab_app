<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Interfaces\ProductCategoryRepositoryInterface;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Requests\InventoryFilterRequest;
use App\Modules\Inventory\Requests\StoreProductCategoryRequest;
use App\Modules\Inventory\Requests\UpdateProductCategoryRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class ProductCategoryController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly ProductCategoryRepositoryInterface $categories,
        private readonly BranchContext $branchContext,
    ) {}

    public function index(InventoryFilterRequest $request): View|Response
    {
        $this->authorize('viewAny', ProductCategory::class);

        $categories = $this->categories->paginate(
            $this->branchContext->requireId(),
            $request->filters(),
            $request->perPage(),
        );

        return $this->renderInventoryView('inventory.product-categories.index', [
            'categories' => $categories,
            'filters' => $request->filters(),
        ]);
    }

    public function create(): View|Response
    {
        $this->authorize('create', ProductCategory::class);

        return $this->renderInventoryView('inventory.product-categories.create');
    }

    public function store(StoreProductCategoryRequest $request): RedirectResponse
    {
        $this->authorize('create', ProductCategory::class);

        $this->categories->create(array_merge($request->validated(), [
            'branch_id' => $this->branchContext->requireId(),
        ]));

        return redirect()
            ->route('inventory.product-categories.index')
            ->with('status', 'Kategori produk berhasil ditambahkan.');
    }

    public function edit(ProductCategory $productCategory): View|Response
    {
        $this->authorize('update', $productCategory);

        return $this->renderInventoryView('inventory.product-categories.edit', [
            'category' => $productCategory,
        ]);
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $productCategory): RedirectResponse
    {
        $this->authorize('update', $productCategory);

        $this->categories->update($productCategory, $request->validated());

        return redirect()
            ->route('inventory.product-categories.index')
            ->with('status', 'Kategori produk berhasil diperbarui.');
    }

    public function destroy(ProductCategory $productCategory): RedirectResponse
    {
        $this->authorize('delete', $productCategory);

        $this->categories->deactivate($productCategory);

        return redirect()
            ->route('inventory.product-categories.index')
            ->with('status', 'Kategori produk berhasil dinonaktifkan.');
    }
}
