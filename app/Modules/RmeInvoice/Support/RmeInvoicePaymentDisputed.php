<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use RuntimeException;

/**
 * IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 (owner OPS-OQ-1, rule
 * 203) — an invoice under an ACTIVE billing dispute must not be collected.
 *
 * Raised under the invoice row lock by RmePaymentService for EVERY invoice a
 * payment batch would settle. It rolls the WHOLE batch back — no payment on any
 * invoice, no status change, no visit completion — and is audited after the
 * rollback (it records an attempt). The batch is never silently trimmed: the
 * money is not redirected to another invoice of the batch.
 *
 * Independent of D10 (RmeInvoicePaymentBlocked): a correction that is rejected,
 * cancelled, stale or applied releases D10, never this hold. Only a RESOLVED
 * dispute releases it, and the payment is then still bound to the current
 * correction_version (rule 191), branch scope, actor and amount rules.
 */
final class RmeInvoicePaymentDisputed extends RuntimeException
{
    public const REASON = 'BILLING_DISPUTE_ACTIVE';

    public function __construct(
        public readonly int $invoiceId,
        public readonly ?string $invoiceNumber,
        public readonly int $disputeId,
        public readonly string $disputeStatus,
    ) {
        parent::__construct(self::messageFor($invoiceNumber));
    }

    /** Operator message, naming the invoice so a multi-invoice batch is unambiguous. */
    public function operatorMessage(): string
    {
        return self::messageFor($this->invoiceNumber);
    }

    public static function messageFor(?string $invoiceNumber): string
    {
        $which = $invoiceNumber !== null && $invoiceNumber !== '' ? ' ('.$invoiceNumber.')' : '';

        return '['.self::REASON.'] Tagihan'.$which.' ini sedang dalam peninjauan karena terdapat ketidaksesuaian nominal. '
            .'Pembayaran sementara tidak dapat diproses. Silakan hubungi penanggung jawab cabang.';
    }
}
