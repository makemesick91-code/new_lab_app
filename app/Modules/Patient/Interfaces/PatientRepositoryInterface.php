<?php

namespace App\Modules\Patient\Interfaces;

use App\Modules\Patient\Models\Patient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PatientRepositoryInterface
{
    /**
     * @param  array{search?: string|null, clinic_id?: int|null, doctor_id?: int|null}  $filters
     */
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function findById(int $id): ?Patient;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Patient;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Patient $patient, array $data): Patient;

    public function delete(Patient $patient): bool;

    public function setActiveStatus(Patient $patient, bool $isActive): Patient;
}
