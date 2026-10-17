<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use RuntimeException;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — owner decision D10: an invoice with an
 * ACTIVE correction (SUBMITTED = pending approval, APPROVED = approved but not
 * applied) must not accept a payment.
 *
 * Raised under the invoice row lock by RmePaymentService, for EVERY invoice a
 * payment batch would settle. It rolls the WHOLE batch back — no payment on any
 * invoice, no status change, no visit completion — and is audited after the
 * rollback (it records an attempt).
 *
 * Payment resumes once the correction is REJECTED, CANCELLED, STALE or
 * APPLIED; after APPLIED the payment is still bound to the new
 * `correction_version` (rule 191), so a form opened before it is refused.
 */
final class RmeInvoicePaymentBlocked extends RuntimeException
{
    public const REASON = 'INVOICE_CORRECTION_ACTIVE';

    public const MESSAGE = 'Tagihan sedang dalam proses koreksi. Pembayaran dapat dilanjutkan setelah proses koreksi selesai.';

    public function __construct(
        public readonly int $invoiceId,
        public readonly ?string $invoiceNumber,
        public readonly int $correctionId,
        public readonly string $correctionStatus,
    ) {
        parent::__construct(self::MESSAGE);
    }

    /** Operator message, naming the invoice so a multi-invoice batch is unambiguous. */
    public function operatorMessage(): string
    {
        $which = $this->invoiceNumber !== null && $this->invoiceNumber !== ''
            ? ' ('.$this->invoiceNumber.')'
            : '';

        return '['.self::REASON.'] Tagihan'.$which.' sedang dalam proses koreksi. Pembayaran dapat dilanjutkan setelah proses koreksi selesai.';
    }
}
