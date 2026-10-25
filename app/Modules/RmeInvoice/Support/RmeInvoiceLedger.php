<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\RmeInvoice\Models\RmeInvoice;

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

    /** Paid money of an invoice in cents, read fresh (call under the invoice lock). */
    public static function paidCents(RmeInvoice $invoice): int
    {
        return RmeInvoiceDocument::toCents(round((float) $invoice->payments()->sum('amount'), 2));
    }

    public static function grandTotalCents(RmeInvoice $invoice): int
    {
        return RmeInvoiceDocument::toCents($invoice->grand_total);
    }
}
