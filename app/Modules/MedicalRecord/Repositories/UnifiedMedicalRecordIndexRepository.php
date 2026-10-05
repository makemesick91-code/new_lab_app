<?php

declare(strict_types=1);

namespace App\Modules\MedicalRecord\Repositories;

use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\MedicalRecord\Interfaces\UnifiedMedicalRecordIndexRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Support\UnifiedMedicalRecordScope;
use App\Modules\MedicalRecord\Support\UnifiedMedicalRecordSource;
use App\Modules\Patient\Models\Patient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FEATURE-RME-MEDICAL-RECORDS-UNIFIED-NATIVE-LEGACY-1.
 *
 * One set-based query over `mst_patients`:
 *
 *     WHERE EXISTS(visible native record)
 *        OR EXISTS(visible PUBLISHED legacy RME)
 *        OR EXISTS(visible PUBLISHED legacy odontogram)
 *
 * Each EXISTS carries its own source's canonical branch envelope (see
 * {@see UnifiedMedicalRecordScope}); the doctor patient scope is applied once
 * to the patient query, which is exactly the predicate every one of the three
 * canonical read paths already applies (all three key on the patient).
 *
 * Pagination happens AFTER the union predicate, on one deterministic order, so
 * totals are honest and a patient can never appear twice. Native and legacy
 * storage stay separate — this only READS them.
 *
 * "Visible legacy" is the record status `PUBLISHED`. A VOID record is not
 * current evidence; a VOID record later replaced by a fresh PUBLISHED import
 * qualifies through the replacement. Staging rows (`stg_*`) are never read
 * here at all: an import that has not been published is not a clinical record.
 */
class UnifiedMedicalRecordIndexRepository implements UnifiedMedicalRecordIndexRepositoryInterface
{
    /** Sentinel used only to keep GREATEST()/MAX() a scalar with ≥2 arguments. */
    private const EPOCH = '1970-01-01 00:00:00';

    public function paginatePatients(UnifiedMedicalRecordScope $scope, array $filters, int $perPage): LengthAwarePaginatorContract
    {
        $source = UnifiedMedicalRecordSource::normalize($filters['source'] ?? null);
        $page = Paginator::resolveCurrentPage();

        // 1. Count over the eligibility predicate ONLY — no per-row projection,
        //    so PostgreSQL can hash every EXISTS leg.
        $counted = $this->eligiblePatients($scope, $filters);
        $this->applySourceFilter($counted, $scope, $source);
        $total = $counted->toBase()->count('mst_patients.id');

        // 2. The page of ids, ordered by ONE sort-key expression (latest record
        //    in scope across every readable source), id as the tie-break.
        $ordered = $this->eligiblePatients($scope, $filters);
        $this->applySourceFilter($ordered, $scope, $source);

        $sortKey = $this->lastActivityExpression($scope);

        $pageRows = DB::query()
            ->fromSub(
                $ordered->toBase()
                    ->select('mst_patients.id')
                    ->selectRaw($sortKey[0].' as last_activity_at', $sortKey[1]),
                'u'
            )
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        $ids = $pageRows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $activity = $pageRows->pluck('last_activity_at', 'id');

        // 3. Hydrate + indicators for THOSE ids only, then restore the order.
        $patients = $ids === [] ? collect() : $this->withIndicators(Patient::query(), $scope)
            ->whereIn('mst_patients.id', $ids)
            ->with('branch')
            ->get()
            ->each(fn (Patient $patient) => $patient->setAttribute('last_activity_at', $activity[$patient->id] ?? null))
            ->sortBy(fn (Patient $patient) => array_search((int) $patient->id, $ids, true))
            ->values();

        return (new LengthAwarePaginator($patients, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]))->withQueryString();
    }

    public function summarize(UnifiedMedicalRecordScope $scope, array $filters): array
    {
        // Three hashed counts over the same filtered eligibility. Because the
        // eligible set is exactly native ∪ legacy under these filters, the
        // overlap follows by inclusion-exclusion — no fourth scan.
        $total = $this->eligiblePatients($scope, $filters)->toBase()->count('mst_patients.id');

        $native = $this->eligiblePatients($scope, $filters)
            ->whereExists($this->nativeExists($scope))
            ->toBase()->count('mst_patients.id');

        $legacy = 0;

        if ($scope->anyLegacyReadable()) {
            $legacyQuery = $this->eligiblePatients($scope, $filters);
            $this->requireLegacy($legacyQuery, $scope);
            $legacy = $legacyQuery->toBase()->count('mst_patients.id');
        }

        return [
            'total' => $total,
            'native' => $native,
            'legacy' => $legacy,
            'native_and_legacy' => max(0, $native + $legacy - $total),
        ];
    }

    public function findVisiblePatient(UnifiedMedicalRecordScope $scope, int $patientId): ?Patient
    {
        $query = $this->eligiblePatients($scope, []);

        return $query->where('mst_patients.id', $patientId)->with('branch')->first();
    }

    public function latestNativeRecordsFor(UnifiedMedicalRecordScope $scope, array $patientIds): Collection
    {
        if ($patientIds === [] || $scope->nativeBranchIds === []) {
            return collect();
        }

        return MedicalRecord::query()
            ->with(['clinicVisit.clinicRoom', 'doctor'])
            ->whereIn('patient_id', $patientIds)
            ->whereIn('branch_id', $scope->nativeBranchIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique('patient_id')
            ->keyBy('patient_id');
    }

    public function nativeRecordsForPatient(UnifiedMedicalRecordScope $scope, int $patientId): Collection
    {
        if ($scope->nativeBranchIds === []) {
            return collect();
        }

        return MedicalRecord::query()
            ->with(['clinicVisit.clinicRoom', 'doctor', 'branch'])
            ->where('patient_id', $patientId)
            ->whereIn('branch_id', $scope->nativeBranchIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The unified eligibility predicate plus search and the native-only
     * filters, before any source filter or projection.
     *
     * @return EloquentBuilder<Patient>
     */
    private function eligiblePatients(UnifiedMedicalRecordScope $scope, array $filters): EloquentBuilder
    {
        $query = Patient::query();

        if ($scope->patientScope !== null) {
            $query = ($scope->patientScope)($query);
        }

        $query->where(function (EloquentBuilder $q) use ($scope): void {
            $q->whereExists($this->nativeExists($scope));

            if ($scope->legacyRmeVisible()) {
                $q->orWhereExists($this->legacyRmeExists($scope));
            }

            if ($scope->legacyOdontogramVisible()) {
                $q->orWhereExists($this->legacyOdontogramExists($scope));
            }
        });

        // Status and visit-date filters describe NATIVE records (legacy archives
        // have no draft/final workflow and no visit), so setting either one
        // narrows the set to patients holding a matching native record.
        $status = $filters['status'] ?? null;
        $from = $filters['visit_date_from'] ?? null;
        $to = $filters['visit_date_to'] ?? null;

        if ($status || $from || $to) {
            $query->whereExists($this->nativeExists($scope, $status, $from, $to));
        }

        $search = $filters['search'] ?? null;

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.mb_strtolower(trim($search)).'%';

            $query->where(function (EloquentBuilder $q) use ($scope, $term): void {
                $q->whereRaw('LOWER(mst_patients.name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(mst_patients.medical_record_number) LIKE ?', [$term])
                    ->orWhereExists(
                        $this->nativeExists($scope)
                            ->join('trx_clinic_visits as sv', 'sv.id', '=', 'mr.clinic_visit_id')
                            ->whereRaw('LOWER(sv.visit_number) LIKE ?', [$term])
                    )
                    ->orWhereExists(
                        $this->nativeExists($scope)
                            ->join('mst_doctors as sd', 'sd.id', '=', 'mr.doctor_id')
                            ->whereRaw('LOWER(sd.name) LIKE ?', [$term])
                    );
            });
        }

        return $query;
    }

    private function applySourceFilter(EloquentBuilder $query, UnifiedMedicalRecordScope $scope, string $source): void
    {
        match ($source) {
            UnifiedMedicalRecordSource::NATIVE => $query->whereExists($this->nativeExists($scope)),
            UnifiedMedicalRecordSource::LEGACY => $this->requireLegacy($query, $scope),
            UnifiedMedicalRecordSource::NATIVE_AND_LEGACY => $this->requireLegacy($query->whereExists($this->nativeExists($scope)), $scope),
            UnifiedMedicalRecordSource::LEGACY_RME => $scope->legacyRmeVisible()
                ? $query->whereExists($this->legacyRmeExists($scope))
                : $query->whereRaw('1 = 0'),
            UnifiedMedicalRecordSource::LEGACY_ODONTOGRAM => $scope->legacyOdontogramVisible()
                ? $query->whereExists($this->legacyOdontogramExists($scope))
                : $query->whereRaw('1 = 0'),
            default => null,
        };
    }

    private function requireLegacy(EloquentBuilder $query, UnifiedMedicalRecordScope $scope): void
    {
        if (! $scope->anyLegacyReadable()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (EloquentBuilder $q) use ($scope): void {
            $this->whereAnyLegacy($q->getQuery(), $scope);
        });
    }

    private function whereAnyLegacy(QueryBuilder $q, UnifiedMedicalRecordScope $scope): void
    {
        if ($scope->legacyRmeVisible()) {
            $q->orWhereExists($this->legacyRmeExists($scope));
        }

        if ($scope->legacyOdontogramVisible()) {
            $q->orWhereExists($this->legacyOdontogramExists($scope));
        }
    }

    /**
     * Per-row indicator counts. Applied ONLY to the ids of one page, never to
     * the whole eligible set.
     *
     * @param  EloquentBuilder<Patient>  $query
     * @return EloquentBuilder<Patient>
     */
    private function withIndicators(EloquentBuilder $query, UnifiedMedicalRecordScope $scope): EloquentBuilder
    {
        $query->select('mst_patients.*')
            ->selectSub($this->nativeExists($scope)->selectRaw('COUNT(*)'), 'native_count');

        $scope->legacyRmeVisible()
            ? $query->selectSub($this->legacyRmeExists($scope)->selectRaw('COUNT(*)'), 'legacy_rme_count')
            : $query->selectRaw('0 as legacy_rme_count');

        $scope->legacyOdontogramVisible()
            ? $query->selectSub($this->legacyOdontogramExists($scope)->selectRaw('COUNT(*)'), 'legacy_odontogram_count')
            : $query->selectRaw('0 as legacy_odontogram_count');

        return $query;
    }

    /**
     * The sort key: the latest record in scope across every readable source,
     * as [sql, bindings].
     *
     * GREATEST on PostgreSQL, scalar MAX on SQLite. Both carry the epoch
     * sentinel as an extra argument: GREATEST ignores NULLs but SQLite's MAX
     * would return NULL, and a single-argument MAX on SQLite is an AGGREGATE.
     * Only readable sources contribute, so an unreadable archive can never
     * reorder — and therefore never hint at — a patient's legacy history.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function lastActivityExpression(UnifiedMedicalRecordScope $scope): array
    {
        $subqueries = [$this->nativeExists($scope)->selectRaw('MAX(mr.created_at)')];

        if ($scope->legacyRmeVisible()) {
            $subqueries[] = $this->legacyRmeExists($scope)->selectRaw('MAX(lr.created_at)');
        }

        if ($scope->legacyOdontogramVisible()) {
            $subqueries[] = $this->legacyOdontogramExists($scope)->selectRaw('MAX(lo.created_at)');
        }

        $parts = ['?'];
        $bindings = [self::EPOCH];

        foreach ($subqueries as $sub) {
            $parts[] = 'COALESCE(('.$sub->toSql().'), ?)';
            array_push($bindings, ...$sub->getBindings());
            $bindings[] = self::EPOCH;
        }

        $function = DB::connection()->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';

        return [$function.'('.implode(', ', $parts).')', $bindings];
    }

    private function nativeExists(
        UnifiedMedicalRecordScope $scope,
        ?string $status = null,
        ?string $from = null,
        ?string $to = null,
    ): QueryBuilder {
        $query = DB::table('trx_medical_records as mr')
            ->whereColumn('mr.patient_id', 'mst_patients.id')
            ->whereNull('mr.deleted_at');

        if ($scope->nativeBranchIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereIn('mr.branch_id', $scope->nativeBranchIds);

        if ($status) {
            $query->where('mr.status', $status);
        }

        if ($from || $to) {
            $query->whereExists(function (QueryBuilder $v) use ($from, $to): void {
                $v->selectRaw('1')
                    ->from('trx_clinic_visits as fv')
                    ->whereColumn('fv.id', 'mr.clinic_visit_id')
                    ->when($from, fn (QueryBuilder $q) => $q->whereDate('fv.visit_date', '>=', $from))
                    ->when($to, fn (QueryBuilder $q) => $q->whereDate('fv.visit_date', '<=', $to));
            });
        }

        return $query;
    }

    private function legacyRmeExists(UnifiedMedicalRecordScope $scope): QueryBuilder
    {
        return $this->legacyExists(
            (new LegacyRmeRecord)->getTable(),
            'lr',
            'origin_branch_id',
            LegacyRmeRecord::STATUS_PUBLISHED,
            $scope->legacyRmeBranchIds,
            $scope->legacyRmeIncludesUnscoped,
        );
    }

    private function legacyOdontogramExists(UnifiedMedicalRecordScope $scope): QueryBuilder
    {
        return $this->legacyExists(
            (new LegacyOdontogramRecord)->getTable(),
            'lo',
            'branch_id',
            LegacyOdontogramRecord::STATUS_PUBLISHED,
            $scope->legacyOdontogramBranchIds,
            $scope->legacyOdontogramIncludesUnscoped,
        );
    }

    /**
     * Mirrors the `scoped()` predicate of each legacy record repository:
     * branch IN the actor's set, plus NULL-branch rows only for governance.
     * An empty set matches nothing, exactly as scoped() does.
     *
     * @param  list<int>  $branchIds
     */
    private function legacyExists(
        string $table,
        string $alias,
        string $branchColumn,
        string $publishedStatus,
        array $branchIds,
        bool $includeUnscoped,
    ): QueryBuilder {
        $query = DB::table($table.' as '.$alias)
            ->whereColumn($alias.'.patient_id', 'mst_patients.id')
            ->where($alias.'.status', $publishedStatus);

        if ($branchIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (QueryBuilder $q) use ($alias, $branchColumn, $branchIds, $includeUnscoped): void {
            $q->whereIn($alias.'.'.$branchColumn, $branchIds);

            if ($includeUnscoped) {
                $q->orWhereNull($alias.'.'.$branchColumn);
            }
        });
    }
}
