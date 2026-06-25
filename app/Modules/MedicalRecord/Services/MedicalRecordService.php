<?php

namespace App\Modules\MedicalRecord\Services;

use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MedicalRecordService
{
    public function __construct(
        private readonly MedicalRecordRepositoryInterface $medicalRecords,
        private readonly BranchService $branches,
        private readonly ClinicVisitService $visitService,
    ) {}

    /**
     * Whether a branch belongs to the operational "Cabang RME" set (active
     * RME-enabled branches). Replaces the single BranchContext/MAIN fallback so
     * the doctor RME workflow works for any RME-branch visit (Sprint 23 Phase 23.10).
     */
    private function isActiveRmeBranch(?int $branchId): bool
    {
        return $branchId !== null && in_array($branchId, $this->branches->rmeEnabledIds(), true);
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->medicalRecords->paginateForBranches(
            $this->branches->rmeEnabledIds(),
            $filters,
            $perPage,
        );
    }

    public function draftCount(): int
    {
        return $this->medicalRecords->countByBranchesStatus(
            $this->branches->rmeEnabledIds(),
            MedicalRecord::STATUS_DRAFT,
        );
    }

    public function finalizedTodayCount(): int
    {
        return $this->medicalRecords->countFinalizedTodayByBranches(
            $this->branches->rmeEnabledIds(),
            now()->toDateString(),
        );
    }

    public function createDraft(ClinicVisit $clinicVisit, ?int $recordedBy = null, array $data = []): MedicalRecord
    {
        return DB::transaction(function () use ($clinicVisit, $recordedBy, $data) {
            if (! $this->isActiveRmeBranch($clinicVisit->branch_id)) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => 'Kunjungan tidak berada di cabang RME aktif.',
                ]);
            }

            if ($this->medicalRecords->findByVisitId($clinicVisit->id) !== null) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => 'Rekam medis sudah ada untuk kunjungan ini.',
                ]);
            }

            $safe = array_intersect_key($data, array_flip([
                'subjective', 'objective', 'assessment', 'plan', 'notes',
            ]));

            return $this->medicalRecords->create(array_merge($safe, [
                'clinic_visit_id' => $clinicVisit->id,
                'branch_id' => $clinicVisit->branch_id,
                'patient_id' => $clinicVisit->patient_id,
                'doctor_id' => $clinicVisit->doctor_id,
                'status' => MedicalRecord::STATUS_DRAFT,
                'recorded_by' => $recordedBy,
            ]));
        });
    }

    public function finalize(MedicalRecord $medicalRecord): MedicalRecord
    {
        return DB::transaction(function () use ($medicalRecord) {
            if (! $this->isActiveRmeBranch($medicalRecord->branch_id)) {
                throw ValidationException::withMessages([
                    'medical_record_id' => 'Rekam medis tidak berada di cabang RME aktif.',
                ]);
            }

            if ($medicalRecord->status === MedicalRecord::STATUS_FINAL) {
                return $medicalRecord;
            }

            if (! $this->hasRequiredHandwriting($medicalRecord)) {
                throw ValidationException::withMessages([
                    'handwriting' => 'RME belum dapat difinalkan karena catatan tulis tangan dokter belum tersedia.',
                ]);
            }

            $finalized = $this->medicalRecords->update($medicalRecord, [
                'status' => MedicalRecord::STATUS_FINAL,
                'finalized_at' => now(),
                'finalized_by' => Auth::id(),
            ]);

            $visit = $medicalRecord->clinicVisit;
            if ($visit && $visit->status === ClinicVisit::STATUS_IN_PROGRESS) {
                $this->visitService->transitionStatus($visit, ClinicVisit::STATUS_CASHIER_PENDING);
            }

            return $finalized;
        });
    }

    /**
     * Sprint 59 — doctors may revise a medical record at any time, including
     * records that were previously finalized and visits from older dates. The
     * finalization lock that previously blocked edits is removed; the `status`,
     * `finalized_at`, and `finalized_by` columns are preserved as-is for
     * backward compatibility.
     *
     * Only keys actually present in the submitted payload are written, so a
     * partial save (e.g. only `notes`) never blanks out previously stored
     * fields the doctor did not touch.
     */
    public function updateDraft(MedicalRecord $medicalRecord, array $data): MedicalRecord
    {
        return DB::transaction(function () use ($medicalRecord, $data) {
            if (! $this->isActiveRmeBranch($medicalRecord->branch_id)) {
                throw ValidationException::withMessages([
                    'medical_record_id' => 'Rekam medis tidak berada di cabang RME aktif.',
                ]);
            }

            $safe = array_intersect_key($data, array_flip([
                'subjective', 'objective', 'assessment', 'plan', 'notes',
            ]));

            return $this->medicalRecords->update($medicalRecord, $safe);
        });
    }

    // --- Phase 1.8 alignment helpers (used by Phase 1.9 finalization enforcement) ---

    public function requiresHandwritingBeforeFinal(): bool
    {
        return true;
    }

    public function hasRequiredHandwriting(MedicalRecord $medicalRecord): bool
    {
        return $medicalRecord->hasHandwriting();
    }

    public function canFinalizeRme(MedicalRecord $medicalRecord): bool
    {
        if ($medicalRecord->status === MedicalRecord::STATUS_FINAL) {
            return false;
        }

        return $this->hasRequiredHandwriting($medicalRecord);
    }
}
