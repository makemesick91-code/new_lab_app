<?php

namespace App\Modules\RmeInvoice\Interfaces;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface RmeInvoiceRepositoryInterface
{
    /** @param array<string, mixed> $filters */
    public function paginateCashierPending(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Cashier pending visits across the operational "Cabang RME" set (active
     * RME-enabled branches), mirroring the multi-branch visit list. Used so the
     * cashier queue never falls back to a single MAIN BranchContext branch
     * (Sprint 23 Phase 23.10).
     *
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $filters
     */
    public function paginateCashierPendingForBranches(array $branchIds, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Hotfix Sprint 60.7 — Doctor → Cashier sync queue. Active (non-terminal)
     * visits across the active "Cabang RME" set, including pre-cashier stages so
     * the cashier/front office can see what each visit is still waiting on. Returns
     * an eager-loaded collection (grouped client-side), not a paginator.
     *
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ClinicVisit>
     */
    public function cashierHandoffQueueForBranches(array $branchIds, array $filters = []): Collection;

    public function findForVisit(int $clinicVisitId): ?RmeInvoice;

    public function hasActiveInvoiceForVisit(int $clinicVisitId): bool;

    public function latestInvoiceNumberForMonth(string $month): ?string;

    /** @param array<string, mixed> $data */
    public function create(array $data): RmeInvoice;

    /** @param array<string, mixed> $data */
    public function update(RmeInvoice $invoice, array $data): RmeInvoice;

    public function findInBranch(int $branchId, int $id): ?RmeInvoice;

    // ─── FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 ─────────────────
    // Used ONLY by RmeInvoiceCorrectionService::apply(), inside one transaction
    // that already holds the invoice row lock.

    /**
     * Row-locked read (FOR UPDATE) — the same lock every payment path takes on
     * this row, so a correction and a payment can never interleave.
     */
    public function findForUpdate(int $id): ?RmeInvoice;

    /** @param array<string, mixed> $data */
    public function createItem(array $data): RmeInvoiceItem;

    /** @param array<string, mixed> $data */
    public function updateItem(RmeInvoiceItem $item, array $data): RmeInvoiceItem;

    public function deleteItem(RmeInvoiceItem $item): void;

    /**
     * Totals + correction_version, written after an applied correction. The
     * caller passes server-computed values only.
     *
     * @param  array{subtotal: string, discount_total: string, grand_total: string, correction_version: int}  $data
     */
    public function applyCorrectionTotals(RmeInvoice $invoice, array $data): RmeInvoice;

    /**
     * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — invoice-line counts per branch and
     * price provenance (a NULL source is reported as NOT_RECORDED: billed before
     * tariff authority). Read-only; one grouped query.
     *
     * @param  list<int>  $branchIds
     * @return array<int, array<string, int>> branch id => [price_source => count]
     */
    public function priceSourceCountsByBranch(array $branchIds): array;
}
