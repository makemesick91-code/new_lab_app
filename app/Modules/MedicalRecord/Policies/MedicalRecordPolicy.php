<?php

namespace App\Modules\MedicalRecord\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Illuminate\Auth\Access\Response;

class MedicalRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, MedicalRecord $medicalRecord): Response|bool
    {
        if (! $this->canView($user) || ! $this->mayReadDocumentAt($user, $medicalRecord->branch_id)) {
            return false;
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

    public function update(User $user, MedicalRecord $medicalRecord): Response|bool
    {
        if (! $this->canManage($user) || ! $this->belongsToActiveBranch($medicalRecord->branch_id)) {
            return false;
        }

        return $this->authorizePatientForRecord($user, $medicalRecord);
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

        return $this->authorizePatientForRecord($user, $medicalRecord);
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
