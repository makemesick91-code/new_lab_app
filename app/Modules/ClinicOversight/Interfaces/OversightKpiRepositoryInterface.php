<?php

namespace App\Modules\ClinicOversight\Interfaces;

use App\Modules\ClinicOversight\Support\ClinicalDateRange;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — read-only KPI aggregations for
 * the Finance & Data Analyst dashboard. Every method takes the SERVER-resolved
 * branch set as its first argument and returns nothing for an empty set (fail
 * closed). Each KPI is ONE grouped aggregation, never one query per branch.
 *
 * REVISION-OVERSIGHT-DASHBOARD-DATE-RANGE-1 (rule 187): the four period KPIs
 * take an inclusive ClinicalDateRange (a single day is the range D..D). The
 * receivables method deliberately takes NO range at all — Piutang is the
 * current balance, so it cannot be date-filtered by any caller.
 *
 * Per-branch results are keyed by branch id. Branches with no activity are
 * simply absent; the service fills zeros.
 */
interface OversightKpiRepositoryInterface
{
    /**
     * Distinct checked-in patients per branch over the clinic-date range.
     *
     * @param  list<int>  $branchIds
     * @return array<int, int>
     */
    public function checkedInPatientsByBranch(array $branchIds, ClinicalDateRange $range): array;

    /**
     * Distinct checked-in patients across ALL the given branches — NOT the sum
     * of the per-branch counts: one patient seen at two branches is one person.
     *
     * @param  list<int>  $branchIds
     */
    public function checkedInPatientsTotal(array $branchIds, ClinicalDateRange $range): int;

    /**
     * Cashier transactions (distinct payment batches) per INVOICE branch.
     *
     * @param  list<int>  $branchIds
     * @return array<int, int>
     */
    public function transactionsByBranch(array $branchIds, ClinicalDateRange $range): array;

    /**
     * Distinct payment batches across all the given branches. A batch that
     * settled invoices at two branches counts once here and once per branch.
     *
     * @param  list<int>  $branchIds
     */
    public function transactionsTotal(array $branchIds, ClinicalDateRange $range): int;

    /**
     * Actual received revenue in integer CENTS per invoice branch.
     *
     * @param  list<int>  $branchIds
     * @return array<int, int>
     */
    public function revenueCentsByBranch(array $branchIds, ClinicalDateRange $range): array;

    /**
     * Current active receivables per invoice branch:
     * ['amount_cents' => int, 'invoices' => int]. NOT date-filtered.
     *
     * @param  list<int>  $branchIds
     * @return array<int, array{amount_cents:int, invoices:int}>
     */
    public function activeReceivablesByBranch(array $branchIds): array;

    /**
     * Visits cancelled during the range (by cancelled_at), per branch.
     *
     * @param  list<int>  $branchIds
     * @return array<int, int>
     */
    public function cancelledVisitsByBranch(array $branchIds, ClinicalDateRange $range): array;
}
