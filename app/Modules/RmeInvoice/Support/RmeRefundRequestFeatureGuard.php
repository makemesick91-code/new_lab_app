<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Services\Foundation\FeatureFlagService;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215; owner decision RF-3) —
 * the refund request capability switch. Default OFF, fail-closed: while OFF every
 * route answers 404 and every service call is refused.
 */
final class RmeRefundRequestFeatureGuard
{
    public const FLAG = 'rme.billing_refund_request';

    public function __construct(
        private readonly FeatureFlagService $flags,
    ) {}

    public function enabled(): bool
    {
        return $this->flags->enabled(self::FLAG);
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RmeRefundRequestRefused(
                RmeRefundRequestRefused::NOT_ENABLED,
                'Permintaan refund belum diaktifkan.',
            );
        }
    }
}
