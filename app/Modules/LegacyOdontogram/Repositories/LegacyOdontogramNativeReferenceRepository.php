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
            /*
             * `whereHas` on the odontogram relation, not a manual join: both
             * models use SoftDeletes, so the relation's own global scope
             * excludes soft-deleted odontograms without this query having to
             * remember to. The ClinicVisit query builder does the same for
             * soft-deleted visits.
             *
             * LEGACY-ODONTOGRAM-NATIVE-REFERENCE-CUTOFF-1 — the relation must
             * also carry CLINICAL CONTENT. Bare existence used to be the whole
             * test, which let a contentless placeholder both open the
             * eligibility gate and draw the chronological bound on a date where
             * nothing was ever charted.
             *
             * `IS NOT NULL` is the whole SQL half, and deliberately so: it is
             * the only form that is safe on BOTH drivers. `tooth_map_payload`
             * is `jsonb` on PostgreSQL, where comparing it to a string is a
             * hard error (there is no `jsonb = text` operator) that SQLite
             * silently tolerates — such a predicate would pass every local test
             * and then fail in production. This mirrors, verbatim, the
             * constraint already documented on the Patient Odontogram History
             * query in OdontogramRepository — the SQL half only. History applies
             * its PHP half in the SERVICE; this repository applies its own below,
             * because the interface returns a single ?ClinicVisit and the early
             * exit has to happen inside the iteration. Moving it out would force
             * this to return a collection and leak the emptiness rule into a
             * second layer, which is the duplication this sprint removed.
             */
            ->whereHas('odontogram', fn ($odontogram) => $odontogram->whereNotNull('tooth_map_payload'))
            /*
             * The eager load is deliberately UNCONSTRAINED while the `whereHas`
             * above is constrained, and that is only safe because
             * `trx_odontograms.clinic_visit_id` is UNIQUE
             * (`trx_odontograms_clinic_visit_id_unique`): a visit has at most one
             * odontogram, so the row loaded here is necessarily the same row the
             * existence subquery matched. Were that constraint ever dropped, this
             * would have to carry the same payload predicate.
             */
            ->with('odontogram')
            // Deterministic across PostgreSQL and SQLite: same-day visits break
            // the tie on the surrogate key rather than on row order.
            ->orderBy('visit_date')
            ->orderBy('id')
            /*
             * The PHP half. SQL cannot portably express "this JSON object has
             * at least one key", so the shapes it cannot reach — `{"teeth": {}}`,
             * a payload with no `teeth` key at all — are settled here by the one
             * canonical predicate, `Odontogram::hasRecordedTeeth()`, the same
             * method the doctor's Patient Odontogram History applies. A row the
             * doctor is never shown as history can therefore never bound, or
             * gate, that patient's archive.
             *
             * `lazy()` walks the already-ordered, already-narrowed candidates and
             * stops at the first real chart. The chunk size is set explicitly:
             * the default is 1000, which would fetch far more of a patient's
             * history than this ever needs, and the SQL half has already removed
             * the NULL-payload placeholders — so in practice the first row is the
             * answer and one chunk is fetched. The explicit `orderBy` above is
             * what makes the offset-paged walk deterministic; `lazy()` does not
             * impose an order of its own.
             */
            ->lazy(25)
            ->first(fn (ClinicVisit $visit) => $visit->odontogram?->hasRecordedTeeth() === true);
    }
}
