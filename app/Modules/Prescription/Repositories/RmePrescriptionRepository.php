<?php

namespace App\Modules\Prescription\Repositories;

use App\Modules\Prescription\Interfaces\RmePrescriptionRepositoryInterface;
use App\Modules\Prescription\Models\RmePrescription;
use Illuminate\Support\Collection;

class RmePrescriptionRepository implements RmePrescriptionRepositoryInterface
{
    public function findByClinicVisit(int $clinicVisitId): ?RmePrescription
    {
        return RmePrescription::query()
            ->where('clinic_visit_id', $clinicVisitId)
            ->first();
    }

    public function findInBranch(int $branchId, int $id): ?RmePrescription
    {
        return RmePrescription::query()
            ->where('branch_id', $branchId)
            ->whereKey($id)
            ->first();
    }

    public function historyForPatient(int $patientId, array $branchIds, ?int $excludeVisitId = null): Collection
    {
        return RmePrescription::query()
            ->with(['clinicVisit', 'doctor'])
            ->where('patient_id', $patientId)
            ->whereIn('branch_id', $branchIds)
            ->when($excludeVisitId !== null, fn ($q) => $q->where('clinic_visit_id', '!=', $excludeVisitId))
            ->orderByDesc('prescription_date')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    public function create(array $data): RmePrescription
    {
        return RmePrescription::create($data);
    }

    public function update(RmePrescription $prescription, array $data): RmePrescription
    {
        $prescription->update($data);

        return $prescription->refresh();
    }
}
