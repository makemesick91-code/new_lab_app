<?php

namespace App\Modules\Prescription\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\RME\Services\DoctorPatientScopeService;
use Illuminate\Auth\Access\Response;

class RmePrescriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, RmePrescription $prescription): Response|bool
    {
        if (! $this->canView($user) || ! $this->belongsToActiveBranch($prescription->branch_id)) {
            return false;
        }

        $visit = $prescription->clinicVisit;

        return $visit !== null
            ? app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $visit)
            : false;
    }

    public function print(User $user, RmePrescription $prescription): Response|bool
    {
        return $this->view($user, $prescription);
    }

    public function viewForVisit(User $user, ClinicVisit $clinicVisit): Response|bool
    {
        if (! $this->canView($user) || ! $this->belongsToActiveBranch($clinicVisit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $clinicVisit);
    }

    public function create(User $user, ClinicVisit $clinicVisit): Response|bool
    {
        if (! $this->canManage($user) || ! $this->belongsToActiveBranch($clinicVisit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $clinicVisit);
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
        if (! $this->canManage($user) || ! $this->belongsToActiveBranch($prescription->branch_id)) {
            return false;
        }

        return $this->view($user, $prescription);
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
