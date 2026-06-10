<?php

namespace App\Modules\MedicalRecord\Repositories;

use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MedicalRecordRepository implements MedicalRecordRepositoryInterface
{
    public function paginateForBranch(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return MedicalRecord::query()
            ->with(['clinicVisit', 'patient', 'doctor', 'recordedBy'])
            ->where('branch_id', $branchId)
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['visit_date_from'] ?? null, fn ($q, $v) => $q->whereHas('clinicVisit', fn ($q) => $q->whereDate('visit_date', '>=', $v)))
            ->when($filters['visit_date_to'] ?? null, fn ($q, $v) => $q->whereHas('clinicVisit', fn ($q) => $q->whereDate('visit_date', '<=', $v)))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereHas('patient', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('doctor', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('clinicVisit', fn ($q) => $q->whereRaw('LOWER(visit_number) LIKE ?', [$term]));
                });
            })
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findByVisitId(int $clinicVisitId): ?MedicalRecord
    {
        return MedicalRecord::query()->where('clinic_visit_id', $clinicVisitId)->first();
    }

    public function countByBranchStatus(int $branchId, string $status): int
    {
        return MedicalRecord::query()
            ->where('branch_id', $branchId)
            ->where('status', $status)
            ->count();
    }

    public function countFinalizedTodayByBranch(int $branchId, string $date): int
    {
        return MedicalRecord::query()
            ->where('branch_id', $branchId)
            ->where('status', MedicalRecord::STATUS_FINAL)
            ->whereDate('finalized_at', $date)
            ->count();
    }

    public function create(array $data): MedicalRecord
    {
        return MedicalRecord::create($data);
    }

    public function update(MedicalRecord $medicalRecord, array $data): MedicalRecord
    {
        $medicalRecord->update($data);

        return $medicalRecord->refresh();
    }
}
