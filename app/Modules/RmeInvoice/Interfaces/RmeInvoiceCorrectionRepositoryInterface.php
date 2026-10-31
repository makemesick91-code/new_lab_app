<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Interfaces;

use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrectionEvent;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrectionLine;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — persistence for invoice
 * corrections, their lines and events, and frozen invoice document versions.
 *
 * Every list/lookup that a user reaches takes the AUTHORIZED branch ids as an
 * argument; the repository never decides scope and never reads a request.
 */
interface RmeInvoiceCorrectionRepositoryInterface
{
    public function find(int $id): ?RmeInvoiceCorrection;

    /** Row-locked read for a lifecycle transition (FOR UPDATE). */
    public function findForUpdate(int $id): ?RmeInvoiceCorrection;

    public function findByIdempotencyKey(string $key): ?RmeInvoiceCorrection;

    public function activeForInvoice(int $invoiceId): ?RmeInvoiceCorrection;

    /**
     * Rule 203 — the most recently APPLIED correction of an invoice (highest
     * applied_invoice_version), or null. Read under the caller's invoice lock.
     */
    public function latestAppliedForInvoice(int $invoiceId): ?RmeInvoiceCorrection;

    /** @return Collection<int, RmeInvoiceCorrection> */
    public function forInvoice(int $invoiceId): Collection;

    /** @param array<string, mixed> $data */
    public function create(array $data): RmeInvoiceCorrection;

    public function assignNumber(RmeInvoiceCorrection $correction, string $number): RmeInvoiceCorrection;

    /** @param array<string, mixed> $data */
    public function createLine(array $data): RmeInvoiceCorrectionLine;

    /**
     * Lifecycle columns only (status, decided/applied/cancelled/stale fields).
     * The model guard refuses any immutable column.
     *
     * @param  array<string, mixed>  $data
     */
    public function transition(RmeInvoiceCorrection $correction, array $data): RmeInvoiceCorrection;

    /** @param array<string, mixed> $data */
    public function recordEvent(array $data): RmeInvoiceCorrectionEvent;

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $filters  status, mine (user id), search, type
     */
    public function paginateForBranches(array $branchIds, array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * @param  array<int, int>  $branchIds
     * @return array<string, int> status => count
     */
    public function countByStatusForBranches(array $branchIds): array;

    /**
     * Active treatments among the given ids, keyed by id.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, Treatment>
     */
    public function activeTreatments(array $ids): Collection;

    /**
     * Active treatments for the proposal form (id, code, name only).
     *
     * @return Collection<int, Treatment>
     */
    public function treatmentOptions(): Collection;

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    public function existingDoctorIds(array $ids): array;

    /** D23 guard: lab case candidates already generated for this invoice. */
    public function invoiceHasLabCandidates(int $invoiceId): bool;

    /**
     * FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 (PP-2/PP-7) — whether this owner
     * approval reference is already recorded on a POST_PAYMENT correction of a
     * DIFFERENT invoice (any status). A reference is case-specific: it may be reused
     * on the same invoice (a resubmission after STALE/REJECT), never on another one.
     */
    public function postPaymentReferenceUsedOnOtherInvoice(string $reference, int $invoiceId): bool;

    /**
     * HARDENING-RME-OWNER-REFERENCE-REGISTRY-1 — whether this reference already
     * authorised an APPROVED or APPLIED post-payment correction of THIS invoice.
     * Rule 207 §10a lets the same invoice repeat a reference only on a
     * resubmission after STALE/REJECT/CANCEL; a reference that was spent on a
     * decision cannot authorise a second one. Call it under the invoice lock.
     */
    public function postPaymentReferenceSpentOnInvoice(string $reference, int $invoiceId): bool;

    /** @return Collection<int, RmeInvoiceDocumentVersion> */
    public function documentVersionsForInvoice(int $invoiceId): Collection;

    public function latestDocumentVersion(int $invoiceId): ?RmeInvoiceDocumentVersion;

    /**
     * FIX-RME-INVOICE-CORRECTION-ZERO-ORIGINAL-GUARD-1 (rule 201) — the frozen
     * v0 (ORIGINAL, version 0) document of an invoice, or null when the invoice
     * has never been corrected. The authority for the ORIGINAL total of an
     * invoice whose correction_version is above 0.
     */
    public function originalDocumentVersion(int $invoiceId): ?RmeInvoiceDocumentVersion;

    /** @param array<string, mixed> $data */
    public function createDocumentVersion(array $data): RmeInvoiceDocumentVersion;

    public function findDocumentVersion(int $id): ?RmeInvoiceDocumentVersion;

    /**
     * Rule 195 — active (SUBMITTED/APPROVED) corrections per branch id. Read-only
     * readiness: an active correction blocks payment of its invoice (D10).
     *
     * @return array<int, int>
     */
    public function activeCountByBranch(): array;

    /**
     * Rule 195 — UNPAID invoices with no payment and a positive grand total in
     * the given branches: the population a pre-payment correction could target.
     * A candidate count only; full eligibility is decided at submission.
     *
     * @param  list<int>  $branchIds
     */
    public function positiveUnpaidUnsettledInvoiceCount(array $branchIds): int;
}
