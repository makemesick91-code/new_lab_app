<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Services;

use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramNativeReferenceRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use Carbon\CarbonImmutable;

/**
 * FIX-04b — the SINGLE source of truth for a patient's earliest NATIVE
 * odontogram date.
 *
 * "Native" means: an odontogram produced by this system's own examination
 * workflow (`trx_odontograms`). Its clinical date is the owning visit's
 * `trx_clinic_visits.visit_date`, which is the canonical clinical date in this
 * codebase.
 *
 * WHY THIS IS NOT PatientEarliestNativeRmeDateResolver. That resolver answers a
 * DIFFERENT question: the earliest visit owning a MEDICAL RECORD. A patient can
 * easily have a medical record on 2022-03-10 and their first odontogram only on
 * 2023-01-05. Reusing it would set the cutoff at 2022-03-10 and happily accept
 * a "legacy" chart dated 2022-06-01 — a legacy odontogram filed AFTER a real
 * native odontogram already exists, which is precisely the chronological
 * corruption this rule exists to prevent. The two facts are not interchangeable
 * and must not share a resolver.
 *
 * Explicitly NOT used as the clinical date (each is a real trap):
 *  - `trx_odontograms.created_at`   — Sprint 59 removed the finalization
 *    edit-lock, so a doctor may revise an old chart today and the row's
 *    timestamps move long after the encounter;
 *  - `trx_odontograms.finalized_at` — nullable workflow timestamp, and since
 *    Sprint 59 a finalized chart is still editable;
 *  - `trx_odontograms.updated_at`   — same problem, worse;
 *  - `mst_patients.registered_at`   — administrative, not clinical.
 *
 * Excluded from the scan: cancelled visits, visits without an odontogram,
 * soft-deleted visits/odontograms, and every legacy archive row (a legacy
 * odontogram lives in its own tables and can never become the native reference
 * — not even a VOID one, since it was never a native examination to begin with).
 *
 * Deliberately NOT branch-scoped. A narrower scan could only move the bound
 * LATER and therefore admit an overlapping document; who may READ an archive is
 * a separate concern, enforced by the policy and the workspace scope.
 *
 * No other layer may re-implement this query: services, controllers, form
 * requests, policies, views and tests all go through this resolver.
 */
class PatientEarliestNativeOdontogramDateResolver
{
    public function __construct(
        private readonly LegacyOdontogramNativeReferenceRepositoryInterface $visits,
    ) {}

    /**
     * The earliest native odontogram date, or null when the patient has no
     * native odontogram at all. Null is meaningful — it is NOT "no restriction",
     * and in regular mode the date rules refuse on it.
     */
    public function resolve(int $patientId): ?CarbonImmutable
    {
        $visit = $this->visits->earliestVisitWithOdontogramForPatient($patientId);

        if ($visit === null || $visit->visit_date === null) {
            return null;
        }

        // visit_date is cast to a date; treat it as an opaque calendar date and
        // never shift it into another timezone — that would only introduce an
        // off-by-one against the value the RME workflow originally stamped.
        return CarbonImmutable::parse($visit->visit_date->toDateString())->startOfDay();
    }

    public function resolveForPatient(Patient $patient): ?CarbonImmutable
    {
        return $this->resolve((int) $patient->getKey());
    }

    /**
     * Convenience for snapshotting into
     * `earliest_native_odontogram_date_snapshot`.
     */
    public function resolveAsDateString(int $patientId): ?string
    {
        return $this->resolve($patientId)?->toDateString();
    }

    public function hasNativeOdontogram(int $patientId): bool
    {
        return $this->resolve($patientId) !== null;
    }
}
