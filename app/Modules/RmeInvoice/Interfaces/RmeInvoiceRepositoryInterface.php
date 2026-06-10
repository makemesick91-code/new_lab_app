<?php

namespace App\Modules\RmeInvoice\Interfaces;

use App\Modules\RmeInvoice\Models\RmeInvoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface RmeInvoiceRepositoryInterface
{
    /** @param array<string, mixed> $filters */
    public function paginateCashierPending(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function findForVisit(int $clinicVisitId): ?RmeInvoice;

    public function hasActiveInvoiceForVisit(int $clinicVisitId): bool;

    public function latestInvoiceNumberForMonth(string $month): ?string;

    /** @param array<string, mixed> $data */
    public function create(array $data): RmeInvoice;

    /** @param array<string, mixed> $data */
    public function update(RmeInvoice $invoice, array $data): RmeInvoice;

    public function findInBranch(int $branchId, int $id): ?RmeInvoice;
}
