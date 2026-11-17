<?php

namespace App\Modules\MedicalRecord\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RME\Services\DoctorPatientScopeService;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 (rule 243 §2.5, PR A1b) — which
 * sheets follow their OWN visit under `rme.doctor_encounter_scope`, and the
 * service-layer form of the sheet write rule.
 *
 * With the flag ON every sheet except the patient's handwriting BOOK
 * ({@see PatientRmWorkspaceResolver::canonicalMedicalRecord()}) is read and
 * written by the rule of its own visit; the book is patient-level (read in
 * HISTORY, pages appended through MedicalRecordPolicy::appendPage). With the
 * flag OFF nothing here applies.
 *
 * {@see assertWritable()} is the third layer (CG-06) behind
 * MedicalRecordPolicy::update/finalize: MedicalRecordService (updateDraft,
 * finalize), MedicalRecordDiagnosisService (record, makePrimary, remove) and
 * DiagnosisRolloutService (grantOverride) call it after the RME authoring gate,
 * so a direct call or a bypassed policy (Gate::before) is refused the same way.
 */
class MedicalRecordSheetWriteScope
{
    public function __construct(
        private readonly DoctorPatientScopeService $doctorScope,
        private readonly PatientRmWorkspaceResolver $workspace,
    ) {}

    public function followsItsVisit(MedicalRecord $sheet): bool
    {
        if (! $this->doctorScope->encounterScopeEnabled() || $sheet->clinic_visit_id === null) {
            return false;
        }

        $book = $this->workspace->canonicalMedicalRecord((int) $sheet->patient_id);

        return $book === null || (int) $book->getKey() !== (int) $sheet->getKey();
    }

    /**
     * A non-book sheet is written only by the doctor of its own visit while that
     * visit is in service. The visit is read fresh, so a caller inside the write
     * transaction (after the authoring gate has locked the live encounter) decides
     * on the current row.
     *
     * @throws AuthorizationException
     */
    public function assertWritable(MedicalRecord $sheet, ?User $actor): void
    {
        if (! $this->doctorScope->encounterScopeEnabled()) {
            return;
        }

        if (! $actor instanceof User) {
            throw new AuthorizationException(DoctorPatientScopeService::OUT_OF_SCOPE_MESSAGE);
        }

        if (! $this->doctorScope->shouldApplyDoctorScope($actor) || ! $this->followsItsVisit($sheet)) {
            return;
        }

        $visit = ClinicVisit::query()->find($sheet->clinic_visit_id);

        if ($visit !== null && $this->doctorScope->doctorMayWriteVisit($actor, $visit)) {
            return;
        }

        $doctor = $this->doctorScope->resolveDoctorForUser($actor);

        throw new AuthorizationException(
            $visit !== null && $doctor !== null && (int) $visit->doctor_id === (int) $doctor->id
                ? DoctorPatientScopeService::NOT_IN_SERVICE_MESSAGE
                : DoctorPatientScopeService::OTHER_DOCTOR_MESSAGE,
        );
    }
}
