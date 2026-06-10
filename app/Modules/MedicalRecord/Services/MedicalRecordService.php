<?php

namespace App\Modules\MedicalRecord\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MedicalRecordService
{
    public function __construct(
        private readonly MedicalRecordRepositoryInterface $medicalRecords,
        private readonly BranchContext $branchContext,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->medicalRecords->paginateForBranch(
            $this->branchContext->requireId(),
            $filters,
            $perPage,
        );
    }

    public function draftCount(): int
    {
        return $this->medicalRecords->countByBranchStatus(
            $this->branchContext->requireId(),
            MedicalRecord::STATUS_DRAFT,
        );
    }

    public function finalizedTodayCount(): int
    {
        return $this->medicalRecords->countFinalizedTodayByBranch(
            $this->branchContext->requireId(),
            now()->toDateString(),
        );
    }

    public function createDraft(ClinicVisit $clinicVisit, ?int $recordedBy = null, array $data = []): MedicalRecord
    {
        return DB::transaction(function () use ($clinicVisit, $recordedBy, $data) {
            $branchId = $this->branchContext->requireId();

            if ($clinicVisit->branch_id !== $branchId) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => 'Kunjungan tidak ditemukan di cabang aktif.',
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
            $branchId = $this->branchContext->requireId();

            if ($medicalRecord->branch_id !== $branchId) {
                throw ValidationException::withMessages([
                    'medical_record_id' => 'Rekam medis tidak ditemukan di cabang aktif.',
                ]);
            }

            if ($medicalRecord->status === MedicalRecord::STATUS_FINAL) {
                return $medicalRecord;
            }

            return $this->medicalRecords->update($medicalRecord, [
                'status' => MedicalRecord::STATUS_FINAL,
                'finalized_at' => now(),
            ]);
        });
    }

    public function updateDraft(MedicalRecord $medicalRecord, array $data): MedicalRecord
    {
        return DB::transaction(function () use ($medicalRecord, $data) {
            $branchId = $this->branchContext->requireId();

            if ($medicalRecord->branch_id !== $branchId) {
                throw ValidationException::withMessages([
                    'medical_record_id' => 'Rekam medis tidak ditemukan di cabang aktif.',
                ]);
            }

            if ($medicalRecord->status === MedicalRecord::STATUS_FINAL) {
                throw ValidationException::withMessages([
                    'status' => 'Rekam medis yang sudah final tidak dapat diubah.',
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

        // TODO Phase 1.9: enforce hasRequiredHandwriting() before allowing finalization.
        return true;
    }
}
