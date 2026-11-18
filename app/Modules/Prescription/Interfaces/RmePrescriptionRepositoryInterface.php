<?php

namespace App\Modules\Prescription\Interfaces;

use App\Modules\Prescription\Models\RmePrescription;
use Closure;
use Illuminate\Support\Collection;

interface RmePrescriptionRepositoryInterface
{
    public function findByClinicVisit(int $clinicVisitId): ?RmePrescription;

    public function findInBranch(int $branchId, int $id): ?RmePrescription;

    /**
     * Rule 243 §2.11 (PR A3b) — `$visitScope`, when given, constrains the
     * prescription's visit (`whereHas('clinicVisit')`) before the row limit.
     *
     * @param  array<int>  $branchIds
     * @return Collection<int, RmePrescription>
     */
    public function historyForPatient(int $patientId, array $branchIds, ?int $excludeVisitId = null, ?Closure $visitScope = null): Collection;

    public function create(array $data): RmePrescription;

    public function update(RmePrescription $prescription, array $data): RmePrescription;
}
