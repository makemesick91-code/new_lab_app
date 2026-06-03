<?php

namespace App\Modules\Patient\Repositories;

use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PatientRepository implements PatientRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;
        $clinicId = $filters['clinic_id'] ?? null;
        $doctorId = $filters['doctor_id'] ?? null;

        return Patient::query()
            ->with(['clinic', 'doctor'])
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(medical_record_number) LIKE ?', [$term]);
                });
            })
            ->when($clinicId, fn ($query, $clinicId) => $query->where('clinic_id', $clinicId))
            ->when($doctorId, fn ($query, $doctorId) => $query->where('doctor_id', $doctorId))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findById(int $id): ?Patient
    {
        return Patient::with(['clinic', 'doctor'])->find($id);
    }

    public function create(array $data): Patient
    {
        return Patient::create($data);
    }

    public function update(Patient $patient, array $data): Patient
    {
        $patient->update($data);

        return $patient->refresh();
    }

    public function delete(Patient $patient): bool
    {
        return (bool) $patient->delete();
    }

    public function setActiveStatus(Patient $patient, bool $isActive): Patient
    {
        $patient->update(['is_active' => $isActive]);

        return $patient->refresh();
    }
}
