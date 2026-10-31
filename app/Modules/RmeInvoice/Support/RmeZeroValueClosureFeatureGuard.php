<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Services\Foundation\FeatureFlagService;

/**
 * FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217, owner decision S6-D8) — the
 * "Selesai Tanpa Biaya" switch. Default OFF and fail-closed: while it is off the
 * closure route answers 404, every service call is refused and every cashier page
 * renders exactly as it did before the capability existed.
 */
final class RmeZeroValueClosureFeatureGuard
{
    public const FLAG = 'rme.zero_value_closure';

    public function __construct(
        private readonly FeatureFlagService $flags,
    ) {}

    public function enabled(): bool
    {
        return $this->flags->enabled(self::FLAG);
    }
}
