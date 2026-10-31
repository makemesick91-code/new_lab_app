<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use RuntimeException;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — a refusal with a stable,
 * machine-readable code. A refusal that would reveal another branch's record is
 * rendered 404 ($notFound); every other one is a validation-style refusal whose
 * message carries its code in brackets.
 */
final class RmeRefundSettlementRefused extends RuntimeException
{
    public const NOT_ENABLED = 'SETTLEMENT_NOT_ENABLED';

    public const NOT_FOUND = 'SETTLEMENT_NOT_FOUND';

    public const ACTOR_UNUSABLE = 'SETTLEMENT_ACTOR_UNUSABLE';

    public const SETTLER_NOT_AUTHORIZED = 'SETTLEMENT_SETTLER_NOT_AUTHORIZED';

    public const PILOT_SCOPE_DENIED = 'SETTLEMENT_PILOT_SCOPE_DENIED';

    public const SETTLER_IS_APPROVER = 'SETTLEMENT_SETTLER_IS_APPROVER';

    public const NOT_STARTER = 'SETTLEMENT_NOT_STARTER';

    public const REQUEST_NOT_APPROVED = 'SETTLEMENT_REQUEST_NOT_APPROVED';

    public const OBLIGATION_NOT_OPEN = 'SETTLEMENT_OBLIGATION_NOT_OPEN';

    public const REQUEST_STALE = 'SETTLEMENT_REQUEST_STALE';

    public const CORRECTION_ACTIVE = 'SETTLEMENT_CORRECTION_ACTIVE';

    public const INVOICE_DISPUTED = 'SETTLEMENT_INVOICE_DISPUTED';

    public const SETTLEMENT_EXISTS = 'SETTLEMENT_ALREADY_EXISTS';

    public const INVALID_TRANSITION = 'SETTLEMENT_INVALID_TRANSITION';

    public const AMOUNT_MISMATCH = 'SETTLEMENT_AMOUNT_MISMATCH';

    public const METHOD_INVALID = 'SETTLEMENT_METHOD_INVALID';

    public const INTENT_INVALID = 'SETTLEMENT_INTENT_INVALID';

    public const INTENT_REUSED = 'SETTLEMENT_INTENT_REUSED';

    public const REFERENCE_REQUIRED = 'SETTLEMENT_REFERENCE_REQUIRED';

    public const REFERENCE_INVALID = 'SETTLEMENT_REFERENCE_INVALID';

    public const REFERENCE_REUSED = 'SETTLEMENT_REFERENCE_REUSED';

    public const DESTINATION_INVALID = 'SETTLEMENT_DESTINATION_INVALID';

    public const RECIPIENT_INVALID = 'SETTLEMENT_RECIPIENT_INVALID';

    public const EVIDENCE_REQUIRED = 'SETTLEMENT_EVIDENCE_REQUIRED';

    public const EVIDENCE_INVALID = 'SETTLEMENT_EVIDENCE_INVALID';

    public const EVIDENCE_REUSED = 'SETTLEMENT_EVIDENCE_REUSED';

    public const ATTESTATION_REQUIRED = 'SETTLEMENT_ATTESTATION_REQUIRED';

    public const REASON_REQUIRED = 'SETTLEMENT_REASON_REQUIRED';

    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly bool $notFound = false,
        public readonly ?int $settlementId = null,
        public readonly ?int $refundRequestId = null,
        public readonly string $field = 'settlement',
    ) {
        parent::__construct($message);
    }

    /** Existence must not leak: rendered 404, the same answer as a missing row. */
    public static function notFound(?int $refundRequestId = null, ?int $settlementId = null): self
    {
        return new self(self::NOT_FOUND, 'Data tidak ditemukan.', true, $settlementId, $refundRequestId);
    }

    public function displayMessage(): string
    {
        return '['.$this->reasonCode.'] '.$this->getMessage();
    }
}
