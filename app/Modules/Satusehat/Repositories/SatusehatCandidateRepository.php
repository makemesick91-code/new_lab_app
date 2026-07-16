<?php

namespace App\Modules\Satusehat\Repositories;

use App\Modules\Satusehat\Interfaces\SatusehatCandidateRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SatusehatCandidateRepository implements SatusehatCandidateRepositoryInterface
{
    public function paginate(array $filters, array $branchIds, int $perPage = 20): LengthAwarePaginator
    {
        return $this->scoped($branchIds)
            ->with([
                'clinicVisit:id,visit_number,visit_date,status,doctor_id,branch_id',
                // NIK deliberately NOT hydrated into the listing — the index view
                // never renders it (name/MRN only). The masked show view loads it separately.
                'patient:id,name,medical_record_number',
                'doctor:id,name',
                'medicalRecord:id,status,finalized_at',
            ])
            ->when($this->str($filters, 'readiness_status'), fn (Builder $q, $v) => $q->where('readiness_status', $v))
            ->when($this->str($filters, 'review_status'), fn (Builder $q, $v) => $q->where('review_status', $v))
            ->when($this->str($filters, 'dental_readiness_status'), fn (Builder $q, $v) => $q->where('dental_readiness_status', $v))
            ->when($this->int($filters, 'doctor_id'), fn (Builder $q, $v) => $q->where('doctor_id', $v))
            ->when($this->int($filters, 'branch_id'), fn (Builder $q, $v) => $q->where('branch_id', $v))
            ->when($this->str($filters, 'search'), function (Builder $q, $v) {
                $q->whereHas('patient', function (Builder $p) use ($v) {
                    $p->where('name', 'like', "%{$v}%")
                        ->orWhere('medical_record_number', 'like', "%{$v}%");
                });
            })
            ->when($this->str($filters, 'visit_date_from'), function (Builder $q, $v) {
                $q->whereHas('clinicVisit', fn (Builder $cv) => $cv->whereDate('visit_date', '>=', $v));
            })
            ->when($this->str($filters, 'visit_date_to'), function (Builder $q, $v) {
                $q->whereHas('clinicVisit', fn (Builder $cv) => $cv->whereDate('visit_date', '<=', $v));
            })
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findInBranches(int $id, array $branchIds): ?SatusehatCandidate
    {
        return $this->scoped($branchIds)
            ->with([
                'clinicVisit', 'patient', 'doctor', 'medicalRecord',
                'reviewedBy:id,name', 'approvedBy:id,name', 'excludedBy:id,name',
            ])
            ->find($id);
    }

    public function idsInBranches(array $ids, array $branchIds): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return collect();
        }

        return $this->scoped($branchIds)
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * @param  list<int>  $branchIds
     * @return Builder<SatusehatCandidate>
     */
    private function scoped(array $branchIds): Builder
    {
        // An empty allowed-branch set matches nothing (fail-closed).
        return SatusehatCandidate::query()
            ->when(true, fn (Builder $q) => $branchIds === []
                ? $q->whereRaw('1 = 0')
                : $q->whereIn('branch_id', $branchIds));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function str(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function int(array $filters, string $key): ?int
    {
        $value = $filters[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
