<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Support;

/**
 * FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219, owner DPT-Q1 revised and
 * approved) — the OPERATIONAL classification of a visit, derived from the
 * doctor's explicit declaration.
 *
 * KONTROL here means "the doctor declared that no medical procedure was performed
 * at this visit". It is deliberately a separate concept from:
 *
 *   - ClinicVisit::$visit_type ('new' / 'control' …) — the registration-time type,
 *     never rewritten by this classification;
 *   - ClinicVisit::$follow_up_of_visit_id — the server-derived parent link, never
 *     created or inferred by it;
 *   - RmeZeroValueEligibility — whether a Rp0 invoice may close NO_CHARGE, which
 *     still requires a REAL Kontrol parent (S6-Q1) and approved free lines (S6-Q3).
 *
 * OPERATIONAL_CONTROL != AUTOMATIC_NO_CHARGE.
 */
final class RmeOperationalVisitCategory
{
    public const CONTROL = 'control';

    public static function label(?string $category): string
    {
        return $category === self::CONTROL ? 'KONTROL' : '—';
    }
}
