<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Repositories;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeAmendment\Interfaces\RmeAmendmentRepositoryInterface;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Models\RmeRecordVersion;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Models\Permission;

class RmeAmendmentRepository implements RmeAmendmentRepositoryInterface
{
    public function create(array $data): RmeAmendment
    {
        // Always filed as pending; the status is not mass-assignable.
        $amendment = new RmeAmendment($data);
        $amendment->forceFill(['status' => RmeAmendment::STATUS_PENDING])->save();

        return $amendment;
    }

    public function find(int $id): ?RmeAmendment
    {
        return RmeAmendment::query()->find($id);
    }

    public function appliedCorrectionPageAmendments(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return RmeAmendment::query()
            ->with(['decider:id,name', 'clinicVisit'])
            ->whereKey($ids)
            ->where('status', RmeAmendment::STATUS_APPLIED)
            ->whereNotNull('correction_page_path')
            ->get()
            ->keyBy(fn (RmeAmendment $amendment): int => (int) $amendment->getKey());
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
            // The patient through the visit (a merge reassigns the visit).
            ->with(['branch:id,name', 'clinicVisit:id,patient_id', 'clinicVisit.patient:id,name,medical_record_number', 'requester:id,name'])
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

    public function applySheetDiagnoses(MedicalRecord $sheet, array $add, array $remove, int $diagnosedBy, int $createdBy): void
    {
        // Like the version writer: only inside the apply transaction, only on a final sheet.
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The diagnoses of an amendment are applied only inside the apply transaction.');
        }

        if ($sheet->status !== MedicalRecord::STATUS_FINAL) {
            throw new LogicException('An amendment is applied only to a final sheet.');
        }

        if ($remove !== []) {
            MedicalRecordDiagnosis::query()
                ->where('medical_record_id', (int) $sheet->getKey())
                ->whereKey($remove)
                ->get()
                ->each(fn (MedicalRecordDiagnosis $diagnosis) => $diagnosis->delete());
        }

        foreach ($add as $row) {
            MedicalRecordDiagnosis::create([
                'medical_record_id' => (int) $sheet->getKey(),
                'clinic_visit_id' => (int) $sheet->clinic_visit_id,
                'branch_id' => (int) $sheet->branch_id,
                'clinical_diagnosis_id' => (int) $row['clinical_diagnosis_id'],
                'diagnosis_role' => $row['diagnosis_role'],
                'diagnosed_by' => $diagnosedBy,
                'diagnosed_at' => now(),
                'notes' => $row['notes'],
                'created_by' => $createdBy,
            ]);
        }
    }

    public function satusehatCandidateForVisit(int $clinicVisitId): ?SatusehatCandidate
    {
        return SatusehatCandidate::query()->where('clinic_visit_id', $clinicVisitId)->first();
    }

    public function chartAmendmentCountsForPatientOfVisit(int $clinicVisitId): array
    {
        $chartIds = Odontogram::query()
            ->where('status', Odontogram::STATUS_FINALIZED)
            ->whereIn('clinic_visit_id', ClinicVisit::query()
                ->select('id')
                ->where('patient_id', ClinicVisit::query()->select('patient_id')->whereKey($clinicVisitId)))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($chartIds === []) {
            return [];
        }

        $counts = RmeRecordVersion::query()
            ->where('subject_type', RmeRecordVersion::SUBJECT_ODONTOGRAM)
            ->where('kind', RmeRecordVersion::KIND_AMENDMENT)
            ->whereIn('subject_id', $chartIds)
            ->selectRaw('subject_id, count(*) as aggregate')
            ->groupBy('subject_id')
            ->pluck('aggregate', 'subject_id')
            ->all();

        $out = [];
        foreach ($chartIds as $id) {
            $out[$id] = (int) ($counts[$id] ?? 0);
        }

        return $out;
    }

    public function versionSummaries(string $subjectType, int $subjectId): Collection
    {
        return RmeRecordVersion::query()
            ->select(['id', 'subject_type', 'subject_id', 'version', 'kind', 'created_by', 'created_at'])
            ->with('creator:id,name')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderBy('version')
            ->get();
    }

    public function approverCandidates(string $permission): Collection
    {
        if (! Permission::query()->where('name', $permission)->exists()) {
            return collect();
        }

        return User::permission($permission)->orderBy('id')->get();
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
