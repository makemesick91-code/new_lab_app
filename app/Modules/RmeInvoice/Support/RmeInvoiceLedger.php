<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\RmeInvoice\Models\RmeInvoice;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — the ONE definition of an
 * invoice's status once money has been received, and of the balance figures that
 * follow from it (rule 206: "a single shared helper").
 *
 * Used by the payment path (every payment row recorded) and by the post-payment
 * correction apply, so the two can never disagree about whether an invoice is
 * PAID or PARTIAL:
 *
 *   paid >= grand total  =>  PAID
 *   paid <  grand total  =>  PARTIAL
 *
 * It is only ever asked about an invoice that has received money (paid > 0); an
 * invoice with no payment keeps whatever status its own lifecycle gave it, which
 * is why there is no UNPAID branch here.
 *
 * Money is compared in integer cents: a float comparison of decimal(15,2) totals
 * is how "lunas" quietly becomes "sebagian" over a rounding residue.
 *
 *   outstanding = max(0, total − paid)   — still receivable, never negative
 *   overpaid    = max(0, paid − total)   — owed back to the patient (PP-3 A)
 *
 * FEATURE-RME-REFUND-SETTLEMENT-1 (RFS-1/RFS-2): "paid" in these three helpers is
 * NET received — gross payments minus SETTLED refunds — supplied by every caller
 * through netReceivedCents(). A settled refund therefore never reappears as an
 * overpayment, and a later correction never counts refunded money as received.
 *
 * At most one of the two is positive; both are zero when the ledger balances.
 */
final class RmeInvoiceLedger
{
    public static function statusAfterMoneyReceived(int $grandTotalCents, int $paidCents): string
    {
        return $paidCents >= $grandTotalCents ? RmeInvoice::STATUS_PAID : RmeInvoice::STATUS_PARTIAL;
    }

    public static function outstandingCents(int $grandTotalCents, int $paidCents): int
    {
        return max(0, $grandTotalCents - $paidCents);
    }

    public static function overpaidCents(int $grandTotalCents, int $paidCents): int
    {
        return max(0, $paidCents - $grandTotalCents);
    }

    /**
     * GROSS money received of an invoice in cents (Σ incoming payments), read fresh.
     * A display figure: never feed it to status/outstanding/overpaid — use
     * netReceivedCents(), or a settled refund looks like a new overpayment.
     */
    public static function paidCents(RmeInvoice $invoice): int
    {
        return RmeInvoiceDocument::toCents(round((float) $invoice->payments()->sum('amount'), 2));
    }

    /**
     * FEATURE-RME-REFUND-SETTLEMENT-1 (RFS-1) — money verifiably paid BACK on an
     * invoice, in cents: Σ SETTLED refund settlements. Read fresh (call under the
     * invoice lock when it decides anything).
     */
    public static function settledRefundCents(RmeInvoice $invoice): int
    {
        return RmeInvoiceDocument::toCents(round((float) $invoice->refundSettlements()
            ->where('status', RmeRefundSettlementStatus::SETTLED)
            ->sum('amount'), 2));
    }

    /**
     * RFS-1 — NET money received in cents: gross payments − settled refunds. The
     * one input of status, outstanding and overpaid (RFS-2): after a refund is
     * settled the invoice balances (overpaid' = 0) and a later correction measures
     * against what the clinic actually kept.
     */
    public static function netReceivedCents(RmeInvoice $invoice): int
    {
        return self::paidCents($invoice) - self::settledRefundCents($invoice);
    }

    public static function grandTotalCents(RmeInvoice $invoice): int
    {
        return RmeInvoiceDocument::toCents($invoice->grand_total);
    }

    /**
     * FEATURE-RME-REFUND-SETTLEMENT-1 (RFS-1) — the SQL form of netReceivedCents()
     * for readers that aggregate many invoices (receivable lists, dashboards,
     * oversight KPIs): one row per invoice, `rme_invoice_id, paid_total`, where
     * paid_total is NET received = Σ payments − Σ SETTLED refund settlements.
     * Every SQL receivable reader joins this instead of summing trx_rme_payments,
     * so a refund paid out is never counted as money still received.
     */
    public static function netReceivedTotalsQuery(bool $excludeDeletedPayments = false): Builder
    {
        $payments = DB::table('trx_rme_payments')
            ->select('rme_invoice_id', 'amount')
            ->when($excludeDeletedPayments, fn ($query) => $query->whereNull('deleted_at'));

        $refunds = DB::table('trx_rme_refund_settlements')
            ->select('rme_invoice_id')
            ->selectRaw('0 - amount AS amount')
            ->where('status', RmeRefundSettlementStatus::SETTLED);

        return DB::query()
            ->fromSub($payments->unionAll($refunds), 'rme_ledger_lines')
            ->select('rme_invoice_id')
            ->selectRaw('SUM(amount) AS paid_total')
            ->groupBy('rme_invoice_id');
    }

    /**
     * RFS-1 — a correlated SQL expression for the NET received of the invoice whose
     * id is in $invoiceIdColumn (a code literal such as `trx_rme_invoices.id`, never
     * input: anything else is refused).
     */
    public static function netReceivedSql(string $invoiceIdColumn): string
    {
        if (preg_match('/^[a-z_]+\.[a-z_]+$/D', $invoiceIdColumn) !== 1) {
            throw new InvalidArgumentException('Invalid invoice id column for the net-received expression.');
        }

        return '((SELECT COALESCE(SUM(trx_rme_payments.amount), 0) FROM trx_rme_payments WHERE trx_rme_payments.rme_invoice_id = '.$invoiceIdColumn.')'
            .' - (SELECT COALESCE(SUM(trx_rme_refund_settlements.amount), 0) FROM trx_rme_refund_settlements WHERE trx_rme_refund_settlements.rme_invoice_id = '.$invoiceIdColumn." AND trx_rme_refund_settlements.status = '".RmeRefundSettlementStatus::SETTLED."'))";
    }
}
