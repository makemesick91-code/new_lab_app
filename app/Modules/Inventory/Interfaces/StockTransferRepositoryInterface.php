<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface StockTransferRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): StockTransfer;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StockTransfer $transfer, array $data): StockTransfer;

    /**
     * @param  array<int, array{product_id: int, quantity: float, notes?: string|null}>  $items
     */
    public function replaceItems(StockTransfer $transfer, array $items): void;

    public function findById(int $branchId, int $id): ?StockTransfer;

    public function loadDetails(StockTransfer $transfer): StockTransfer;
}
