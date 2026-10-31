<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Interfaces;

use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligationEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — persistence for overpayment
 * obligations (owner decision PP-3, option A) and their append-only events.
 *
 * Every list a user reaches takes the AUTHORIZED branch ids as an argument; the
 * repository never decides scope and never reads a request.
 */
interface RmeOverpaymentObligationRepositoryInterface
{
    /**
     * The OPEN obligation of an invoice, or null. With $lock it is read FOR
     * UPDATE — call it only while holding the invoice row lock (lock order:
     * invoice → correction → obligation).
     */
    public function openForInvoice(int $invoiceId, bool $lock = false): ?RmeOverpaymentObligation;

    public function find(int $id): ?RmeOverpaymentObligation;

    /**
     * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 — SELECT … FOR UPDATE by id, for
     * the refund decision that locks the request's OWN obligation (design §5 step 2).
     * Take it after the invoice lock and before the refund request lock.
     */
    public function findForUpdate(int $id): ?RmeOverpaymentObligation;

    public function forCorrection(int $correctionId): ?RmeOverpaymentObligation;

    /** @return Collection<int, RmeOverpaymentObligation> */
    public function forInvoice(int $invoiceId): Collection;

    /** @param array<string, mixed> $data */
    public function create(array $data): RmeOverpaymentObligation;

    public function assignNumber(RmeOverpaymentObligation $obligation, string $number): RmeOverpaymentObligation;

    /**
     * FEATURE-RME-REFUND-SETTLEMENT-1 (RFS-4) — the one-time OPEN → SETTLED move,
     * written only by the settlement transaction while it holds the obligation
     * lock. Writes status, settled_at and rme_refund_settlement_id, nothing else.
     */
    public function markSettled(RmeOverpaymentObligation $obligation, int $settlementId, \DateTimeInterface $settledAt): RmeOverpaymentObligation;

    /** @param array<string, mixed> $data */
    public function recordEvent(array $data): RmeOverpaymentObligationEvent;

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $filters  status, search
     */
    public function paginateForBranches(array $branchIds, array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * OPEN obligations per branch id: count and total amount. Read-only readiness.
     *
     * @return array<int, array{count: int, amount: string}>
     */
    public function openSummaryByBranch(): array;
}
