<?php

namespace App\Modules\Odontogram\Interfaces;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use Closure;
use Illuminate\Support\Collection;

interface OdontogramRepositoryInterface
{
    public function findByClinicVisit(int $clinicVisitId): ?Odontogram;

    /**
     * POST-RME-ODONTOGRAM-STABILIZATION-1 / FIX-01 — the same lookup under a
     * row lock, for the create-or-update decision in a first save.
     *
     * MUST be called inside a transaction. Creation moved from the page GET to
     * the Save button, so two concurrent saves on the same uncharted visit are
     * now realistic (a double-click, or a doctor and a nurse on one live
     * encounter). Without the lock the loser of that race loses its payload to
     * the UNIQUE on clinic_visit_id.
     */
    public function findByClinicVisitForUpdate(int $clinicVisitId): ?Odontogram;

    public function createForClinicVisit(ClinicVisit $clinicVisit, array $data = []): Odontogram;

    public function updatePlaceholder(Odontogram $odontogram, array $data): Odontogram;

    public function finalize(Odontogram $odontogram, array $data): Odontogram;

    /**
     * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-04 — the patient's PREVIOUS
     * odontograms, for the read-only history section.
     *
     * Branch scoping is a required parameter rather than something the
     * implementation looks up, so a caller cannot forget it: this is the first
     * patient-wide (rather than single-visit) query in this module, and every
     * other branch guard in the module lives in the service or the policy.
     *
     * @param  array<int, int>  $branchIds  the active RME branch set
     * @param  Closure|null  $scope  extra query scope, e.g. the doctor's clinical patient scope
     * @return Collection<int, Odontogram>
     */
    public function patientHistoryForBranches(
        array $branchIds,
        int $patientId,
        ?int $excludeVisitId = null,
        ?Closure $scope = null,
        int $limit = 50,
    ): Collection;
}
