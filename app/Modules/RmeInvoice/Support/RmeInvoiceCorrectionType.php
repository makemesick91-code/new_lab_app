<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — what kind of correction.
 *
 * PRE_PAYMENT — the invoice has NO payment. Implemented (behind the flag).
 * POST_PAYMENT — the invoice has at least one payment. DECLARED, NOT ENABLED:
 *   it changes a settled balance and therefore needs the owner's decisions on
 *   receivable/overpayment treatment (D2, D7, D8, D9, D12, D13) and a refund
 *   consumer for any overpayment it produces. The service refuses it with
 *   CORRECTION_TYPE_NOT_ENABLED; the value exists only so the schema and the
 *   oversight vocabulary do not need a migration when it is built.
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
