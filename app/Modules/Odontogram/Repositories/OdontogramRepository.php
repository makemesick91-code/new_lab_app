<?php

namespace App\Modules\Odontogram\Repositories;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Interfaces\OdontogramRepositoryInterface;
use App\Modules\Odontogram\Models\Odontogram;

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
}
