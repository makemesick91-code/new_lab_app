<?php

namespace App\Modules\MedicalRecord\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
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
