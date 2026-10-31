<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use RuntimeException;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215) — a refusal with a
 * stable, machine-readable code (design §9). A refusal that would reveal another
 * branch's record is rendered 404 ($notFound); every other one is a validation-
 * style refusal whose message carries its code in brackets.
 */
final class RmeRefundRequestRefused extends RuntimeException
{
    public const NOT_ENABLED = 'REFUND_REQUEST_NOT_ENABLED';

    public const MAKER_NOT_AUTHORIZED = 'REFUND_MAKER_NOT_AUTHORIZED';

    public const REVIEWER_NOT_AUTHORIZED = 'REFUND_REVIEWER_NOT_AUTHORIZED';

    public const PILOT_SCOPE_DENIED = 'REFUND_PILOT_SCOPE_DENIED';

    public const PILOT_IDENTITY_DENIED = 'REFUND_PILOT_IDENTITY_DENIED';

    public const ACTOR_UNUSABLE = 'REFUND_ACTOR_UNUSABLE';

    public const INTENT_INVALID = 'REFUND_INTENT_INVALID';

    public const INTENT_REUSED = 'REFUND_INTENT_REUSED';

    public const OBLIGATION_NOT_FOUND = 'REFUND_OBLIGATION_NOT_FOUND';

    public const OBLIGATION_NOT_OPEN = 'REFUND_OBLIGATION_NOT_OPEN';

    public const OBLIGATION_CHANGED = 'REFUND_OBLIGATION_CHANGED';

    public const INVOICE_VERSION_CHANGED = 'REFUND_INVOICE_VERSION_CHANGED';

    public const LEDGER_MISMATCH = 'REFUND_LEDGER_MISMATCH';

    public const INVOICE_NOT_PAID = 'REFUND_INVOICE_NOT_PAID';

    public const CORRECTION_ACTIVE = 'REFUND_CORRECTION_ACTIVE';

    public const INVOICE_DISPUTED = 'REFUND_INVOICE_DISPUTED';

    public const ACTIVE_REQUEST_EXISTS = 'REFUND_ACTIVE_REQUEST_EXISTS';

    public const ALREADY_DECIDED = 'REFUND_REQUEST_ALREADY_DECIDED';

    public const INVALID_TRANSITION = 'REFUND_INVALID_TRANSITION';

    public const SELF_APPROVAL_FORBIDDEN = 'REFUND_SELF_APPROVAL_FORBIDDEN';

    public const SELF_DECISION_FORBIDDEN = 'REFUND_SELF_DECISION_FORBIDDEN';

    public const REQUEST_STALE = 'REFUND_REQUEST_STALE';

    public const OWNER_REFERENCE_REQUIRED = 'REFUND_OWNER_REFERENCE_REQUIRED';

    public const OWNER_REFERENCE_INVALID = 'REFUND_OWNER_REFERENCE_INVALID';

    public const OWNER_REFERENCE_REUSED = 'REFUND_OWNER_REFERENCE_REUSED';

    public const REASON_REQUIRED = 'REFUND_REASON_REQUIRED';

    public const PATIENT_REFERENCE_REQUIRED = 'REFUND_PATIENT_REFERENCE_REQUIRED';

    public const PATIENT_REFERENCE_INVALID = 'REFUND_PATIENT_REFERENCE_INVALID';

    public const PATIENT_REFERENCE_REUSED = 'REFUND_PATIENT_REFERENCE_REUSED';

    /** FEATURE-RME-REFUND-SETTLEMENT-1 — an approved request with a payout in flight is never revoked. */
    public const SETTLEMENT_IN_PROGRESS = 'REFUND_SETTLEMENT_IN_PROGRESS';

    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly bool $notFound = false,
        public readonly ?int $refundRequestId = null,
        public readonly ?int $invoiceId = null,
        public readonly string $field = 'refund',
    ) {
        parent::__construct($message);
    }

    /** Existence must not leak: rendered 404, the same answer as a missing row. */
    public static function notFound(?int $invoiceId = null, ?int $refundRequestId = null): self
    {
        return new self(self::OBLIGATION_NOT_FOUND, 'Data tidak ditemukan.', true, $refundRequestId, $invoiceId);
    }

    public function displayMessage(): string
    {
        return '['.$this->reasonCode.'] '.$this->getMessage();
    }
}
