<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Interfaces;

use App\Modules\RmeInvoice\Models\RmeRefundRequest;
use App\Modules\RmeInvoice\Models\RmeRefundRequestEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215) — persistence of refund
 * requests, their events and the patient-reference registry. Every write is
 * allow-listed: nothing here can change an obligation, an invoice or a payment.
 */
interface RmeRefundRequestRepositoryInterface
{
    public function find(int $id): ?RmeRefundRequest;

    /** SELECT … FOR UPDATE; call only after the invoice and obligation locks (design §5). */
    public function findForUpdate(int $id): ?RmeRefundRequest;

    public function findByIntentKey(string $intentKey): ?RmeRefundRequest;

    /** The obligation's REQUESTED/APPROVED request, if any; `$lock` takes FOR UPDATE. */
    public function activeForObligation(int $obligationId, bool $lock = false): ?RmeRefundRequest;

    /** @return Collection<int, RmeRefundRequest> */
    public function forInvoice(int $invoiceId): Collection;

    /** @param  array<string, mixed>  $data */
    public function create(array $data): RmeRefundRequest;

    public function assignNumber(RmeRefundRequest $request, string $number): RmeRefundRequest;

    /**
     * Lifecycle columns only (status, decision, cancellation, staleness).
     *
     * @param  array<string, mixed>  $data
     */
    public function transition(RmeRefundRequest $request, array $data): RmeRefundRequest;

    /** @param  array<string, mixed>  $data */
    public function recordEvent(array $data): RmeRefundRequestEvent;

    /** @return Collection<int, RmeRefundRequestEvent> */
    public function events(int $requestId): Collection;

    /** Is this Owner reference (case-folded) recorded on ANY refund request? */
    public function ownerReferenceUsedOnRefund(string $reference): bool;

    /** Is this Owner reference (case-folded) recorded on ANY invoice correction, of any type? */
    public function ownerReferenceUsedOnCorrection(string $reference): bool;

    /** INSERT … ON CONFLICT DO NOTHING into the patient-reference registry; never raises on a duplicate. */
    public function claimPatientReference(string $folded, int $obligationId, int $actorId): void;

    /** The obligation that owns a folded patient reference, or null. */
    public function patientReferenceObligation(string $folded): ?int;

    /**
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     */
    public function paginateForBranches(array $branchIds, array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * @param  list<int>  $branchIds
     * @return array<string, int>
     */
    public function statusCountsForBranches(array $branchIds): array;

    /**
     * APPROVED-but-unsettled requests by branch: count and cents (RF-8 — shown
     * apart from revenue, receivables, cash and bank).
     *
     * @param  list<int>  $branchIds
     * @return array<int, array{count: int, amount_cents: int}>
     */
    public function approvedUnsettledByBranch(array $branchIds): array;
}
