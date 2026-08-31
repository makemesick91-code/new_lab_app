<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Interfaces;

use App\Modules\ClinicVisit\Models\ClinicVisit;

/**
 * FIX-04b — the read boundary onto the patient's NATIVE odontogram history.
 *
 * It lives in THIS module, not in the Odontogram module, because it exists only
 * to answer one archive question: "what is the earliest clinical date on which
 * this patient already has a real odontogram?" The Odontogram module owns the
 * examination workflow and must not grow an archive-shaped read.
 *
 * It returns the owning VISIT rather than the odontogram, because the canonical
 * clinical date in this codebase is `trx_clinic_visits.visit_date` — the
 * odontogram row itself has no clinical date column at all.
 */
interface LegacyOdontogramNativeReferenceRepositoryInterface
{
    /**
     * The earliest non-cancelled visit that owns a native odontogram CARRYING
     * CLINICAL CONTENT, or null when the patient has none.
     *
     * LEGACY-ODONTOGRAM-NATIVE-REFERENCE-CUTOFF-1 — "owns an odontogram" used
     * to mean a row exists. A contentless placeholder is not evidence, so it
     * does not draw the chronological bound. The content test is
     * `Odontogram::hasRecordedTeeth()`, the same predicate the doctor's Patient
     * Odontogram History applies.
     *
     * REVISION-LEGACY-ODONTOGRAM-NATIVE-OPTIONAL-1 — this answer no longer
     * gates ELIGIBILITY, only the BOUND. A patient with none may still be
     * archived against; the archive simply has no native history to sit before.
     *
     * Null therefore means "none found" and nothing else. An implementation
     * must never convert a query failure into null: since absence now allows,
     * that would turn a database fault into an unbounded archive. Let it throw.
     */
    public function earliestVisitWithOdontogramForPatient(int $patientId): ?ClinicVisit;
}
