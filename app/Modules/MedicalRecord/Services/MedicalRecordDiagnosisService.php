<?php

namespace App\Modules\MedicalRecord\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use App\Modules\Satusehat\Services\SatusehatCandidateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4A — structured diagnosis entry on a medical record.
 *
 * A diagnosis is only ever recorded explicitly by an authorized user (the
 * medical-record update authority) — never auto-created, never guessed from
 * free text. Legacy records stay readable and are never backfilled. Changing
 * diagnoses on an approved SATUSEHAT candidate is safe by design: the source
 * hash drifts, the approval is revoked, and the candidate returns to review.
 */
class MedicalRecordDiagnosisService
{
    public function __construct(
        private readonly BranchService $branches,
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /**
     * @param  array{clinical_diagnosis_id: int, diagnosis_role: string, notes?: ?string}  $data
     */
    public function record(MedicalRecord $medicalRecord, array $data, User $actor): MedicalRecordDiagnosis
    {
        /*
         * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-02 — a structured
         * diagnosis is clinical content OF the medical record (it feeds the RME,
         * the FHIR Condition preview and the SATUSEHAT source hash), so it is
         * gated exactly like the rest of the record. Asserted in the service so
         * the CLI and any future controller inherit it.
         *
         * CORRECTIVE-03 — the ACTOR the caller vouched for is the actor the gate
         * authorises. Every method here already receives $actor explicitly and
         * writes it to the audit trail, but the gate was falling back to
         * Auth::user(), so a legitimate non-HTTP caller was judged against an
         * empty session. Passing it through matches DiagnosisRolloutService and
         * keeps one actor for both the decision and the record of it. Not a
         * widening: an unresolvable actor still fails closed, and the encounter,
         * scope and consent conditions are unchanged.
         *
         * Rule 240 §1.8 — a final sheet answers with its lock before a missing
         * PTM (MedicalRecordService::assertSheetAuthoringAllowed()). The RME
         * branch set is decided first, as updateDraft() does: a sheet outside it
         * answers with the branch refusal, never with its lock.
         */
        $this->assertRmeBranch($medicalRecord);

        app(MedicalRecordService::class)->assertSheetAuthoringAllowed($medicalRecord, $actor);

        // Rule 243 §2.5 — after the RME authoring gate and the rule 240 lock
        // (§1.8 order kept): with the encounter scope ON, only the doctor of the
        // sheet's own visit, while in service (flag OFF: no-op).
        app(MedicalRecordSheetWriteScope::class)->assertWritable($medicalRecord, $actor);

        $master = ClinicalDiagnosis::query()->find((int) $data['clinical_diagnosis_id']);
        if ($master === null || $master->status !== ClinicalDiagnosis::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'clinical_diagnosis_id' => 'Diagnosis master tidak ditemukan atau tidak aktif.',
            ]);
        }

        $role = (string) $data['diagnosis_role'];
        if (! in_array($role, MedicalRecordDiagnosis::ROLES, true)) {
            throw ValidationException::withMessages(['diagnosis_role' => 'Peran diagnosis tidak valid.']);
        }

        return DB::transaction(function () use ($medicalRecord, $master, $role, $data, $actor) {
            // DMS-RME-COMPREHENSIVE-CLOSURE-1 (rule 223 §6, rule 219 §12a) — re-decided
            // inside the transaction: locks the encounter's visit row FIRST, so a
            // consent void or a finish committed meanwhile is seen.
            // Rule 240 §1 (memo M3.3) — a final sheet's diagnoses are locked;
            // decided on the row locked after the visit, binding Super Admin, and
            // before a missing PTM (§1.8).
            $record = app(MedicalRecordService::class)->assertSheetAuthoringAllowed(
                $medicalRecord,
                $actor,
                fn (): MedicalRecord => MedicalRecord::query()->lockForUpdate()->findOrFail($medicalRecord->id),
            );

            // Rule 243 §2.5 — re-decided on the LOCKED sheet (its own visit is
            // read fresh, after the gate above locked the live encounter).
            app(MedicalRecordSheetWriteScope::class)->assertWritable($record, $actor);

            $duplicate = MedicalRecordDiagnosis::query()
                ->where('medical_record_id', $record->id)
                ->where('clinical_diagnosis_id', $master->id)
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'clinical_diagnosis_id' => 'Diagnosis ini sudah tercatat pada rekam medis.',
                ]);
            }

            if ($role === MedicalRecordDiagnosis::ROLE_PRIMARY) {
                $hasPrimary = MedicalRecordDiagnosis::query()
                    ->where('medical_record_id', $record->id)
                    ->where('diagnosis_role', MedicalRecordDiagnosis::ROLE_PRIMARY)
                    ->exists();
                if ($hasPrimary) {
                    throw ValidationException::withMessages([
                        'diagnosis_role' => 'Rekam medis sudah memiliki diagnosis utama — gunakan peran sekunder atau hapus diagnosis utama sebelumnya.',
                    ]);
                }
            }

            $diagnosis = MedicalRecordDiagnosis::create([
                'medical_record_id' => (int) $record->id,
                'clinic_visit_id' => (int) $record->clinic_visit_id,
                'branch_id' => (int) $record->branch_id,
                'clinical_diagnosis_id' => (int) $master->id,
                'diagnosis_role' => $role,
                'diagnosed_by' => $actor->id,
                'diagnosed_at' => now(),
                'notes' => isset($data['notes']) && is_string($data['notes']) ? mb_substr(trim($data['notes']), 0, 500) : null,
                'created_by' => $actor->id,
            ]);

            $this->audit->log(
                'medical_record',
                (int) $record->id,
                SatusehatAuditLog::EVENT_DIAGNOSIS_RECORDED,
                'Diagnosis terstruktur dicatat',
                ['diagnosis_code' => (string) $master->code, 'role' => $role],
                (int) $record->branch_id,
                $actor,
            );

            $this->refreshCandidate($record, $actor);

            return $diagnosis;
        });
    }

    /**
     * SATUSEHAT-4B — explicit primary swap. The current primary (if any) is
     * demoted to secondary and the chosen diagnosis promoted, in one locked
     * transaction. Never silent: requested explicitly and audited.
     */
    public function makePrimary(MedicalRecord $medicalRecord, MedicalRecordDiagnosis $diagnosis, User $actor): MedicalRecordDiagnosis
    {
        /*
         * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-02 — a structured
         * diagnosis is clinical content OF the medical record (it feeds the RME,
         * the FHIR Condition preview and the SATUSEHAT source hash), so it is
         * gated exactly like the rest of the record. Asserted in the service so
         * the CLI and any future controller inherit it.
         *
         * CORRECTIVE-03 — the ACTOR the caller vouched for is the actor the gate
         * authorises. Every method here already receives $actor explicitly and
         * writes it to the audit trail, but the gate was falling back to
         * Auth::user(), so a legitimate non-HTTP caller was judged against an
         * empty session. Passing it through matches DiagnosisRolloutService and
         * keeps one actor for both the decision and the record of it. Not a
         * widening: an unresolvable actor still fails closed, and the encounter,
         * scope and consent conditions are unchanged.
         *
         * Rule 240 §1.8 — a final sheet answers with its lock before a missing
         * PTM (MedicalRecordService::assertSheetAuthoringAllowed()). The RME
         * branch set is decided first, as updateDraft() does: a sheet outside it
         * answers with the branch refusal, never with its lock.
         */
        $this->assertRmeBranch($medicalRecord);

        app(MedicalRecordService::class)->assertSheetAuthoringAllowed($medicalRecord, $actor);

        // Rule 243 §2.5 — after the RME authoring gate and the rule 240 lock
        // (§1.8 order kept): with the encounter scope ON, only the doctor of the
        // sheet's own visit, while in service (flag OFF: no-op).
        app(MedicalRecordSheetWriteScope::class)->assertWritable($medicalRecord, $actor);

        if ((int) $diagnosis->medical_record_id !== (int) $medicalRecord->id) {
            throw ValidationException::withMessages(['diagnosis' => 'Diagnosis tidak sesuai rekam medis.']);
        }

        return DB::transaction(function () use ($medicalRecord, $diagnosis, $actor) {
            // DMS-RME-COMPREHENSIVE-CLOSURE-1 (rule 223 §6, rule 219 §12a) — re-decided
            // inside the transaction: locks the encounter's visit row FIRST, so a
            // consent void or a finish committed meanwhile is seen.
            // Rule 240 §1 — a final sheet's diagnoses are locked (§1.8 order).
            $record = app(MedicalRecordService::class)->assertSheetAuthoringAllowed(
                $medicalRecord,
                $actor,
                fn (): MedicalRecord => MedicalRecord::query()->lockForUpdate()->findOrFail($medicalRecord->id),
            );

            // Rule 243 §2.5 — re-decided on the LOCKED sheet (its own visit is
            // read fresh, after the gate above locked the live encounter).
            app(MedicalRecordSheetWriteScope::class)->assertWritable($record, $actor);

            /** @var MedicalRecordDiagnosis $target */
            $target = MedicalRecordDiagnosis::query()->lockForUpdate()->findOrFail($diagnosis->id);

            if ($target->diagnosis_role === MedicalRecordDiagnosis::ROLE_PRIMARY) {
                return $target; // idempotent no-op
            }

            $previousPrimary = MedicalRecordDiagnosis::query()
                ->where('medical_record_id', $record->id)
                ->where('diagnosis_role', MedicalRecordDiagnosis::ROLE_PRIMARY)
                ->lockForUpdate()
                ->first();

            $previousPrimary?->update(['diagnosis_role' => MedicalRecordDiagnosis::ROLE_SECONDARY]);
            $target->update(['diagnosis_role' => MedicalRecordDiagnosis::ROLE_PRIMARY]);

            $this->audit->log(
                'medical_record',
                (int) $record->id,
                SatusehatAuditLog::EVENT_DIAGNOSIS_ROLE_CHANGED,
                'Diagnosis utama rekam medis diganti',
                [
                    'new_primary_code' => (string) $target->clinicalDiagnosis?->code,
                    'previous_primary_code' => $previousPrimary?->clinicalDiagnosis?->code,
                ],
                (int) $record->branch_id,
                $actor,
            );

            $this->refreshCandidate($record, $actor);

            return $target;
        });
    }

    public function remove(MedicalRecord $medicalRecord, MedicalRecordDiagnosis $diagnosis, User $actor): void
    {
        /*
         * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-02 — a structured
         * diagnosis is clinical content OF the medical record (it feeds the RME,
         * the FHIR Condition preview and the SATUSEHAT source hash), so it is
         * gated exactly like the rest of the record. Asserted in the service so
         * the CLI and any future controller inherit it.
         *
         * CORRECTIVE-03 — the ACTOR the caller vouched for is the actor the gate
         * authorises. Every method here already receives $actor explicitly and
         * writes it to the audit trail, but the gate was falling back to
         * Auth::user(), so a legitimate non-HTTP caller was judged against an
         * empty session. Passing it through matches DiagnosisRolloutService and
         * keeps one actor for both the decision and the record of it. Not a
         * widening: an unresolvable actor still fails closed, and the encounter,
         * scope and consent conditions are unchanged.
         *
         * Rule 240 §1.8 — a final sheet answers with its lock before a missing
         * PTM (MedicalRecordService::assertSheetAuthoringAllowed()). The RME
         * branch set is decided first, as updateDraft() does: a sheet outside it
         * answers with the branch refusal, never with its lock.
         */
        $this->assertRmeBranch($medicalRecord);

        app(MedicalRecordService::class)->assertSheetAuthoringAllowed($medicalRecord, $actor);

        // Rule 243 §2.5 — after the RME authoring gate and the rule 240 lock
        // (§1.8 order kept): with the encounter scope ON, only the doctor of the
        // sheet's own visit, while in service (flag OFF: no-op).
        app(MedicalRecordSheetWriteScope::class)->assertWritable($medicalRecord, $actor);

        if ((int) $diagnosis->medical_record_id !== (int) $medicalRecord->id) {
            throw ValidationException::withMessages(['diagnosis' => 'Diagnosis tidak sesuai rekam medis.']);
        }

        DB::transaction(function () use ($medicalRecord, $diagnosis, $actor) {
            // DMS-RME-COMPREHENSIVE-CLOSURE-1 (rule 223 §6, rule 219 §12a) — re-decided
            // inside the transaction: locks the encounter's visit row FIRST, so a
            // consent void or a finish committed meanwhile is seen.
            // Rule 240 §1 — a final sheet's diagnoses are locked; the sheet row is
            // locked after the visit, as record() and makePrimary() do (§1.8 order).
            app(MedicalRecordService::class)->assertSheetAuthoringAllowed(
                $medicalRecord,
                $actor,
                fn (): MedicalRecord => MedicalRecord::query()->lockForUpdate()->findOrFail($medicalRecord->id),
            );

            // Rule 243 §2.5 — re-decided inside the transaction.
            app(MedicalRecordSheetWriteScope::class)->assertWritable($medicalRecord, $actor);

            $code = $diagnosis->clinicalDiagnosis?->code;
            $diagnosis->delete();

            $this->audit->log(
                'medical_record',
                (int) $medicalRecord->id,
                SatusehatAuditLog::EVENT_DIAGNOSIS_REMOVED,
                'Diagnosis terstruktur dihapus',
                ['diagnosis_code' => (string) $code],
                (int) $medicalRecord->branch_id,
                $actor,
            );

            $this->refreshCandidate($medicalRecord, $actor);
        });
    }

    private function assertRmeBranch(MedicalRecord $medicalRecord): void
    {
        if (! in_array((int) $medicalRecord->branch_id, $this->branches->rmeEnabledIds(), true)) {
            throw ValidationException::withMessages([
                'medical_record_id' => 'Rekam medis bukan milik cabang RME aktif.',
            ]);
        }
    }

    /**
     * Post-commit candidate refresh so readiness (+drift revocation) follows
     * diagnosis changes automatically. Failure never rolls the write back.
     */
    private function refreshCandidate(MedicalRecord $medicalRecord, User $actor): void
    {
        $visitId = (int) $medicalRecord->clinic_visit_id;

        DB::afterCommit(function () use ($visitId, $actor) {
            try {
                $candidate = SatusehatCandidate::query()
                    ->where('clinic_visit_id', $visitId)
                    ->first();
                if ($candidate !== null) {
                    app(SatusehatCandidateService::class)->refresh($candidate, $actor);
                }
            } catch (\Throwable $e) {
                Log::warning('SATUSEHAT candidate refresh failed after diagnosis change', [
                    'clinic_visit_id' => $visitId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
