<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface GoodsReceiptRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateForBranch(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function findForBranch(int $branchId, int $id): ?GoodsReceipt;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): GoodsReceipt;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(GoodsReceipt $goodsReceipt, array $data): GoodsReceipt;

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function replaceItems(GoodsReceipt $goodsReceipt, array $items): void;

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateItem(GoodsReceiptItem $item, array $data): GoodsReceiptItem;

    /**
     * @return Collection<int, PurchaseOrder>
     */
    public function findReceivablePurchaseOrders(int $branchId): Collection;

    /**
     * @return Collection<int, PurchaseOrder>
     */
    public function findFilterablePurchaseOrders(int $branchId): Collection;

    public function getPreviouslyReceivedQty(int $purchaseOrderItemId): float;

    public function existsPostedMovement(GoodsReceipt $goodsReceipt): bool;

    public function lockForPosting(int $goodsReceiptId, int $branchId): ?GoodsReceipt;

    public function latestNumberForDateAndBranch(string $prefix, int $branchId): ?string;

    public function existsNumber(string $number): bool;
}
