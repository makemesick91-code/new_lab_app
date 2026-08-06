<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use Carbon\CarbonImmutable;

/**
 * LEGACY-RME-PDF-1A — the SINGLE source of truth for a patient's earliest
 * NATIVE RME date.
 *
 * "Native" means: a medical record produced by this system's own RME workflow.
 * Its clinical date is the owning visit's `trx_clinic_visits.visit_date` — the
 * canonical clinical date in this codebase.
 *
 * Explicitly NOT used as the clinical date (each is a real trap):
 *  - `trx_medical_records.created_at`   — Sprint 59 removed the finalize lock
 *    and Sprint 64.0 lets a doctor open a sheet from a later visit, so the row
 *    can be written long after the encounter;
 *  - `trx_medical_records.finalized_at` — nullable workflow timestamp;
 *  - `trx_medical_records.canonical_visit_id` — a cache, not the source of
 *    truth (its own migration says so);
 *  - `mst_patients.registered_at`       — administrative, not clinical.
 *
 * Excluded from the scan: cancelled visits, visits without a medical record,
 * soft-deleted visits/records, and every legacy archive row (a legacy record
 * lives in its own tables and can never become the native reference — not even
 * a VOID one, since it is never a native encounter to begin with).
 *
 * No other layer may re-implement this query: controllers, form requests,
 * policies, views and tests must all go through this resolver.
 */
class PatientEarliestNativeRmeDateResolver
{
    public function __construct(
        private readonly ClinicVisitRepositoryInterface $visits,
    ) {}

    /**
     * The earliest native RME date, or null when the patient has no native RME
     * at all. Null is meaningful — it is NOT "no restriction".
     */
    public function resolve(int $patientId): ?CarbonImmutable
    {
        $visit = $this->visits->earliestVisitWithMedicalRecordForPatient($patientId);

        if ($visit === null || $visit->visit_date === null) {
            return null;
        }

        // visit_date is cast to a date; treat it as an opaque calendar date and
        // never shift it into another timezone (that would only introduce a new
        // off-by-one against the value the RME workflow originally stamped).
        return CarbonImmutable::parse($visit->visit_date->toDateString())->startOfDay();
    }

    public function resolveForPatient(Patient $patient): ?CarbonImmutable
    {
        return $this->resolve((int) $patient->getKey());
    }

    /**
     * Convenience for snapshotting into `earliest_native_rme_date_snapshot`.
     */
    public function resolveAsDateString(int $patientId): ?string
    {
        return $this->resolve($patientId)?->toDateString();
    }

    public function hasNativeRme(int $patientId): bool
    {
        return $this->resolve($patientId) !== null;
    }
}
