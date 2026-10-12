<?php

namespace App\Modules\ClinicOversight\Interfaces;

use App\Modules\ClinicOversight\Support\ClinicalDayWindow;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — read-only KPI aggregations for
 * the Finance & Data Analyst dashboard. Every method takes the SERVER-resolved
 * branch set as its first argument and returns nothing for an empty set (fail
 * closed). Each KPI is ONE grouped aggregation, never one query per branch.
 *
 * Per-branch results are keyed by branch id. Branches with no activity are
 * simply absent; the service fills zeros.
 */
interface OversightKpiRepositoryInterface
{
    /**
     * Distinct checked-in patients per branch for the clinic day.
     *
     * @param  list<int>  $branchIds
     * @return array<int, int>
     */
    public function checkedInPatientsByBranch(array $branchIds, ClinicalDayWindow $day): array;

    /**
     * Distinct checked-in patients across ALL the given branches — NOT the sum
     * of the per-branch counts: one patient seen at two branches is one person.
     *
     * @param  list<int>  $branchIds
     */
    public function checkedInPatientsTotal(array $branchIds, ClinicalDayWindow $day): int;

    /**
     * Cashier transactions (distinct payment batches) per INVOICE branch.
     *
     * @param  list<int>  $branchIds
     * @return array<int, int>
     */
    public function transactionsByBranch(array $branchIds, ClinicalDayWindow $day): array;

    /**
     * Distinct payment batches across all the given branches. A batch that
     * settled invoices at two branches counts once here and once per branch.
     *
     * @param  list<int>  $branchIds
     */
    public function transactionsTotal(array $branchIds, ClinicalDayWindow $day): int;

    /**
     * Actual received revenue in integer CENTS per invoice branch.
     *
     * @param  list<int>  $branchIds
     * @return array<int, int>
     */
    public function revenueCentsByBranch(array $branchIds, ClinicalDayWindow $day): array;

    /**
     * Current active receivables per invoice branch:
     * ['amount_cents' => int, 'invoices' => int]. NOT date-filtered.
     *
     * @param  list<int>  $branchIds
     * @return array<int, array{amount_cents:int, invoices:int}>
     */
    public function activeReceivablesByBranch(array $branchIds): array;

    /**
     * Visits cancelled during the clinic day (by cancelled_at), per branch.
     *
     * @param  list<int>  $branchIds
     * @return array<int, int>
     */
    public function cancelledVisitsByBranch(array $branchIds, ClinicalDayWindow $day): array;
}
