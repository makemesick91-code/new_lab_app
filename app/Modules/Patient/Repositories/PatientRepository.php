<?php

namespace App\Modules\Patient\Repositories;

use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
     * REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1 — see the interface for the
     * authorization contract. This method owns only the query.
     *
     * Only the four columns the selector renders are selected, so a widened
     * payload would have to be a deliberate act rather than an accident of
     * `select *`. Soft-deleted patients are excluded by the model's SoftDeletes.
     *
     * @param  array<int, int>  $branchIds
     * @param  Closure(Builder<Patient>): Builder<Patient>|null  $additionalScope
     * @return Collection<int, Patient>
     */
    public function searchSelectable(array $branchIds, string $term, int $limit, ?Closure $additionalScope = null): Collection
    {
        $term = trim($term);

        if ($branchIds === [] || $term === '' || $limit < 1) {
            return collect();
        }

        return $this->selectableQuery($branchIds, $additionalScope)
            ->where(function (Builder $query) use ($term): void {
                // Both sides are lowercased by the DATABASE. Lowercasing the
                // needle in PHP instead would apply PHP's Unicode rules to one
                // side and the database's collation to the other, so a non-ASCII
                // name could fail to match itself.
                $like = '%'.$this->escapeLike($term).'%';

                $query->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(medical_record_number) LIKE LOWER(?) ESCAPE '\\'", [$like]);
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<int, int>  $branchIds
     * @param  Closure(Builder<Patient>): Builder<Patient>|null  $additionalScope
     */
    public function findSelectable(array $branchIds, int $patientId, ?Closure $additionalScope = null): ?Patient
    {
        if ($branchIds === [] || $patientId <= 0) {
            return null;
        }

        return $this->selectableQuery($branchIds, $additionalScope)
            ->whereKey($patientId)
            ->first();
    }

    /**
     * The shared, authorized base query: patients of the given RME branches plus
     * legacy patients that never got a Cabang RME, narrowed further by the
     * caller's optional scope (doctor RM visibility).
     *
     * @param  array<int, int>  $branchIds
     * @param  Closure(Builder<Patient>): Builder<Patient>|null  $additionalScope
     * @return Builder<Patient>
     */
    private function selectableQuery(array $branchIds, ?Closure $additionalScope): Builder
    {
        $query = Patient::query()
            ->select(['id', 'name', 'medical_record_number', 'branch_id'])
            ->with('branch:id,code,name')
            ->where(function (Builder $scoped) use ($branchIds): void {
                $scoped->whereIn('branch_id', $branchIds)
                    ->orWhereNull('branch_id');
            });

        return $additionalScope === null ? $query : $additionalScope($query);
    }

    /**
     * Escape LIKE metacharacters so a typed `%` or `_` matches literally instead
     * of turning one keystroke into a full-table wildcard. The explicit ESCAPE
     * clause at the call site keeps this identical on PostgreSQL and SQLite.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
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
