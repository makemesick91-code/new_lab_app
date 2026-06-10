<?php

namespace App\Modules\MedicalRecord\Interfaces;

use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;

interface MedicalRecordHandwritingRepositoryInterface
{
    public function findByMedicalRecordId(int $medicalRecordId): ?MedicalRecordHandwriting;

    /** @param array<string, mixed> $data */
    public function create(array $data): MedicalRecordHandwriting;

    /** @param array<string, mixed> $data */
    public function update(MedicalRecordHandwriting $handwriting, array $data): MedicalRecordHandwriting;
}
