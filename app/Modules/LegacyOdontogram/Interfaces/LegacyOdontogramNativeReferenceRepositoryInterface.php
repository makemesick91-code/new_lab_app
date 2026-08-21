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
     * The earliest non-cancelled visit that owns a native odontogram, or null
     * when the patient has none. Null is meaningful — it is NOT "no restriction".
     */
    public function earliestVisitWithOdontogramForPatient(int $patientId): ?ClinicVisit;
}
