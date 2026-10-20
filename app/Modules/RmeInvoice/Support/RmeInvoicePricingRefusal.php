<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use RuntimeException;

/**
 * A line the server refuses for an AUTHORITY reason (not a typing error).
 * Carries a stable code so a refusal is audited after the invoice transaction
 * rolled back, then surfaced as a validation error.
 *
 * REVISION-RME-MANUAL-PRICING-AUTHORITY-1: under manual pricing the only
 * authority refusal is TREATMENT_INVALID — a line naming a treatment that does
 * not exist, is soft-deleted or is inactive (a forged or stale id). The TARIFF_*
 * codes belong to the LEGACY_SUPERSEDED strict tariff mode.
 *
 * Ordinary input errors (qty < 1, a negative number, malformed money, a
 * discount above the line, overflow) are plain validation errors and are NOT
 * this type: they are not an authority decision.
 */
final class RmeInvoicePricingRefusal extends RuntimeException
{
    public const TREATMENT_INVALID = 'INVOICE_TREATMENT_INVALID';

    public const FREE_TEXT_NOT_PERMITTED = 'TARIFF_FREE_TEXT_NOT_PERMITTED';

    public const TREATMENT_INACTIVE = 'TARIFF_TREATMENT_INACTIVE';

    public const TARIFF_MISSING = 'TARIFF_MISSING';

    public const TARIFF_ZERO_PRICE = 'TARIFF_ZERO_PRICE';

    public const PRICE_MISMATCH = 'TARIFF_PRICE_MISMATCH';

    public const DISCOUNT_NOT_AUTHORIZED = 'TARIFF_DISCOUNT_NOT_AUTHORIZED';

    public const EXCEPTION_REASON_REQUIRED = 'TARIFF_EXCEPTION_REASON_REQUIRED';

    public const RP0_NOT_PERMITTED = 'TARIFF_RP0_NOT_PERMITTED';

    public function __construct(
        public readonly string $refusalCode,
        string $message,
        public readonly ?int $lineNo = null,
        public readonly ?int $treatmentId = null,
    ) {
        parent::__construct($message);
    }

    /** The operator-facing text, prefixed with the stable code. */
    public function operatorMessage(): string
    {
        return '['.$this->refusalCode.'] '.$this->getMessage();
    }
}
