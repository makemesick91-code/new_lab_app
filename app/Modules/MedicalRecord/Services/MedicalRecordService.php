<?php

namespace App\Modules\MedicalRecord\Services;

use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RME\Services\DoctorPatientScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MedicalRecordService
{
    public function __construct(
        private readonly MedicalRecordRepositoryInterface $medicalRecords,
        private readonly BranchService $branches,
        // FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-01 — ClinicVisitService
        // is deliberately NOT a dependency of this service. Authoring or
        // finalizing a clinical document must never move the visit through the
        // workflow, so this class does not even hold the ability to do it.
        private readonly PatientRmWorkspaceResolver $workspace,
        private readonly DoctorPatientScopeService $doctorScope,
        private readonly DiagnosisRolloutService $diagnosisRollout,
        // FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-02 — every write path
        // in this service asserts the consent gate before it touches a record.
        private readonly RmeVisitConsentService $consents,
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
        $user = Auth::user();
        $scope = $user !== null
            ? fn ($query) => $this->doctorScope->applyMedicalRecordScopeForUser($user, $query)
            : null;

        return $this->medicalRecords->paginateForBranches(
            $this->branches->rmeEnabledIds(),
            $filters,
            $perPage,
            $scope,
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

            // FIX-02 — no RME sheet is created for a live visit without consent.
            // Patient-scoped first: which visit a request claims to be "for" is client
            // input, so the binding question is whether this PATIENT has an open,
            // unconsented encounter.
            $this->consents->assertPatientRecordWritable($clinicVisit->patient_id);
            $this->consents->assertRmeAuthoringAllowed($clinicVisit);

            $safe = array_intersect_key($data, array_flip([
                'subjective', 'objective', 'assessment', 'plan', 'notes',
            ]));

            // Sprint 64.0 — stamp the patient-workspace audit columns at create
            // time. canonical = the true first visit (existing anchor or self);
            // source = this visit (one sheet per visit); sheet_number = next
            // per-patient ordinal.
            $sourceVisitId = isset($data['source_visit_id'])
                ? (int) $data['source_visit_id']
                : $clinicVisit->id;

            return $this->medicalRecords->create(array_merge($safe, [
                'clinic_visit_id' => $clinicVisit->id,
                'canonical_visit_id' => $this->workspace->pickCanonicalVisitId($clinicVisit),
                'source_visit_id' => $sourceVisitId,
                'sheet_number' => $this->workspace->nextSheetNumber($clinicVisit->patient_id),
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

            // FIX-02 — finalization is a write to this visit's record.
            $this->consents->assertPatientRecordWritable($medicalRecord->patient_id);
            $this->consents->assertRmeAuthoringAllowed($medicalRecord->clinicVisit);

            if (! $this->hasRequiredHandwriting($medicalRecord)) {
                throw ValidationException::withMessages([
                    'handwriting' => 'RME belum dapat difinalkan karena catatan tulis tangan dokter belum tersedia.',
                ]);
            }

            // SATUSEHAT-4B — branch-scoped structured diagnosis rollout gate.
            // Blocks ONLY on an explicitly configured pilot_enforced branch
            // without an active primary diagnosis or a reasoned override;
            // informational/warning branches are never blocked here.
            $this->diagnosisRollout->assertFinalizationAllowed($medicalRecord);

            $finalized = $this->medicalRecords->update($medicalRecord, [
                'status' => MedicalRecord::STATUS_FINAL,
                'finalized_at' => now(),
                'finalized_by' => Auth::id(),
            ]);

            // Sprint 64.0 — lazily backfill workspace audit columns (no-op once
            // stamped).
            $this->workspace->stampWorkspaceFields($finalized);

            /*
             * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-01 — finalizing a
             * medical record NO LONGER ends the examination.
             *
             * This method used to transition an in_progress visit to
             * cashier_pending, so completing a clinical DOCUMENT silently
             * completed the doctor's EXAMINATION and handed the patient to the
             * cashier. Those are two different facts. A doctor who finalizes one
             * RM sheet may still have an odontogram to record, a second sheet to
             * write, or a treatment decision to revise — and since Sprint 59 a
             * finalized record is explicitly editable again, so "final" was never
             * a reliable proxy for "the examination is over".
             *
             * Document finalization is now purely a clinical-document state.
             * The workflow transition in_progress -> cashier_pending happens ONLY
             * when an authorized doctor explicitly performs "Selesai Pemeriksaan"
             * (ClinicVisitController::transitionStatus, gated by
             * ClinicVisitPolicy::completeExamination / complete_rme_examination).
             *
             * Nothing here may re-introduce an automatic transition: not this
             * method, not odontogram saving, not handwriting completion, and not
             * any combination of them.
             */
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

            // FIX-02 — editing this visit's record is a write to it.
            $this->consents->assertPatientRecordWritable($medicalRecord->patient_id);
            $this->consents->assertRmeAuthoringAllowed($medicalRecord->clinicVisit);

            $safe = array_intersect_key($data, array_flip([
                'subjective', 'objective', 'assessment', 'plan', 'notes',
            ]));

            $updated = $this->medicalRecords->update($medicalRecord, $safe);

            // Sprint 64.0 — lazily backfill workspace audit columns for legacy
            // sheets created before this sprint (no-op once stamped).
            $this->workspace->stampWorkspaceFields($updated);

            return $updated;
        });
    }

    // --- Phase 1.8 alignment helpers (used by Phase 1.9 finalization enforcement) ---

    /**
     * Sprint 64.0.2 — ensure the canonical visit owns the patient's handwriting
     * RM book. Creates a draft on the canonical visit when missing; never creates
     * a duplicate on a later visit.
     */
    public function getOrCreateCanonicalMedicalRecord(
        ClinicVisit $contextVisit,
        ?int $recordedBy = null,
        ?int $sourceVisitId = null,
    ): MedicalRecord {
        $patientId = $contextVisit->patient_id;
        $canonicalVisit = $this->workspace->resolveCanonicalWorkspaceVisit($patientId)
            ?? $contextVisit;

        /*
         * FIX-02 — gate the ENCOUNTER, not just the storage target.
         *
         * Sprint 64.0.2 writes the patient's handwriting book onto the CANONICAL
         * visit, which is usually an older, already-terminal visit and therefore
         * outside the consent window. Content authored during the live encounter
         * ($contextVisit) would otherwise be written through that exempt
         * historical visit with no consent for the treatment actually happening.
         */
        $this->consents->assertPatientRecordWritable($patientId);
        $this->consents->assertRmeAuthoringAllowedForAll([$contextVisit, $canonicalVisit]);

        $existing = $this->medicalRecords->findByVisitId($canonicalVisit->id);

        if ($existing !== null) {
            return $existing;
        }

        return $this->createDraft($canonicalVisit, $recordedBy, array_filter([
            'source_visit_id' => $sourceVisitId ?? $contextVisit->id,
        ], fn ($v) => $v !== null));
    }

    public function requiresHandwritingBeforeFinal(): bool
    {
        return true;
    }

    /**
     * Sprint 64.0.2 — one patient = one handwriting RM book on the canonical
     * visit. Finalize may proceed when the active sheet OR the canonical book
     * has at least one saved handwriting page.
     */
    public function hasHandwritingForVisitOrCanonicalBook(MedicalRecord $medicalRecord): bool
    {
        if ($this->recordHasHandwriting($medicalRecord)) {
            return true;
        }

        $canonical = $this->workspace->canonicalMedicalRecord($medicalRecord->patient_id);

        if ($canonical === null || $canonical->is($medicalRecord)) {
            return false;
        }

        return $this->recordHasHandwriting($canonical);
    }

    public function hasRequiredHandwriting(MedicalRecord $medicalRecord): bool
    {
        return $this->hasHandwritingForVisitOrCanonicalBook($medicalRecord);
    }

    private function recordHasHandwriting(MedicalRecord $medicalRecord): bool
    {
        if ($medicalRecord->hasHandwriting()) {
            return true;
        }

        return $medicalRecord->handwritingPages()->exists();
    }

    public function canFinalizeRme(MedicalRecord $medicalRecord): bool
    {
        if ($medicalRecord->status === MedicalRecord::STATUS_FINAL) {
            return false;
        }

        return $this->hasRequiredHandwriting($medicalRecord);
    }
}
