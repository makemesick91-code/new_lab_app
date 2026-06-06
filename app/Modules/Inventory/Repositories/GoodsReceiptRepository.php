<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\GoodsReceiptRepositoryInterface;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class GoodsReceiptRepository implements GoodsReceiptRepositoryInterface
{
    public function paginateForBranch(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return GoodsReceipt::query()
            ->with(['branch', 'purchaseOrder.supplier', 'createdBy', 'postedBy', 'items'])
            ->where('branch_id', $branchId)
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(receipt_number) LIKE ?', [$term])
                        ->orWhereHas('purchaseOrder', fn ($po) => $po->whereRaw('LOWER(purchase_order_number) LIKE ?', [$term]));
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['purchase_order_id'] ?? null, fn ($q, $v) => $q->where('purchase_order_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('receipt_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('receipt_date', '<=', $v))
            ->orderByDesc('receipt_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForBranch(int $branchId, int $id): ?GoodsReceipt
    {
        return GoodsReceipt::query()
            ->with([
                'branch',
                'purchaseOrder.supplier',
                'purchaseOrder.branch',
                'purchaseOrder.items.product',
                'purchaseOrder.items.inventoryLocation',
                'createdBy',
                'submittedBy',
                'postedBy',
                'cancelledBy',
                'items.product',
                'items.inventoryLocation',
                'items.inventoryBatch',
                'items.purchaseOrderItem',
                'items.inventoryMovement',
            ])
            ->where('branch_id', $branchId)
            ->find($id);
    }

    public function create(array $data): GoodsReceipt
    {
        return GoodsReceipt::create($data);
    }

    public function update(GoodsReceipt $goodsReceipt, array $data): GoodsReceipt
    {
        $goodsReceipt->update($data);

        return $goodsReceipt->refresh();
    }

    public function replaceItems(GoodsReceipt $goodsReceipt, array $items): void
    {
        $goodsReceipt->items()->delete();

        foreach ($items as $item) {
            $goodsReceipt->items()->create([
                'purchase_order_item_id' => $item['purchase_order_item_id'],
                'product_id' => $item['product_id'],
                'inventory_location_id' => $item['inventory_location_id'],
                'inventory_batch_id' => $item['inventory_batch_id'] ?? null,
                'batch_number' => $item['batch_number'] ?? null,
                'lot_number' => $item['lot_number'] ?? null,
                'batch_received_date' => $item['batch_received_date'] ?? null,
                'expiry_date' => $item['expiry_date'] ?? null,
                'ordered_qty' => $item['ordered_qty'],
                'previously_received_qty' => $item['previously_received_qty'],
                'received_qty' => $item['received_qty'],
                'accepted_qty' => $item['accepted_qty'],
                'rejected_qty' => $item['rejected_qty'] ?? 0,
                'unit_cost' => $item['unit_cost'] ?? null,
                'line_total' => $item['line_total'] ?? null,
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }

    public function updateItem(GoodsReceiptItem $item, array $data): GoodsReceiptItem
    {
        $item->update($data);

        return $item->refresh();
    }

    public function findReceivablePurchaseOrders(int $branchId): Collection
    {
        return PurchaseOrder::query()
            ->with(['supplier', 'items.product', 'items.inventoryLocation'])
            ->where('branch_id', $branchId)
            ->whereIn('status', [
                PurchaseOrder::STATUS_APPROVED,
                PurchaseOrder::STATUS_SENT,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ])
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->get();
    }

    public function findFilterablePurchaseOrders(int $branchId): Collection
    {
        return PurchaseOrder::query()
            ->where('branch_id', $branchId)
            ->where(function ($query) use ($branchId) {
                $query->whereIn('status', [
                    PurchaseOrder::STATUS_APPROVED,
                    PurchaseOrder::STATUS_SENT,
                    PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                    PurchaseOrder::STATUS_FULLY_RECEIVED,
                ])->orWhereHas('goodsReceipts', fn ($goodsReceipt) => $goodsReceipt->where('branch_id', $branchId));
            })
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->get(['id', 'purchase_order_number']);
    }

    public function getPreviouslyReceivedQty(int $purchaseOrderItemId): float
    {
        return (float) (PurchaseOrderItem::query()
            ->whereKey($purchaseOrderItemId)
            ->value('quantity_received') ?? 0);
    }

    public function existsPostedMovement(GoodsReceipt $goodsReceipt): bool
    {
        if ($goodsReceipt->items()->whereNotNull('inventory_movement_id')->exists()) {
            return true;
        }

        return InventoryMovement::query()
            ->where('reference_type', $goodsReceipt->getTable())
            ->where('reference_id', $goodsReceipt->id)
            ->exists();
    }

    public function lockForPosting(int $goodsReceiptId, int $branchId): ?GoodsReceipt
    {
        return GoodsReceipt::query()
            ->with(['items.purchaseOrderItem', 'purchaseOrder.items'])
            ->where('branch_id', $branchId)
            ->whereKey($goodsReceiptId)
            ->lockForUpdate()
            ->first();
    }

    public function latestNumberForDateAndBranch(string $prefix, int $branchId): ?string
    {
        return GoodsReceipt::query()
            ->where('branch_id', $branchId)
            ->where('receipt_number', 'like', $prefix.'%')
            ->orderByDesc('receipt_number')
            ->value('receipt_number');
    }

    public function existsNumber(string $number): bool
    {
        return GoodsReceipt::query()
            ->where('receipt_number', $number)
            ->exists();
    }
}
