<?php

namespace App\Modules\Odontogram\Repositories;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Interfaces\OdontogramRepositoryInterface;
use App\Modules\Odontogram\Models\Odontogram;
use Closure;
use Illuminate\Support\Collection;

class OdontogramRepository implements OdontogramRepositoryInterface
{
    public function findByClinicVisit(int $clinicVisitId): ?Odontogram
    {
        return Odontogram::query()->where('clinic_visit_id', $clinicVisitId)->first();
    }

    public function createForClinicVisit(ClinicVisit $clinicVisit, array $data = []): Odontogram
    {
        $existing = $this->findByClinicVisit($clinicVisit->id);

        if ($existing !== null) {
            return $existing;
        }

        return Odontogram::create(array_merge([
            'clinic_visit_id' => $clinicVisit->id,
            'branch_id' => $clinicVisit->branch_id,
            'medical_record_id' => $clinicVisit->medicalRecord?->id,
            'status' => Odontogram::STATUS_DRAFT,
            'summary_notes' => null,
            'additional_conditions' => null,
            'tooth_map_payload' => null,
        ], $data));
    }

    public function updatePlaceholder(Odontogram $odontogram, array $data): Odontogram
    {
        $odontogram->update($data);

        return $odontogram->refresh();
    }

    public function finalize(Odontogram $odontogram, array $data): Odontogram
    {
        $odontogram->update($data);

        return $odontogram->refresh();
    }

    /**
     * The patient's previous odontograms, newest first.
     *
     * Ordering is by the OWNING VISIT's clinical date (visit_date, then id as a
     * deterministic tie-break), not by the odontogram's own timestamps: a chart
     * may be corrected long after the encounter, and the history is a clinical
     * timeline, not an edit log.
     *
     * Eager-loads everything the history card renders so the list costs a fixed
     * number of queries regardless of how many visits a patient has.
     */
    public function patientHistoryForBranches(
        array $branchIds,
        int $patientId,
        ?int $excludeVisitId = null,
        ?Closure $scope = null,
        int $limit = 50,
    ): Collection {
        if ($branchIds === []) {
            // Fail closed: an unresolved branch set must never widen to "all".
            return collect();
        }

        $query = Odontogram::query()
            ->with(['clinicVisit' => fn ($q) => $q->with(['doctor', 'branch'])])
            // Joined (rather than only whereHas) because the history is ordered by
            // the OWNING VISIT's clinical date. Every column below is table
            // qualified: trx_odontograms and trx_clinic_visits both have
            // branch_id, so an unqualified reference is ambiguous.
            ->join('trx_clinic_visits', 'trx_clinic_visits.id', '=', 'trx_odontograms.clinic_visit_id')
            ->whereIn('trx_odontograms.branch_id', $branchIds)
            ->where('trx_clinic_visits.patient_id', $patientId)
            ->whereIn('trx_clinic_visits.branch_id', $branchIds)
            // A join does not inherit the related model's soft-delete scope.
            ->whereNull('trx_clinic_visits.deleted_at')
            ->when(
                $excludeVisitId !== null,
                fn ($q) => $q->where('trx_clinic_visits.id', '!=', $excludeVisitId),
            )
            ->when(
                $scope !== null,
                fn ($q) => $q->whereHas('clinicVisit', fn ($sub) => $scope($sub)),
            )
            // Clinical-content predicate lives IN the query so `limit` bounds REAL
            // history. Filtering empty drafts only in PHP after the limit would let
            // a long-history patient's auto-created empty rows push genuine findings
            // out of the result — hiding clinical data from the treating doctor.
            // hasRecordedTeeth() stays as a defensive post-filter for shapes SQL
            // cannot express portably.
            ->whereNotNull('trx_odontograms.tooth_map_payload')
            ->where('trx_odontograms.tooth_map_payload', '!=', '')
            ->orderByDesc('trx_clinic_visits.visit_date')
            ->orderByDesc('trx_clinic_visits.id')
            ->select('trx_odontograms.*')
            ->limit($limit);

        return $query->get();
    }
}
