<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\PurchaseOrderRepositoryInterface;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrderRepository implements PurchaseOrderRepositoryInterface
{
    public function queryForBranch(int $branchId, array $filters = []): Builder
    {
        $search = $filters['search'] ?? null;

        return PurchaseOrder::query()
            ->with(['supplier', 'purchaseRequest', 'createdBy', 'items'])
            ->where('branch_id', $branchId)
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(purchase_order_number) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(supplier_snapshot_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(supplier_reference_number) LIKE ?', [$term])
                        ->orWhereHas('supplier', fn ($supplier) => $supplier->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($filters['purchase_request_id'] ?? null, fn ($q, $v) => $q->where('purchase_request_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('order_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('order_date', '<=', $v))
            ->orderByDesc('order_date')
            ->orderByDesc('id');
    }

    public function paginateForBranch(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->queryForBranch($branchId, $filters)
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForBranch(int $branchId, int $id): ?PurchaseOrder
    {
        return PurchaseOrder::query()
            ->with([
                'supplier',
                'purchaseRequest',
                'submittedBy',
                'approvedBy',
                'sentBy',
                'createdBy',
                'items.product',
                'items.inventoryLocation',
                'items.purchaseRequestItem',
            ])
            ->where('branch_id', $branchId)
            ->find($id);
    }

    public function create(array $data): PurchaseOrder
    {
        return PurchaseOrder::create($data);
    }

    public function update(PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        $purchaseOrder->update($data);

        return $purchaseOrder->refresh();
    }

    public function replaceItems(PurchaseOrder $purchaseOrder, array $items): void
    {
        $purchaseOrder->items()->delete();

        foreach ($items as $item) {
            $purchaseOrder->items()->create([
                'product_id' => $item['product_id'],
                'inventory_location_id' => $item['inventory_location_id'] ?? null,
                'purchase_request_item_id' => $item['purchase_request_item_id'] ?? null,
                'quantity_ordered' => $item['quantity_ordered'],
                'unit_price' => $item['unit_price'] ?? null,
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }

    public function nextDocumentSequence(int $branchId, string $datePrefix): int
    {
        $latest = $this->latestNumberForDateAndBranch($datePrefix, $branchId);

        return $latest ? ((int) substr($latest, -6)) + 1 : 1;
    }

    public function latestNumberForDateAndBranch(string $datePrefix, int $branchId): ?string
    {
        return PurchaseOrder::query()
            ->where('branch_id', $branchId)
            ->where('purchase_order_number', 'like', $datePrefix.'%')
            ->orderByDesc('purchase_order_number')
            ->value('purchase_order_number');
    }

    public function existsNumber(string $number): bool
    {
        return PurchaseOrder::query()
            ->where('purchase_order_number', $number)
            ->exists();
    }

    public function incrementItemQuantityReceived(int $purchaseOrderItemId, float $acceptedQty): void
    {
        PurchaseOrderItem::query()
            ->whereKey($purchaseOrderItemId)
            ->increment('quantity_received', $acceptedQty);
    }
}
