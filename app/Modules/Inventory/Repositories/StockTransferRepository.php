<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\StockTransferRepositoryInterface;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StockTransferRepository implements StockTransferRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return StockTransfer::query()
            ->with(['sourceInventoryLocation', 'destinationInventoryLocation', 'requestedBy'])
            ->withCount('items')
            ->where('branch_id', $branchId)
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(transfer_number) LIKE ?', [$term])
                        ->orWhereHas('sourceInventoryLocation', fn ($l) => $l->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('destinationInventoryLocation', fn ($l) => $l->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->when($filters['source_inventory_location_id'] ?? null, fn ($q, $v) => $q->where('source_inventory_location_id', $v))
            ->when($filters['destination_inventory_location_id'] ?? null, fn ($q, $v) => $q->where('destination_inventory_location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('transfer_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('transfer_date', '<=', $v))
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(array $data): StockTransfer
    {
        return StockTransfer::create($data);
    }

    public function update(StockTransfer $transfer, array $data): StockTransfer
    {
        $transfer->update($data);

        return $transfer->refresh();
    }

    public function replaceItems(StockTransfer $transfer, array $items): void
    {
        $transfer->items()->delete();

        foreach ($items as $item) {
            $transfer->items()->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }

    public function findById(int $branchId, int $id): ?StockTransfer
    {
        return StockTransfer::query()
            ->with([
                'sourceInventoryLocation',
                'destinationInventoryLocation',
                'items.product.unit',
                'requestedBy',
                'approvedBy',
                'createdBy',
            ])
            ->where('branch_id', $branchId)
            ->find($id);
    }

    public function loadDetails(StockTransfer $transfer): StockTransfer
    {
        return $transfer->load([
            'sourceInventoryLocation',
            'destinationInventoryLocation',
            'items.product.unit',
            'requestedBy',
            'approvedBy',
            'createdBy',
        ]);
    }
}
