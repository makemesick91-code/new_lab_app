<?php

namespace App\Modules\MedicalRecord\Interfaces;

use App\Modules\MedicalRecord\Models\MedicalRecord;

interface MedicalRecordRepositoryInterface
{
    public function findByVisitId(int $clinicVisitId): ?MedicalRecord;

    /** @param array<string, mixed> $data */
    public function create(array $data): MedicalRecord;

    /** @param array<string, mixed> $data */
    public function update(MedicalRecord $medicalRecord, array $data): MedicalRecord;
}
