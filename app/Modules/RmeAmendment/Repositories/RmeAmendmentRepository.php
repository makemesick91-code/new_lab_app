<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Repositories;

use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeAmendment\Interfaces\RmeAmendmentRepositoryInterface;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Models\RmeRecordVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class RmeAmendmentRepository implements RmeAmendmentRepositoryInterface
{
    public function create(array $data): RmeAmendment
    {
        return RmeAmendment::query()->create($data);
    }

    public function find(int $id): ?RmeAmendment
    {
        return RmeAmendment::query()->find($id);
    }

    public function findForUpdate(int $id): ?RmeAmendment
    {
        return RmeAmendment::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function pendingFor(string $subjectType, int $subjectId): ?RmeAmendment
    {
        return RmeAmendment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('status', RmeAmendment::STATUS_PENDING)
            ->first();
    }

    public function recentFor(string $subjectType, int $subjectId, int $limit): Collection
    {
        return RmeAmendment::query()
            ->with(['requester:id,name', 'decider:id,name'])
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function transition(RmeAmendment $amendment, array $attributes): RmeAmendment
    {
        $amendment->forceFill($attributes)->save();

        return $amendment->refresh();
    }

    public function paginateQueue(array $branchIds, ?string $status, int $perPage): LengthAwarePaginator
    {
        return RmeAmendment::query()
            ->with(['branch:id,name', 'patient:id,name,medical_record_number', 'requester:id,name'])
            ->whereIn('branch_id', $branchIds)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            // Pending first (the work), then the newest.
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function statusCounts(array $branchIds): array
    {
        return RmeAmendment::query()
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public function findSubject(string $subjectType, int $subjectId): MedicalRecord|Odontogram|null
    {
        return match ($subjectType) {
            RmeRecordVersion::SUBJECT_MEDICAL_RECORD => MedicalRecord::query()->find($subjectId),
            RmeRecordVersion::SUBJECT_ODONTOGRAM => Odontogram::query()->find($subjectId),
            default => null,
        };
    }

    public function doctorUserId(?int $doctorId): ?int
    {
        if ($doctorId === null) {
            return null;
        }

        $userId = Doctor::query()->whereKey($doctorId)->value('user_id');

        return $userId !== null ? (int) $userId : null;
    }

    public function sheetDiagnoses(int $medicalRecordId): Collection
    {
        return MedicalRecordDiagnosis::query()
            ->where('medical_record_id', $medicalRecordId)
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    public function clinicalDiagnoses(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return ClinicalDiagnosis::query()->whereKey($ids)->get()->keyBy('id');
    }
}
