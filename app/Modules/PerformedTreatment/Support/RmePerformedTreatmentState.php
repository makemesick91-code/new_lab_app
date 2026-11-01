<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Support;

use App\Modules\PerformedTreatment\Models\RmeTreatmentDeclaration;

/**
 * FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — the visit's clinical
 * declaration state, derived (never stored) from the doctor's explicit choice and
 * the active rows. Branch on the CODE.
 *
 *   NOT_RECORDED              no explicit "no treatment" and no active performed
 *                             row. An empty list is NOT a declaration (DPT-Q1):
 *                             it is never KONTROL and never free.
 *   HAS_PERFORMED_TREATMENTS  at least one active (not voided) performed row.
 *   NO_TREATMENT_DECLARED     the doctor explicitly declared that nothing was
 *                             performed (=> operational category KONTROL).
 *
 * The service never lets "no treatment" coexist with an active row; if the data
 * ever disagreed, the active row wins (evidence of a procedure is never hidden).
 */
final class RmePerformedTreatmentState
{
    public const NOT_RECORDED = 'NOT_RECORDED';

    public const HAS_PERFORMED_TREATMENTS = 'HAS_PERFORMED_TREATMENTS';

    public const NO_TREATMENT_DECLARED = 'NO_TREATMENT_DECLARED';

    public const STATES = [self::NOT_RECORDED, self::HAS_PERFORMED_TREATMENTS, self::NO_TREATMENT_DECLARED];

    public static function derive(?string $declarationType, int $activeRowCount): string
    {
        if ($activeRowCount > 0) {
            return self::HAS_PERFORMED_TREATMENTS;
        }

        if ($declarationType === RmeTreatmentDeclaration::TYPE_NO_TREATMENT) {
            return self::NO_TREATMENT_DECLARED;
        }

        return self::NOT_RECORDED;
    }

    /** The operational category a state implies (DPT-Q1). Only an explicit no-treatment declaration is KONTROL. */
    public static function operationalCategory(string $state): ?string
    {
        return $state === self::NO_TREATMENT_DECLARED ? RmeOperationalVisitCategory::CONTROL : null;
    }

    public static function label(?string $state): string
    {
        return match ($state) {
            self::HAS_PERFORMED_TREATMENTS => 'Ada tindakan dicatat dokter',
            self::NO_TREATMENT_DECLARED => 'Tidak ada tindakan (deklarasi dokter)',
            self::NOT_RECORDED => 'Belum dicatat dokter',
            default => 'Tidak dievaluasi',
        };
    }
}
