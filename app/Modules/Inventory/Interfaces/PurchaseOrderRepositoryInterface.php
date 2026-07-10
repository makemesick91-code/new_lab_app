<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

interface PurchaseOrderRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<PurchaseOrder>
     */
    public function queryForBranch(int $branchId, array $filters = []): Builder;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateForBranch(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function findForBranch(int $branchId, int $id): ?PurchaseOrder;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PurchaseOrder;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PurchaseOrder $purchaseOrder, array $data): PurchaseOrder;

    /**
     * @param  array<int, array{product_id: int, supplier_id?: int|null, inventory_location_id?: int|null, purchase_request_item_id?: int|null, quantity_ordered: float, unit_price?: float|null, estimated_arrival_date?: string|null, notes?: string|null}>  $items
     */
    public function replaceItems(PurchaseOrder $purchaseOrder, array $items): void;

    public function nextDocumentSequence(int $branchId, string $datePrefix): int;

    public function latestNumberForDateAndBranch(string $datePrefix, int $branchId): ?string;

    public function existsNumber(string $number): bool;

    /**
     * Increment cumulative received quantity for a PO line.
     * Callable only from GoodsReceiptService::post().
     */
    public function incrementItemQuantityReceived(int $purchaseOrderItemId, float $acceptedQty): void;

    /**
     * Decrement cumulative received quantity for a PO line.
     * Callable only from GoodsReceiptService::void().
     */
    public function decrementItemQuantityReceived(int $purchaseOrderItemId, float $acceptedQty): void;
}
