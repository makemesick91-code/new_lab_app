<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use RuntimeException;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — a line refused by STRICT tariff
 * authority (D21). Carries a stable code so a refusal is audited after the
 * invoice transaction rolled back, then surfaced as a validation error.
 *
 * Ordinary input errors (qty < 1, a negative number, a discount above the line)
 * are plain validation errors and are NOT this type: they are not a price
 * authority decision.
 */
final class RmeInvoicePricingRefusal extends RuntimeException
{
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
