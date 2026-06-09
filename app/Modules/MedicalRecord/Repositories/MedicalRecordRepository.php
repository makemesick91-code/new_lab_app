<?php

namespace App\Modules\MedicalRecord\Repositories;

use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;

class MedicalRecordRepository implements MedicalRecordRepositoryInterface
{
    public function findByVisitId(int $clinicVisitId): ?MedicalRecord
    {
        return MedicalRecord::query()->where('clinic_visit_id', $clinicVisitId)->first();
    }

    public function create(array $data): MedicalRecord
    {
        return MedicalRecord::create($data);
    }

    public function update(MedicalRecord $medicalRecord, array $data): MedicalRecord
    {
        $medicalRecord->update($data);

        return $medicalRecord->refresh();
    }
}
