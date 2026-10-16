<?php

declare(strict_types=1);

namespace App\Modules\ClinicOversight\Interfaces;

use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — READ-ONLY queries of the
 * BILLING CORRECTION oversight area. Every method takes the already-authorized
 * branch ids and the WITA date range resolved to UTC instants; an empty branch
 * set yields nothing (fail closed). There is no write method on this interface.
 */
interface OversightBillingCorrectionRepositoryInterface
{
    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $filters  status, search
     */
    public function paginate(array $branchIds, string $fromUtc, string $toUtcExclusive, array $filters, int $perPage = 25): LengthAwarePaginator;

    /**
     * One row per patient who has a correction in range (in scope).
     *
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $filters
     */
    public function paginatePatients(array $branchIds, string $fromUtc, string $toUtcExclusive, array $filters, int $perPage = 25): LengthAwarePaginator;

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, RmeInvoiceCorrection>
     */
    public function exportRows(array $branchIds, string $fromUtc, string $toUtcExclusive, array $filters, int $limit): Collection;

    public function find(int $id): ?RmeInvoiceCorrection;

    /** @return Collection<int, RmeInvoiceDocumentVersion> */
    public function documentVersions(int $invoiceId): Collection;
}
