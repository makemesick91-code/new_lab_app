<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\LocationProductMinimumRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\LocationProductMinimum;
use App\Modules\Inventory\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 17.8 — business rules for per-room minimum/maximum stock thresholds.
 *
 * Manages threshold CONFIGURATION rows only. This service never creates or reads
 * inventory movements, never calculates or persists current stock, and never
 * touches inv_products.minimum_stock — current stock stays ledger-derived from
 * trx_inventory_movements (SUM(in) - SUM(out)). Every operation is branch-scoped
 * through BranchContext; branch_id is never sourced from request input.
 */
class LocationProductMinimumService
{
    public function __construct(
        private readonly LocationProductMinimumRepositoryInterface $minimums,
        private readonly InventoryLocationRepositoryInterface $locations,
        private readonly ProductRepositoryInterface $products,
        private readonly BranchContext $branchContext,
    ) {}

    /**
     * @param  array{inventory_location_id?: int, product_id?: int, is_active?: bool, search?: string}  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->minimums->paginateForBranch($this->branchContext->requireId(), $filters, $perPage);
    }

    public function find(int $id): ?LocationProductMinimum
    {
        return $this->minimums->findInBranch($this->branchContext->requireId(), $id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): LocationProductMinimum
    {
        return DB::transaction(function () use ($data) {
            $branchId = $this->branchContext->requireId();
            $locationId = (int) $data['inventory_location_id'];
            $productId = (int) $data['product_id'];

            $this->assertLocationBelongsToBranch($branchId, $locationId);
            $this->assertProductBelongsToBranch($branchId, $productId);
            $this->assertThresholdQuantities($data);

            $existing = $this->minimums->findByLocationAndProduct($branchId, $locationId, $productId);

            if ($existing !== null) {
                if ($existing->is_active) {
                    throw ValidationException::withMessages([
                        'product_id' => 'Konfigurasi stok minimum untuk lokasi dan produk ini sudah ada.',
                    ]);
                }

                // Reactivate the deactivated threshold rather than inserting a second row:
                // the (branch_id, inventory_location_id, product_id) unique key forbids a duplicate.
                return $this->minimums->update($existing, array_merge(
                    $this->normalizePayload($data, $branchId),
                    ['is_active' => true, 'created_by' => Auth::id()],
                ));
            }

            return $this->minimums->create(array_merge(
                $this->normalizePayload($data, $branchId),
                ['created_by' => Auth::id()],
            ));
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LocationProductMinimum|int $minimum, array $data): LocationProductMinimum
    {
        return DB::transaction(function () use ($minimum, $data) {
            $branchId = $this->branchContext->requireId();
            $model = $this->resolveInBranch($minimum, $branchId);

            $locationId = array_key_exists('inventory_location_id', $data)
                ? (int) $data['inventory_location_id']
                : (int) $model->inventory_location_id;
            $productId = array_key_exists('product_id', $data)
                ? (int) $data['product_id']
                : (int) $model->product_id;

            if (array_key_exists('inventory_location_id', $data)) {
                $this->assertLocationBelongsToBranch($branchId, $locationId);
            }

            if (array_key_exists('product_id', $data)) {
                $this->assertProductBelongsToBranch($branchId, $productId);
            }

            $this->assertThresholdQuantities([
                'minimum_stock' => $data['minimum_stock'] ?? $model->minimum_stock,
                'maximum_stock' => array_key_exists('maximum_stock', $data)
                    ? $data['maximum_stock']
                    : $model->maximum_stock,
            ]);

            $this->assertUniqueThreshold($branchId, $locationId, $productId, $model->id);

            return $this->minimums->update($model, $this->normalizePayload($data, $branchId));
        });
    }

    public function delete(LocationProductMinimum|int $minimum): LocationProductMinimum
    {
        return DB::transaction(function () use ($minimum) {
            $branchId = $this->branchContext->requireId();
            $model = $this->resolveInBranch($minimum, $branchId);

            return $this->minimums->delete($model);
        });
    }

    public function getActiveForLocation(int $inventoryLocationId): Collection
    {
        $branchId = $this->branchContext->requireId();
        $this->ensureLocationInBranch($branchId, $inventoryLocationId);

        return $this->minimums->getActiveForLocation($branchId, $inventoryLocationId);
    }

    /**
     * Builds the persistable attribute set. branch_id is always forced from
     * BranchContext, never taken from $data, so a request can never reassign branch.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePayload(array $data, int $branchId): array
    {
        $payload = ['branch_id' => $branchId];

        if (array_key_exists('inventory_location_id', $data)) {
            $payload['inventory_location_id'] = (int) $data['inventory_location_id'];
        }

        if (array_key_exists('product_id', $data)) {
            $payload['product_id'] = (int) $data['product_id'];
        }

        if (array_key_exists('minimum_stock', $data)) {
            $payload['minimum_stock'] = $data['minimum_stock'];
        }

        if (array_key_exists('maximum_stock', $data)) {
            $payload['maximum_stock'] = ($data['maximum_stock'] === null || $data['maximum_stock'] === '')
                ? null
                : $data['maximum_stock'];
        }

        return $payload;
    }

    private function resolveInBranch(LocationProductMinimum|int $minimum, int $branchId): LocationProductMinimum
    {
        $id = $minimum instanceof LocationProductMinimum ? $minimum->id : (int) $minimum;
        $model = $this->minimums->findInBranch($branchId, $id);

        if ($model === null) {
            throw ValidationException::withMessages([
                'location_product_minimum' => 'Konfigurasi stok minimum tidak ditemukan di cabang aktif.',
            ]);
        }

        return $model;
    }

    private function ensureLocationInBranch(int $branchId, int $locationId): InventoryLocation
    {
        $location = $this->locations->findInBranch($branchId, $locationId);

        if ($location === null) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Lokasi persediaan tidak valid untuk cabang aktif.',
            ]);
        }

        return $location;
    }

    private function assertLocationBelongsToBranch(int $branchId, int $locationId): InventoryLocation
    {
        $location = $this->ensureLocationInBranch($branchId, $locationId);

        if (! $location->is_active) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Lokasi persediaan tidak aktif.',
            ]);
        }

        return $location;
    }

    private function assertProductBelongsToBranch(int $branchId, int $productId): Product
    {
        $product = $this->products->findInBranch($branchId, $productId);

        if ($product === null) {
            throw ValidationException::withMessages([
                'product_id' => 'Produk tidak valid untuk cabang aktif.',
            ]);
        }

        if (! $product->is_active) {
            throw ValidationException::withMessages([
                'product_id' => 'Produk tidak aktif.',
            ]);
        }

        return $product;
    }

    /**
     * @param  array<string, mixed>  $data  Must carry effective minimum_stock / maximum_stock.
     */
    private function assertThresholdQuantities(array $data): void
    {
        $minimum = $data['minimum_stock'] ?? null;

        if (! is_numeric($minimum) || (float) $minimum <= 0) {
            throw ValidationException::withMessages([
                'minimum_stock' => 'Stok minimum harus berupa angka lebih besar dari nol.',
            ]);
        }

        $maximum = $data['maximum_stock'] ?? null;

        if ($maximum === null || $maximum === '') {
            return;
        }

        if (! is_numeric($maximum)) {
            throw ValidationException::withMessages([
                'maximum_stock' => 'Stok maksimum harus berupa angka.',
            ]);
        }

        if ((float) $maximum < (float) $minimum) {
            throw ValidationException::withMessages([
                'maximum_stock' => 'Stok maksimum tidak boleh kurang dari stok minimum.',
            ]);
        }
    }

    private function assertUniqueThreshold(int $branchId, int $locationId, int $productId, ?int $ignoreId = null): void
    {
        $existing = $this->minimums->findByLocationAndProduct($branchId, $locationId, $productId);

        if ($existing !== null && $existing->id !== $ignoreId) {
            throw ValidationException::withMessages([
                'product_id' => 'Konfigurasi stok minimum untuk lokasi dan produk ini sudah ada.',
            ]);
        }
    }
}
