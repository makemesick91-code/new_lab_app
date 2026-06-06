<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\PurchaseRequestRepositoryInterface;
use App\Modules\Inventory\Models\PurchaseRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PurchaseRequestRepository implements PurchaseRequestRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return PurchaseRequest::query()
            ->with(['requestedBy', 'approvedBy'])
            ->withCount('items')
            ->where('branch_id', $branchId)
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(purchase_request_number) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(notes) LIKE ?', [$term]);
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('request_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('request_date', '<=', $v))
            ->orderByDesc('request_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(array $data): PurchaseRequest
    {
        return PurchaseRequest::create($data);
    }

    public function update(PurchaseRequest $purchaseRequest, array $data): PurchaseRequest
    {
        $purchaseRequest->update($data);

        return $purchaseRequest->refresh();
    }

    public function replaceItems(PurchaseRequest $purchaseRequest, array $items): void
    {
        $purchaseRequest->items()->delete();

        foreach ($items as $item) {
            $purchaseRequest->items()->create([
                'product_id' => $item['product_id'],
                'inventory_location_id' => $item['inventory_location_id'] ?? null,
                'quantity_requested' => $item['quantity_requested'],
                'estimated_unit_price' => $item['estimated_unit_price'] ?? null,
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }

    public function findById(int $branchId, int $id): ?PurchaseRequest
    {
        return PurchaseRequest::query()
            ->with([
                'items.product.unit',
                'items.inventoryLocation',
                'requestedBy',
                'approvedBy',
                'rejectedBy',
                'createdBy',
            ])
            ->where('branch_id', $branchId)
            ->find($id);
    }

    public function loadDetails(PurchaseRequest $purchaseRequest): PurchaseRequest
    {
        return $purchaseRequest->load([
            'items.product.unit',
            'items.inventoryLocation',
            'requestedBy',
            'approvedBy',
            'rejectedBy',
            'createdBy',
        ]);
    }

    public function latestNumberForDateAndBranch(string $datePrefix, int $branchId): ?string
    {
        return PurchaseRequest::query()
            ->where('branch_id', $branchId)
            ->where('purchase_request_number', 'like', $datePrefix.'%')
            ->orderByDesc('purchase_request_number')
            ->value('purchase_request_number');
    }

    public function existsNumber(string $number): bool
    {
        return PurchaseRequest::query()
            ->where('purchase_request_number', $number)
            ->exists();
    }
}
