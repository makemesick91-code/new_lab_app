<?php

namespace App\Modules\ClinicVisit\Repositories;

use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ClinicVisitRepository implements ClinicVisitRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return ClinicVisit::query()
            ->with(['patient', 'doctor', 'clinicRoom'])
            ->where('branch_id', $branchId)
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['visit_date'] ?? null, fn ($q, $v) => $q->whereDate('visit_date', $v))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(visit_number) LIKE ?', [$term])
                        ->orWhereHas('patient', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->orderByDesc('visit_date')
            ->orderBy('queue_number')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateForBranches(array $branchIds, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return ClinicVisit::query()
            ->with(['patient', 'doctor', 'clinicRoom', 'branch'])
            ->whereIn('branch_id', $branchIds)
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['visit_date'] ?? null, fn ($q, $v) => $q->whereDate('visit_date', $v))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(visit_number) LIKE ?', [$term])
                        ->orWhereHas('patient', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->orderByDesc('visit_date')
            ->orderBy('queue_number')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function worklistForBranches(array $branchIds, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return ClinicVisit::query()
            ->with(['patient', 'doctor', 'clinicRoom', 'branch', 'medicalRecord'])
            ->whereIn('branch_id', $branchIds)
            ->whereNotNull('clinic_room_id')
            ->whereNotIn('status', [
                ClinicVisit::STATUS_CASHIER_PENDING,
                ClinicVisit::STATUS_COMPLETED,
                ClinicVisit::STATUS_CANCELLED,
            ])
            ->when($filters['clinic_room_id'] ?? null, fn ($q, $v) => $q->where('clinic_room_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(visit_number) LIKE ?', [$term])
                        ->orWhereHas('patient', function ($p) use ($term) {
                            $p->whereRaw('LOWER(name) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(medical_record_number) LIKE ?', [$term]);
                        });
                });
            })
            ->orderByDesc('visit_date')
            ->orderBy('clinic_room_id')
            ->orderBy('queue_number')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function queueForBranches(array $branchIds, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return ClinicVisit::query()
            ->with(['patient', 'doctor', 'clinicRoom', 'branch'])
            ->whereIn('branch_id', $branchIds)
            ->whereNotIn('status', [
                ClinicVisit::STATUS_CASHIER_PENDING,
                ClinicVisit::STATUS_COMPLETED,
                ClinicVisit::STATUS_CANCELLED,
            ])
            ->when(($filters['room_status'] ?? null) === 'unassigned', fn ($q) => $q->whereNull('clinic_room_id'))
            ->when(($filters['room_status'] ?? null) === 'assigned', fn ($q) => $q->whereNotNull('clinic_room_id'))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['visit_date'] ?? null, fn ($q, $v) => $q->whereDate('visit_date', $v))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(visit_number) LIKE ?', [$term])
                        ->orWhereHas('patient', function ($p) use ($term) {
                            $p->whereRaw('LOWER(name) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(medical_record_number) LIKE ?', [$term]);
                        });
                });
            })
            ->orderByDesc('visit_date')
            ->orderBy('queue_number')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findInBranch(int $branchId, int $id): ?ClinicVisit
    {
        return ClinicVisit::query()->where('branch_id', $branchId)->find($id);
    }

    public function nextQueueNumber(int $branchId, Carbon $visitDate): int
    {
        $lastQueueNumber = ClinicVisit::query()
            ->where('branch_id', $branchId)
            ->whereDate('visit_date', $visitDate->toDateString())
            ->orderByDesc('queue_number')
            ->lockForUpdate()
            ->value('queue_number');

        return ((int) $lastQueueNumber) + 1;
    }

    public function countTodayByBranch(int $branchId, string $date): int
    {
        return ClinicVisit::query()
            ->where('branch_id', $branchId)
            ->whereDate('visit_date', $date)
            ->count();
    }

    public function countByBranchStatus(int $branchId, string $status): int
    {
        return ClinicVisit::query()
            ->where('branch_id', $branchId)
            ->where('status', $status)
            ->count();
    }

    public function countTodayByBranches(array $branchIds, string $date): int
    {
        return ClinicVisit::query()
            ->whereIn('branch_id', $branchIds)
            ->whereDate('visit_date', $date)
            ->count();
    }

    public function countByBranchesStatus(array $branchIds, string $status): int
    {
        return ClinicVisit::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', $status)
            ->count();
    }

    public function create(array $data): ClinicVisit
    {
        return ClinicVisit::create($data);
    }

    public function update(ClinicVisit $visit, array $data): ClinicVisit
    {
        $visit->update($data);

        return $visit->refresh();
    }

    public function listForPatient(array $branchIds, int $patientId, ?int $excludeVisitId = null): Collection
    {
        return ClinicVisit::query()
            ->with(['doctor', 'initialTreatment'])
            ->whereIn('branch_id', $branchIds)
            ->where('patient_id', $patientId)
            ->when($excludeVisitId !== null, fn ($query) => $query->where('id', '!=', $excludeVisitId))
            ->orderByDesc('visit_date')
            ->orderByDesc('id')
            ->get();
    }

    public function findByIdInBranches(array $branchIds, int $id): ?ClinicVisit
    {
        return ClinicVisit::query()
            ->whereIn('branch_id', $branchIds)
            ->find($id);
    }
}
