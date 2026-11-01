<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Support;

use App\Services\Foundation\FeatureFlagService;

/**
 * FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — the capability switch.
 *
 * Read only through FeatureFlagService (CG-08: the dotted key breaks config()
 * traversal). Default OFF; while off the routes answer 404, the service refuses,
 * the doctor workspace and cashier pages render exactly as before, and the rule
 * 218 snapshot keeps its version 1 shape.
 */
final class RmePerformedTreatmentFeatureGuard
{
    public const FLAG = 'rme.doctor_performed_treatments';

    public function __construct(private readonly FeatureFlagService $flags) {}

    public function enabled(): bool
    {
        return $this->flags->enabled(self::FLAG);
    }
}
