<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Repositories;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramNativeReferenceRepositoryInterface;

class LegacyOdontogramNativeReferenceRepository implements LegacyOdontogramNativeReferenceRepositoryInterface
{
    public function earliestVisitWithOdontogramForPatient(int $patientId): ?ClinicVisit
    {
        return ClinicVisit::query()
            ->where('patient_id', $patientId)
            // A cancelled visit never happened clinically, so an odontogram
            // hanging off one is not a reference point either.
            ->where('status', '!=', ClinicVisit::STATUS_CANCELLED)
            // `whereHas` on the odontogram relation, not a manual join: both
            // models use SoftDeletes, so the relation's own global scope
            // excludes soft-deleted odontograms without this query having to
            // remember to. The ClinicVisit query builder does the same for
            // soft-deleted visits.
            ->whereHas('odontogram')
            // Deterministic across PostgreSQL and SQLite: same-day visits break
            // the tie on the surrogate key rather than on row order.
            ->orderBy('visit_date')
            ->orderBy('id')
            ->first();
    }
}
