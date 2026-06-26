<?php

namespace App\Modules\Patient\Repositories;

use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PatientRepository implements PatientRepositoryInterface
{
    public function listAll(): Collection
    {
        return Patient::query()->where('is_active', true)->orderBy('name')->get();
    }

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

    /**
     * Read-only legacy preview: patients without a Cabang RME (branch_id null).
     * Non-mutating — no automatic backfill is performed (Sprint 23 Phase 23.10).
     * Legacy clinic_id (if any) is eager-loaded for context only.
     *
     * @return Collection<int, Patient>
     */
    public function legacyWithoutBranch(): Collection
    {
        return Patient::query()
            ->with('clinic')
            ->whereNull('branch_id')
            ->orderBy('name')
            ->get();
    }

    /**
     * Read-only audit scope: branch + active-status only. Ordered newest-first by
     * id so the downstream service can apply display filters / sorting in PHP.
     *
     * @param  array{branch_id?: int|null, is_active?: bool|null}  $filters
     * @return Collection<int, Patient>
     */
    public function forAudit(array $filters = []): Collection
    {
        return Patient::query()
            ->with('branch:id,code,name,is_rme_enabled')
            ->when(($filters['branch_id'] ?? null) !== null, fn ($query) => $query->where('branch_id', $filters['branch_id']))
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null, fn ($query) => $query->where('is_active', $filters['is_active']))
            ->orderByDesc('id')
            ->get();
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
