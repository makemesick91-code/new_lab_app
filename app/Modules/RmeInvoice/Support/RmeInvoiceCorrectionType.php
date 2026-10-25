<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — what kind of correction.
 *
 * PRE_PAYMENT — the invoice has NO payment. Implemented (behind the flag).
 * POST_PAYMENT — the invoice has at least one payment. IMPLEMENTED by
 *   FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 (owner decisions PP-1..PP-10,
 *   rule 207) and gated by its OWN switch `rme.invoice_postpayment_correction`
 *   (RmeInvoicePostPaymentCorrectionFeatureGuard), default OFF. The type is
 *   always derived by the service from the payment ledger, never submitted.
 *
 * ENABLED below stays PRE_PAYMENT-only on purpose: post-payment correction is
 * never switched on by editing this list, only by its own switch, so turning on
 * the post-payment flag cannot be confused with — or slipped into — a change of
 * this constant.
 */
final class RmeInvoiceCorrectionType
{
    public const PRE_PAYMENT = 'PRE_PAYMENT';

    public const POST_PAYMENT = 'POST_PAYMENT';

    public const ALL = [self::PRE_PAYMENT, self::POST_PAYMENT];

    /** Types the service will actually accept today. */
    public const ENABLED = [self::PRE_PAYMENT];

    public const LABELS = [
        self::PRE_PAYMENT => 'Koreksi Sebelum Pembayaran',
        self::POST_PAYMENT => 'Koreksi Setelah Pembayaran',
    ];

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }
}
