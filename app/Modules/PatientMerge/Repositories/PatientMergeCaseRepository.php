<?php

namespace App\Modules\PatientMerge\Repositories;

use App\Modules\PatientMerge\Interfaces\PatientMergeCaseRepositoryInterface;
use App\Modules\PatientMerge\Models\PatientMergeCase;
use App\Modules\PatientMerge\Models\PatientMergeFieldResolution;
use App\Modules\PatientMerge\Support\PatientMergeStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PatientMergeCaseRepository implements PatientMergeCaseRepositoryInterface
{
    public function create(array $attributes): PatientMergeCase
    {
        $case = new PatientMergeCase;
        $case->forceFill($attributes)->save();

        return $case;
    }

    public function findByUuid(string $uuid): ?PatientMergeCase
    {
        return PatientMergeCase::query()->where('uuid', $uuid)->first();
    }

    public function lockForUpdate(int $id): ?PatientMergeCase
    {
        return PatientMergeCase::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function write(PatientMergeCase $case, array $attributes): PatientMergeCase
    {
        $case->forceFill($attributes)->save();

        return $case;
    }

    public function openCaseInvolving(array $patientIds, ?int $exceptCaseId = null): ?PatientMergeCase
    {
        $patientIds = array_values(array_filter(array_map('intval', $patientIds)));

        if ($patientIds === []) {
            return null;
        }

        return PatientMergeCase::query()
            ->whereIn('status', PatientMergeStatus::OPEN)
            ->where(function (Builder $q) use ($patientIds): void {
                $q->whereIn('patient_a_id', $patientIds)->orWhereIn('patient_b_id', $patientIds);
            })
            ->when($exceptCaseId !== null, fn (Builder $q) => $q->whereKeyNot($exceptCaseId))
            ->orderBy('id')
            ->first();
    }

    public function paginateScoped(array $branchIds, array $statuses, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->scoped($branchIds)
            ->with(['patientA:id,name,medical_record_number,branch_id', 'patientB:id,name,medical_record_number,branch_id',
                'canonicalPatient:id,name,medical_record_number', 'requester:id,name', 'reviewer:id,name'])
            ->whereIn('status', $statuses);

        if (! empty($filters['status']) && in_array($filters['status'], $statuses, true)) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['requested_by'])) {
            $query->where('requested_by', (int) $filters['requested_by']);
        }

        if (! empty($filters['reviewed_by'])) {
            $query->where('reviewed_by', (int) $filters['reviewed_by']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from'].' 00:00:00');
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'].' 23:59:59');
        }

        if (! empty($filters['risk'])) {
            $query->where('risk_level', $filters['risk']);
        }

        if (! empty($filters['rm'])) {
            $rm = mb_strtolower(trim((string) $filters['rm']));
            $query->where(function (Builder $q) use ($rm): void {
                foreach (['patientA', 'patientB', 'canonicalPatient'] as $relation) {
                    $q->orWhereHas($relation, fn (Builder $p) => $p->whereRaw('LOWER(medical_record_number) = ?', [$rm]));
                }
            });
        }

        return $query->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    public function statusCounts(array $branchIds): array
    {
        $counts = $this->scoped($branchIds)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($value): int => (int) $value)
            ->all();

        return array_merge(array_fill_keys(PatientMergeStatus::ALL, 0), $counts);
    }

    public function completedPerMonth(array $branchIds, int $months): array
    {
        $start = now()->startOfMonth()->subMonths(max(0, $months - 1));
        $buckets = [];

        for ($i = 0; $i < $months; $i++) {
            $buckets[$start->copy()->addMonths($i)->format('Y-m')] = 0;
        }

        // Bucketed in PHP so the query stays portable across PostgreSQL and
        // SQLite; the row set is bounded by the window.
        $this->scoped($branchIds)
            ->whereNotNull('merged_at')
            ->where('merged_at', '>=', $start)
            ->pluck('merged_at')
            ->each(function ($mergedAt) use (&$buckets): void {
                $key = $mergedAt?->format('Y-m');

                if ($key !== null && array_key_exists($key, $buckets)) {
                    $buckets[$key]++;
                }
            });

        return $buckets;
    }

    public function countHighRiskOpen(array $branchIds): int
    {
        return $this->scoped($branchIds)
            ->whereIn('status', PatientMergeStatus::OPEN)
            ->where('risk_level', 'high')
            ->count();
    }

    public function nextCaseNumber(int $year): string
    {
        $prefix = 'PMC-'.$year.'-';
        $last = PatientMergeCase::query()
            ->where('case_number', 'like', $prefix.'%')
            ->orderByDesc('case_number')
            ->value('case_number');

        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    public function openPairKeys(): array
    {
        return PatientMergeCase::query()
            ->whereIn('status', PatientMergeStatus::OPEN)
            ->get(['uuid', 'patient_a_id', 'patient_b_id'])
            ->mapWithKeys(fn (PatientMergeCase $case): array => [
                min($case->patient_a_id, $case->patient_b_id).'-'.max($case->patient_a_id, $case->patient_b_id) => $case->uuid,
            ])
            ->all();
    }

    public function replaceFieldResolutions(PatientMergeCase $case, array $rows): void
    {
        foreach ($rows as $row) {
            PatientMergeFieldResolution::query()->updateOrCreate(
                ['merge_case_id' => $case->id, 'field' => $row['field']],
                $row,
            );
        }
    }

    /**
     * Both patients inside the branch scope. An empty scope sees nothing —
     * a user with no working branch must not see every case.
     *
     * @param  array<int, int>  $branchIds
     * @return Builder<PatientMergeCase>
     */
    private function scoped(array $branchIds): Builder
    {
        $query = PatientMergeCase::query();

        if ($branchIds === []) {
            return $query->whereRaw('1 = 0');
        }

        foreach (['patientA', 'patientB'] as $relation) {
            $query->whereHas($relation, function (Builder $patient) use ($branchIds): void {
                $patient->where(function (Builder $q) use ($branchIds): void {
                    $q->whereIn('branch_id', $branchIds)->orWhereNull('branch_id');
                });
            });
        }

        return $query;
    }
}
