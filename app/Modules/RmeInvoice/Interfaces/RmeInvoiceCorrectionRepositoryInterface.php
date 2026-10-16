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

    /** @return Collection<int, RmeInvoiceDocumentVersion> */
    public function documentVersionsForInvoice(int $invoiceId): Collection;

    public function latestDocumentVersion(int $invoiceId): ?RmeInvoiceDocumentVersion;

    /** @param array<string, mixed> $data */
    public function createDocumentVersion(array $data): RmeInvoiceDocumentVersion;

    public function findDocumentVersion(int $id): ?RmeInvoiceDocumentVersion;
}
