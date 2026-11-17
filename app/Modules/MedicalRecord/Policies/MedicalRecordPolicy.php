<?php

namespace App\Modules\MedicalRecord\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\MedicalRecord\Services\PatientRmWorkspaceResolver;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Illuminate\Auth\Access\Response;

class MedicalRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    /**
     * Rule 243 §2 — with `rme.doctor_encounter_scope` ON a per-visit sheet is
     * read by its OWN visit's rule (own visit any date; another doctor's only
     * past the pre-exam stage inside an own in-service encounter; else 404).
     * The patient's handwriting BOOK is patient-level (HISTORY). With the flag
     * OFF every sheet keeps the patient-level relationship scope, as before.
     */
    public function view(User $user, MedicalRecord $medicalRecord): Response|bool
    {
        if (! $this->canView($user) || ! $this->mayReadDocumentAt($user, $medicalRecord->branch_id)) {
            return false;
        }

        if ($this->sheetFollowsItsVisit($medicalRecord)) {
            return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $medicalRecord->clinicVisit);
        }

        return $this->authorizePatientForRecord($user, $medicalRecord);
    }

    public function create(User $user, ClinicVisit $clinicVisit): Response|bool
    {
        if (! $this->canManage($user) || ! $this->belongsToActiveBranch($clinicVisit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $clinicVisit);
    }

    /**
     * Rule 243 §2 — the typed fields, diagnoses and finalisation of a sheet are
     * WRITES on its own visit: with the encounter scope ON only the doctor of
     * that visit, while it is in service (403 "ditangani dokter lain" /
     * "sudah selesai dilayani"; 404 when not even readable). This includes the
     * book's own typed fields, which belong to the visit that anchors it. With
     * the flag OFF: the patient-level relationship scope, unchanged.
     */
    public function update(User $user, MedicalRecord $medicalRecord): Response|bool
    {
        if (! $this->canManage($user) || ! $this->belongsToActiveBranch($medicalRecord->branch_id)) {
            return false;
        }

        return $this->authorizeSheetWrite($user, $medicalRecord);
    }

    /**
     * Rule 240 §1 (memo M3.3, owner decision 2026-11-17) — may this user edit the
     * SHEET's own content: SOAP fields, notes, structured diagnoses, the emergency
     * diagnosis override? Scope first, so an account outside it is refused for
     * that reason and learns nothing about the sheet's state; then a final sheet
     * is refused (corrections go through a supervised amendment).
     *
     * Deliberately separate from update(), which the handwriting book still asks
     * (the canonical book is usually an earlier, final sheet; sealing its pages is
     * a later part of rule 240). Super Admin passes here through Gate::before, so
     * the services re-decide the lock on the row they lock (CG-06).
     */
    public function editSheet(User $user, MedicalRecord $medicalRecord): Response|bool
    {
        $scope = $this->update($user, $medicalRecord);

        if ($scope === false || ($scope instanceof Response && $scope->denied())) {
            return $scope;
        }

        if ($medicalRecord->status === MedicalRecord::STATUS_FINAL) {
            return Response::deny(MedicalRecordService::MESSAGE_FINAL_SHEET_LOCKED);
        }

        return $scope;
    }

    public function finalize(User $user, MedicalRecord $medicalRecord): Response|bool
    {
        if (! $this->canManage($user) || ! $this->belongsToActiveBranch($medicalRecord->branch_id)) {
            return false;
        }

        return $this->authorizeSheetWrite($user, $medicalRecord);
    }

    /**
     * Rule 243 §2 — appending a handwriting page to the patient's BOOK. The book
     * is patient-level: with the encounter scope ON a Doctor appends only inside
     * an own in-service encounter (HISTORY; RmeVisitConsentService then requires
     * the live encounter to be the doctor's own). Re-saving an existing book page
     * goes through the same door until M3.3's page seal (rule 240 §4) — the flag
     * stays OFF until that is released. With the flag OFF this is `update`.
     */
    public function appendPage(User $user, MedicalRecord $medicalRecord): Response|bool
    {
        if (! $this->canManage($user) || ! $this->belongsToActiveBranch($medicalRecord->branch_id)) {
            return false;
        }

        return $this->authorizePatientForRecord($user, $medicalRecord);
    }

    private function authorizeSheetWrite(User $user, MedicalRecord $medicalRecord): Response|bool
    {
        $scope = app(DoctorPatientScopeService::class);

        if (! $scope->encounterScopeEnabled() || ! $scope->shouldApplyDoctorScope($user)) {
            return $this->authorizePatientForRecord($user, $medicalRecord);
        }

        $visit = $medicalRecord->clinicVisit;

        if ($visit === null) {
            return Response::denyAsNotFound(DoctorPatientScopeService::OUT_OF_SCOPE_MESSAGE);
        }

        // The book is read patient-level, so its typed fields must not answer
        // 404 to a doctor who may read it: read first by the sheet's own rule,
        // then the write rule of its own visit.
        $read = $this->view($user, $medicalRecord);

        if ($read !== true) {
            return $read;
        }

        return $scope->authorizeVisitWrite($user, $visit) === true
            ? true
            : $this->visitWriteDenial($scope, $user, $visit);
    }

    /**
     * The write refusal for a sheet the doctor may read: 403 with the reason
     * (another doctor's visit, or the doctor's own visit out of service) —
     * never 404, which authorizeVisitWrite() would answer for a book anchored to
     * a pre-exam visit the doctor may not read on its own.
     */
    private function visitWriteDenial(DoctorPatientScopeService $scope, User $user, ClinicVisit $visit): Response
    {
        $doctor = $scope->resolveDoctorForUser($user);

        return Response::deny($doctor !== null && (int) $visit->doctor_id === (int) $doctor->id
            ? DoctorPatientScopeService::NOT_IN_SERVICE_MESSAGE
            : DoctorPatientScopeService::OTHER_DOCTOR_MESSAGE);
    }

    /**
     * With the encounter scope ON, every sheet except the patient's handwriting
     * BOOK follows its own visit; the book stays patient-level.
     */
    private function sheetFollowsItsVisit(MedicalRecord $medicalRecord): bool
    {
        if (! app(DoctorPatientScopeService::class)->encounterScopeEnabled() || $medicalRecord->clinicVisit === null) {
            return false;
        }

        $book = app(PatientRmWorkspaceResolver::class)->canonicalMedicalRecord((int) $medicalRecord->patient_id);

        return $book === null || (int) $book->getKey() !== (int) $medicalRecord->getKey();
    }

    private function authorizePatientForRecord(User $user, MedicalRecord $medicalRecord): Response|bool
    {
        $patient = $medicalRecord->patient;

        if ($patient !== null) {
            return app(DoctorPatientScopeService::class)->authorizePatientAccess($user, $patient);
        }

        return false;
    }

    /**
     * DMS-RME-REVISION-PROGRAM-8B / D1 — the clinical DOCUMENT read scope, an
     * allowlist since DMS-RME-REVISION-PROGRAM-8C (D3-NEW-4, rule 228 §5): the
     * context-bound front desk and cashier read only their working branch; only
     * RmeWorkingBranchScope::CROSS_BRANCH_DOCUMENT_READ_ROLES (the clinical roles
     * and Owner) keep the RME-enabled set, never wider; any other account,
     * including an unclassified role, reads none
     * (RmeWorkingBranchScope::allowsClinicalDocument()). The doctor-patient scope
     * still applies on top, in this policy.
     */
    private function mayReadDocumentAt(User $user, ?int $branchId): bool
    {
        return app(RmeWorkingBranchScope::class)->allowsClinicalDocument($user, $branchId);
    }

    private function canView(User $user): bool
    {
        return $user->canAny(['view_clinic_visits', 'manage_clinic_visits']);
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage_clinic_visits');
    }

    private function belongsToActiveBranch(?int $branchId): bool
    {
        if ($branchId === null) {
            return false;
        }

        return in_array($branchId, app(BranchService::class)->rmeEnabledIds(), true);
    }
}
