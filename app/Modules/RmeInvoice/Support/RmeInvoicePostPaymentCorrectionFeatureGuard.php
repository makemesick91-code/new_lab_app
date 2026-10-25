<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Services\Foundation\FeatureFlagService;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — the runtime switch for correcting
 * an invoice that has already received money (owner decision PP-10: a separate
 * switch, default OFF, activated only by its own owner decision).
 *
 * It is a SECOND gate on top of RmeInvoiceCorrectionFeatureGuard: a POST_PAYMENT
 * submit or approve needs BOTH flags on. Turning this one on can therefore never
 * widen anything the pre-payment capability does, and turning it off never touches
 * a pre-payment correction.
 *
 * Reject and cancel are NOT gated by it — an active correction blocks payment of
 * its invoice (D10), so the emergency stop must never strand one.
 *
 * Enabling post-payment correction is NOT a matter of adding the type to
 * RmeInvoiceCorrectionType::ENABLED; that list stays PRE_PAYMENT-only and the
 * post-payment branch of the engine asks this guard instead.
 */
final class RmeInvoicePostPaymentCorrectionFeatureGuard
{
    public const FLAG = 'rme.invoice_postpayment_correction';

    public function __construct(
        private readonly FeatureFlagService $flags,
    ) {}

    public function enabled(): bool
    {
        return $this->flags->enabled(self::FLAG);
    }

    /**
     * @throws ValidationException
     */
    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages([
                'correction_type' => 'Koreksi tagihan yang sudah dibayar belum diaktifkan.',
            ]);
        }
    }
}
