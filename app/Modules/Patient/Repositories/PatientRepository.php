<?php

namespace App\Modules\Patient\Repositories;

use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PatientRepository implements PatientRepositoryInterface
{
    public function __construct(
        private readonly PatientMedicalRecordNumberService $medicalRecordNumbers,
    ) {}

    /**
     * BUGFIX-NEW-VISIT-PATIENT-SEARCH-RUNTIME-1 — the LIKE escape character.
     *
     * DELIBERATELY NOT A BACKSLASH, and that is the entire point of naming it.
     *
     * `ESCAPE '\'` is valid SQL and both PostgreSQL and SQLite accept it, but
     * PDO must rewrite `?` into `$n` before pdo_pgsql can send the statement,
     * and its parser (up to and including PHP 8.3, which serves production)
     * treats a backslash inside a single-quoted literal as escaping the closing
     * quote. The literal then swallows the placeholders that follow, PDO counts
     * fewer `?` than there are bindings, and every search 500s with
     * `SQLSTATE[HY093]: Invalid parameter number`.
     *
     * That fault is invisible to SQLite — which accepts positional `?` natively
     * and so never runs the rewriting parser — which is exactly why a fully
     * tested control still shipped broken. Choosing a character that can never
     * terminate a string literal removes the hazard rather than dodging it.
     *
     * Any single character works as long as it is neither a backslash nor a
     * quote; `!` is conventional and is itself escaped below when a patient's
     * name legitimately contains one.
     */
    private const LIKE_ESCAPE = '!';

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
                // REVISION-TELKOMAS-BRANCH-CODE-TKM1-TO-TLK1-1 — a full Nomor RM
                // typed from an old card carries the branch code it was ISSUED
                // under, which may since have been revised. Each equivalent
                // spelling is searched so the patient is still found; a term that
                // is not a canonical Nomor RM yields itself alone, so ordinary
                // name and partial searches behave exactly as before.
                $rmTerms = array_map(
                    static fn (string $value): string => '%'.mb_strtolower($value).'%',
                    $this->medicalRecordNumbers->equivalentNumbers($search),
                );

                $query->where(function ($q) use ($term, $rmTerms) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term]);

                    foreach ($rmTerms as $rmTerm) {
                        $q->orWhereRaw('LOWER(medical_record_number) LIKE ?', [$rmTerm]);
                    }
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

                // The escape character is interpolated from a private class
                // constant, never from input; the column names are literals.
                // See LIKE_ESCAPE for why it must not be a backslash.
                $escape = " ESCAPE '".self::LIKE_ESCAPE."'";

                $query->whereRaw('LOWER(name) LIKE LOWER(?)'.$escape, [$like]);

                // REVISION-TELKOMAS-BRANCH-CODE-TKM1-TO-TLK1-1 — see the note in
                // search(): every equivalent spelling of the same Nomor RM is
                // matched, each escaped through the same helper as the name term.
                foreach ($this->medicalRecordNumbers->equivalentNumbers($term) as $variant) {
                    $query->orWhereRaw(
                        'LOWER(medical_record_number) LIKE LOWER(?)'.$escape,
                        ['%'.$this->escapeLike($variant).'%'],
                    );
                }
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
     *
     * The escape character escapes ITSELF first, so a patient whose name really
     * contains one is still matched literally. Both this helper and the clause
     * read {@see LIKE_ESCAPE}, so they cannot drift apart.
     */
    private function escapeLike(string $value): string
    {
        $escape = self::LIKE_ESCAPE;

        return str_replace(
            [$escape, '%', '_'],
            [$escape.$escape, $escape.'%', $escape.'_'],
            $value,
        );
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
