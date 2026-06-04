<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\ProductCategoryRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductUnitRepositoryInterface;
use App\Modules\Inventory\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryProductService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductCategoryRepositoryInterface $categories,
        private readonly ProductUnitRepositoryInterface $units,
        private readonly BranchContext $branchContext,
    ) {}

    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->products->paginate($this->branchContext->requireId(), $filters, $perPage);
    }

    public function listActive(): Collection
    {
        return $this->products->listActive($this->branchContext->requireId());
    }

    public function find(int $id): ?Product
    {
        return $this->products->findInBranch($this->branchContext->requireId(), $id);
    }

    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $branchId = $this->branchContext->requireId();
            $this->assertReferencesInBranch($branchId, $data);

            return $this->products->create(array_merge($data, [
                'branch_id' => $branchId,
            ]));
        });
    }

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $branchId = $this->branchContext->requireId();
            $this->assertProductInBranch($branchId, $product->id);
            $this->assertReferencesInBranch($branchId, $data);

            return $this->products->update($product, $data);
        });
    }

    public function deactivate(Product $product): Product
    {
        return DB::transaction(function () use ($product) {
            $this->assertProductInBranch($this->branchContext->requireId(), $product->id);

            return $this->products->deactivate($product);
        });
    }

    private function assertReferencesInBranch(int $branchId, array $data): void
    {
        if (! $this->categories->findInBranch($branchId, (int) $data['product_category_id'])) {
            throw ValidationException::withMessages([
                'product_category_id' => 'Product category tidak valid untuk branch aktif.',
            ]);
        }

        if (! $this->units->find((int) $data['product_unit_id'])) {
            throw ValidationException::withMessages([
                'product_unit_id' => 'Product unit tidak valid.',
            ]);
        }
    }

    private function assertProductInBranch(int $branchId, int $productId): Product
    {
        $product = $this->products->findInBranch($branchId, $productId);

        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => 'Product tidak valid untuk branch aktif.',
            ]);
        }

        return $product;
    }
}
