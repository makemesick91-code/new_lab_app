<?php

namespace App\Modules\Invoice\Interfaces;

use App\Modules\Invoice\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface InvoiceRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function find(int $id): ?Invoice;

    public function create(array $data): Invoice;

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createItems(Invoice $invoice, array $items): void;

    public function update(Invoice $invoice, array $data): Invoice;

    public function latestInvoiceNumberForMonth(string $month): ?string;

    /**
     * @param  array<int, int>  $labOrderIds
     */
    public function hasActiveInvoiceForLabOrders(array $labOrderIds): bool;

    /**
     * @param  array<int, int>  $excludeOrderIds
     */
    public function completedOrdersAvailable(array $filters = [], array $excludeOrderIds = [], int $limit = 100): Collection;
}
