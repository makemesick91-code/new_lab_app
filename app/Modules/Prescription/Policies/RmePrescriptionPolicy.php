<?php

namespace App\Modules\Prescription\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\Prescription\Services\PrescriptionAuthoringAuthority;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RME\Services\DoctorRoomScopeService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Illuminate\Auth\Access\Response;

class RmePrescriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, RmePrescription $prescription): Response|bool
    {
        if (! $this->canView($user) || ! $this->mayReadDocumentAt($user, $prescription->branch_id)) {
            return false;
        }

        $visit = $prescription->clinicVisit;

        return $visit !== null ? $this->clinicalVisitAccess($user, $visit) : false;
    }

    public function print(User $user, RmePrescription $prescription): Response|bool
    {
        return $this->view($user, $prescription);
    }

    public function viewForVisit(User $user, ClinicVisit $clinicVisit): Response|bool
    {
        if (! $this->canView($user) || ! $this->mayReadDocumentAt($user, $clinicVisit->branch_id)) {
            return false;
        }

        return $this->clinicalVisitAccess($user, $clinicVisit);
    }

    public function create(User $user, ClinicVisit $clinicVisit): Response|bool
    {
        if (! $this->canManage($user)
            || ! $this->belongsToActiveBranch($clinicVisit->branch_id)
            || ! $this->mayAuthorAt($user, $clinicVisit->branch_id)) {
            return false;
        }

        return $this->clinicalVisitAccess($user, $clinicVisit);
    }

    /**
     * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-02) — hand this prescription to
     * the patient over WhatsApp. Its own clinical permission, so viewing or
     * printing a prescription never implies the authority to transmit it.
     */
    public function sendWhatsApp(User $user, RmePrescription $prescription): Response|bool
    {
        if (! $user->can('send_prescription_whatsapp')) {
            return false;
        }

        return $this->view($user, $prescription);
    }

    public function update(User $user, RmePrescription $prescription): Response|bool
    {
        if (! $this->canManage($user)
            || ! $this->belongsToActiveBranch($prescription->branch_id)
            || ! $this->mayAuthorAt($user, $prescription->branch_id)) {
            return false;
        }

        return $this->view($user, $prescription);
    }

    /**
     * The doctor-patient scope (Sprint 66.2) and, since DMS-RME-REVISION-PROGRAM-8C
     * (OD-7-2 / R8A-1, rule 228), the doctor ROOM scope of rule 138: a doctor never
     * opens or writes the prescription of an ACTIVE visit of another treatment
     * room. Post-examination and historical visits are never room-restricted
     * (rule 138 §17), and accounts that are not doctor-scoped are unaffected.
     */
    private function clinicalVisitAccess(User $user, ClinicVisit $visit): Response|bool
    {
        $patientScope = app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $visit);

        if ($patientScope instanceof Response ? $patientScope->denied() : ! $patientScope) {
            return $patientScope;
        }

        return app(DoctorRoomScopeService::class)->authorizeActiveVisitRoom($user, $visit);
    }

    /**
     * DMS-RME-REVISION-PROGRAM-8B / D1 — the clinical DOCUMENT read scope:
     * the front desk and the cashier read only their working branch, everyone
     * else keeps the RME-enabled set (RmeWorkingBranchScope::allowsClinicalDocument()).
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

    /**
     * DMS-RME-REVISION-PROGRAM-8A / P8-N1 — the WRITE abilities follow the
     * clinical authoring authority (rule 223 §3, rule 226): never the
     * front-desk-only tier, and only inside the actor's working branch. The read
     * abilities above keep their existing scope (P8-N2 is an owner decision).
     */
    private function mayAuthorAt(User $user, ?int $branchId): bool
    {
        return app(PrescriptionAuthoringAuthority::class)->permitsActorAtBranch($user, $branchId);
    }

    private function belongsToActiveBranch(?int $branchId): bool
    {
        if ($branchId === null) {
            return false;
        }

        return in_array($branchId, app(BranchService::class)->rmeEnabledIds(), true);
    }
}
