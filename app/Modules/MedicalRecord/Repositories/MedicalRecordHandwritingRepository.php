<?php

namespace App\Modules\MedicalRecord\Repositories;

use App\Modules\MedicalRecord\Interfaces\MedicalRecordHandwritingRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;

class MedicalRecordHandwritingRepository implements MedicalRecordHandwritingRepositoryInterface
{
    public function findByMedicalRecordId(int $medicalRecordId): ?MedicalRecordHandwriting
    {
        return MedicalRecordHandwriting::query()
            ->where('medical_record_id', $medicalRecordId)
            ->latest()
            ->first();
    }

    public function create(array $data): MedicalRecordHandwriting
    {
        return MedicalRecordHandwriting::create($data);
    }

    public function update(MedicalRecordHandwriting $handwriting, array $data): MedicalRecordHandwriting
    {
        $handwriting->update($data);

        return $handwriting->refresh();
    }
}
