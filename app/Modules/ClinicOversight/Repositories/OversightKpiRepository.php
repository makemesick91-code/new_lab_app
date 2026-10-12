<?php

namespace App\Modules\ClinicOversight\Repositories;

use App\Modules\ClinicOversight\Interfaces\OversightKpiRepositoryInterface;
use App\Modules\ClinicOversight\Support\ClinicalDateRange;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — the KPI definitions, in SQL.
 * Rule 186 is the prose authority for every definition below; this class is
 * its only implementation.
 *
 * Read-only by construction: every statement is a SELECT. Every query filters
 * soft-deleted rows explicitly (these are query-builder statements, so the
 * Eloquent SoftDeletes scope does not apply on its own).
 *
 * Portable across PostgreSQL (production) and SQLite (local tests): no
 * dialect-specific function is used, and timestamp columns are compared to
 * bound UTC literals, never converted.
 */
class OversightKpiRepository implements OversightKpiRepositoryInterface
{
    /**
     * The fallback identity of a payment that carries no batch uuid.
     *
     * `payment_batch_uuid` is written only by the multi-invoice allocation
     * paths; every single-invoice payment, and every row older than that
     * column, is NULL. Such a payment IS one cashier operation, so it is
     * identified by its own primary key — prefixed so it can never collide with
     * a real uuid, and never collapsed into a shared NULL bucket.
     */
    private const BATCH_IDENTITY_SQL = "COALESCE(CAST(p.payment_batch_uuid AS TEXT), 'payment:' || CAST(p.id AS TEXT))";

    public function checkedInPatientsByBranch(array $branchIds, ClinicalDateRange $range): array
    {
        if ($branchIds === []) {
            return [];
        }

        return $this->checkedInVisits($branchIds, $range)
            ->selectRaw('v.branch_id AS branch_id, COUNT(DISTINCT v.patient_id) AS total')
            ->groupBy('v.branch_id')
            ->pluck('total', 'branch_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    public function checkedInPatientsTotal(array $branchIds, ClinicalDateRange $range): int
    {
        if ($branchIds === []) {
            return 0;
        }

        return (int) $this->checkedInVisits($branchIds, $range)
            ->selectRaw('COUNT(DISTINCT v.patient_id) AS total')
            ->value('total');
    }

    public function transactionsByBranch(array $branchIds, ClinicalDateRange $range): array
    {
        if ($branchIds === []) {
            return [];
        }

        return $this->paymentsInRange($branchIds, $range)
            ->selectRaw('i.branch_id AS branch_id, COUNT(DISTINCT '.self::BATCH_IDENTITY_SQL.') AS total')
            ->groupBy('i.branch_id')
            ->pluck('total', 'branch_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    public function transactionsTotal(array $branchIds, ClinicalDateRange $range): int
    {
        if ($branchIds === []) {
            return 0;
        }

        return (int) $this->paymentsInRange($branchIds, $range)
            ->selectRaw('COUNT(DISTINCT '.self::BATCH_IDENTITY_SQL.') AS total')
            ->value('total');
    }

    public function revenueCentsByBranch(array $branchIds, ClinicalDateRange $range): array
    {
        if ($branchIds === []) {
            return [];
        }

        return $this->paymentsInRange($branchIds, $range)
            ->selectRaw('i.branch_id AS branch_id, COALESCE(SUM(p.amount), 0) AS total')
            ->groupBy('i.branch_id')
            ->pluck('total', 'branch_id')
            ->map(fn ($v) => self::toCents($v))
            ->all();
    }

    public function activeReceivablesByBranch(array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        // Valid accumulated payments per invoice — soft-deleted payments are
        // not money received. One grouped sub-select, joined once.
        $paid = DB::table('trx_rme_payments')
            ->selectRaw('rme_invoice_id, SUM(amount) AS paid_total')
            ->whereNull('deleted_at')
            ->groupBy('rme_invoice_id');

        $rows = DB::table('trx_rme_invoices as i')
            ->leftJoinSub($paid, 'pp', 'pp.rme_invoice_id', '=', 'i.id')
            ->whereNull('i.deleted_at')
            ->whereIn('i.branch_id', $branchIds)
            ->whereIn('i.status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL])
            ->where('i.grand_total', '>', 0)
            // Outstanding = grand_total − valid payments, strictly positive. An
            // overpaid invoice has no receivable and is not counted.
            ->whereRaw('i.grand_total - COALESCE(pp.paid_total, 0) > 0')
            ->groupBy('i.branch_id')
            ->selectRaw('i.branch_id AS branch_id, COUNT(*) AS invoices, SUM(i.grand_total - COALESCE(pp.paid_total, 0)) AS outstanding')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->branch_id] = [
                'amount_cents' => self::toCents($row->outstanding),
                'invoices' => (int) $row->invoices,
            ];
        }

        return $out;
    }

    public function cancelledVisitsByBranch(array $branchIds, ClinicalDateRange $range): array
    {
        if ($branchIds === []) {
            return [];
        }

        return DB::table('trx_clinic_visits as v')
            ->whereNull('v.deleted_at')
            ->whereIn('v.branch_id', $branchIds)
            ->where('v.status', ClinicVisit::STATUS_CANCELLED)
            // The CANCELLATION instant decides the period — not visit_date (an
            // old or future visit cancelled in the range counts in the range)
            // and never updated_at (any later edit would move it).
            ->where('v.cancelled_at', '>=', $range->startUtcString())
            ->where('v.cancelled_at', '<', $range->endExclusiveUtcString())
            ->selectRaw('v.branch_id AS branch_id, COUNT(*) AS total')
            ->groupBy('v.branch_id')
            ->pluck('total', 'branch_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Visits that genuinely ARRIVED in the clinic-date range: checked in (the
     * only arrival marker the workflow writes), not cancelled, not deleted.
     * visit_date is a DATE column: compared as [start_date, end_date + 1).
     *
     * @param  list<int>  $branchIds
     */
    private function checkedInVisits(array $branchIds, ClinicalDateRange $range): Builder
    {
        return DB::table('trx_clinic_visits as v')
            ->whereNull('v.deleted_at')
            ->whereIn('v.branch_id', $branchIds)
            ->where('v.visit_date', '>=', $range->startDate)
            ->where('v.visit_date', '<', $range->endExclusiveDate())
            ->whereNotNull('v.check_in_at')
            ->where('v.status', '!=', ClinicVisit::STATUS_CANCELLED);
    }

    /**
     * RME payments made during the range, attributed to the INVOICE's branch
     * (never the cashier's or the session's). RME only — the lab billing
     * tables (`trx_payments`) are never read here. paid_at holds UTC instants:
     * compared as the half-open [startUtc, endExclusiveUtc), no function on
     * the column, so the (branch_id, paid_at) index stays usable.
     *
     * @param  list<int>  $branchIds
     */
    private function paymentsInRange(array $branchIds, ClinicalDateRange $range): Builder
    {
        return DB::table('trx_rme_payments as p')
            ->join('trx_rme_invoices as i', 'i.id', '=', 'p.rme_invoice_id')
            ->whereNull('p.deleted_at')
            ->whereNull('i.deleted_at')
            ->whereIn('i.branch_id', $branchIds)
            ->where('p.paid_at', '>=', $range->startUtcString())
            ->where('p.paid_at', '<', $range->endExclusiveUtcString());
    }

    /** Decimal money (string|float|int|null) to exact integer cents. */
    private static function toCents(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $string = is_string($value) ? trim($value) : (string) $value;

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $string, $m) === 1) {
            $fraction = substr(str_pad($m[3] ?? '', 3, '0'), 0, 3);
            $cents = ((int) $m[2]) * 100 + (int) substr($fraction, 0, 2);
            // Round half up on the third decimal (decimal(15,2) never has one,
            // but a SUM over SQLite floats can).
            if ((int) $fraction[2] >= 5) {
                $cents++;
            }

            return $m[1] === '-' ? -$cents : $cents;
        }

        return (int) round(((float) $value) * 100);
    }
}
