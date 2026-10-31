<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Services\Foundation\FeatureFlagService;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216; RF-3 order) — the settlement switch.
 * Default OFF, fail-closed, and it needs the refund REQUEST capability as well:
 * there is nothing to settle without approved requests, and a rollback of the
 * request capability must also stop payouts. While either is off every
 * operational settlement route answers 404 and every service call is refused.
 */
final class RmeRefundSettlementFeatureGuard
{
    public const FLAG = 'rme.billing_refund_settlement';

    public function __construct(
        private readonly FeatureFlagService $flags,
    ) {}

    public function enabled(): bool
    {
        return $this->flags->enabled(self::FLAG)
            && $this->flags->enabled(RmeRefundRequestFeatureGuard::FLAG);
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RmeRefundSettlementRefused(
                RmeRefundSettlementRefused::NOT_ENABLED,
                'Pembayaran refund belum diaktifkan.',
            );
        }
    }
}
