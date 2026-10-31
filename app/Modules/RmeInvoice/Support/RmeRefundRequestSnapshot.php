<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215; design §7.1 step 8) —
 * the financial state a refund request was filed against: ids, versions and cents
 * only (no identity, no free text). The approval recomputes it from the locked
 * rows; any difference makes the request STALE.
 */
final class RmeRefundRequestSnapshot
{
    public const SCHEMA_VERSION = 1;

    /** @return array<string, mixed> */
    public static function capture(RmeInvoice $invoice, RmeOverpaymentObligation $obligation): array
    {
        $paymentIds = $invoice->payments()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'schema' => self::SCHEMA_VERSION,
            'invoice_id' => (int) $invoice->id,
            'invoice_version' => (int) ($invoice->correction_version ?? 0),
            'invoice_status' => (string) $invoice->status,
            'grand_total_cents' => RmeInvoiceLedger::grandTotalCents($invoice),
            'paid_cents' => RmeInvoiceLedger::paidCents($invoice),
            'payment_ids' => $paymentIds,
            'obligation_id' => (int) $obligation->id,
            'obligation_amount_cents' => self::cents($obligation->amount),
            'obligation_invoice_version' => (int) $obligation->invoice_version,
            'obligation_status' => (string) $obligation->status,
        ];
    }

    /** @param  array<string, mixed>  $snapshot */
    public static function hash(array $snapshot): string
    {
        ksort($snapshot);

        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Exact cents of a stored decimal, the same conversion the invoice ledger uses. */
    public static function cents(mixed $amount): int
    {
        return RmeInvoiceDocument::toCents($amount);
    }

    public static function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
