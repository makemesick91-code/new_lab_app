<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\PurchaseRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PurchaseRequestRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PurchaseRequest;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PurchaseRequest $purchaseRequest, array $data): PurchaseRequest;

    /**
     * @param  array<int, array{product_id: int, inventory_location_id?: int|null, quantity_requested: float, estimated_unit_price?: float|null, notes?: string|null}>  $items
     */
    public function replaceItems(PurchaseRequest $purchaseRequest, array $items): void;

    public function findById(int $branchId, int $id): ?PurchaseRequest;

    public function loadDetails(PurchaseRequest $purchaseRequest): PurchaseRequest;

    public function latestNumberForDateAndBranch(string $datePrefix, int $branchId): ?string;

    public function existsNumber(string $number): bool;
}
