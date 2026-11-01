<?php

declare(strict_types=1);

namespace App\Modules\ClinicOversight\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * HARDENING-RME-RP0-CLOSURE-OWNER-DECISIONS-AND-PAID-TREATMENT-1 (rule 217 §9) —
 * READ-ONLY oversight of "Selesai Tanpa Biaya" closures (invoices in NO_CHARGE).
 *
 * Every method takes the AUTHORIZED branch ids and fails closed on an empty list.
 * Filters: category, treatment_id, doctor_id, closer_id (zero_value_closed_by),
 * creator_id (the invoice cashier_id), invoice_number, visit_number (exact). The
 * period is applied on zero_value_closed_at (UTC bounds of a WITA clinical range).
 * No patient name, record number or identity number is ever selected.
 *
 * @phpstan-type Filters array{category?: ?string, treatment_id?: ?int, doctor_id?: ?int, closer_id?: ?int, creator_id?: ?int, invoice_number?: ?string, visit_number?: ?string}
 */
interface OversightZeroValueClosureRepositoryInterface
{
    /**
     * @param  list<int>  $branchIds
     * @param  Filters  $filters
     */
    public function paginate(array $branchIds, array $filters, string $startUtc, string $endExclusiveUtc, int $perPage = 25): LengthAwarePaginator;

    /**
     * Totals, breakdowns (branch, closing cashier, doctor, treatment) and anomalies.
     *
     * @param  list<int>  $branchIds
     * @param  Filters  $filters
     * @return array<string, mixed>
     */
    public function summary(array $branchIds, array $filters, string $startUtc, string $endExclusiveUtc): array;

    /**
     * The doctors, cashiers and treatments that occur on NO_CHARGE invoices inside
     * the authorized branches — the only choices the filter offers.
     *
     * @param  list<int>  $branchIds
     * @return array{doctors: list<array{id: int, name: string}>, cashiers: list<array{id: int, name: string}>, treatments: list<array{id: int, name: string}>}
     */
    public function filterOptions(array $branchIds): array;
}
