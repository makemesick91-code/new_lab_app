<?php

namespace App\Modules\Prescription\Interfaces;

use App\Modules\Prescription\Models\RmePrescription;
use Illuminate\Support\Collection;

interface RmePrescriptionRepositoryInterface
{
    public function findByClinicVisit(int $clinicVisitId): ?RmePrescription;

    public function findInBranch(int $branchId, int $id): ?RmePrescription;

    /**
     * @param  array<int>  $branchIds
     * @return Collection<int, RmePrescription>
     */
    public function historyForPatient(int $patientId, array $branchIds, ?int $excludeVisitId = null): Collection;

    public function create(array $data): RmePrescription;

    public function update(RmePrescription $prescription, array $data): RmePrescription;
}
