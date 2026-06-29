<?php

namespace App\Modules\Odontogram\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RME\Services\DoctorPatientScopeService;
use Illuminate\Auth\Access\Response;

class OdontogramPolicy
{
    public function view(User $user, Odontogram $odontogram): Response|bool
    {
        if (! $this->canView($user) || ! $this->belongsToActiveBranch($odontogram->branch_id)) {
            return false;
        }

        return $this->authorizePatientForOdontogram($user, $odontogram);
    }

    public function print(User $user, Odontogram $odontogram): Response|bool
    {
        if (! $this->canView($user) || ! $this->belongsToActiveBranch($odontogram->branch_id)) {
            return false;
        }

        return $this->authorizePatientForOdontogram($user, $odontogram);
    }

    public function create(User $user, ClinicVisit $clinicVisit): Response|bool
    {
        if (! $this->canView($user) || ! $this->belongsToActiveBranch($clinicVisit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $clinicVisit);
    }

    public function update(User $user, Odontogram $odontogram): Response|bool
    {
        if (! $this->canManage($user) || ! $this->belongsToActiveBranch($odontogram->branch_id)) {
            return false;
        }

        return $this->authorizePatientForOdontogram($user, $odontogram);
    }

    public function finalize(User $user, Odontogram $odontogram): Response|bool
    {
        if (! $this->canManage($user) || ! $this->belongsToActiveBranch($odontogram->branch_id)) {
            return false;
        }

        return $this->authorizePatientForOdontogram($user, $odontogram);
    }

    private function authorizePatientForOdontogram(User $user, Odontogram $odontogram): Response|bool
    {
        $visit = $odontogram->clinicVisit;

        if ($visit !== null) {
            return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $visit);
        }

        $patient = $odontogram->patient;

        if ($patient !== null) {
            return app(DoctorPatientScopeService::class)->authorizePatientAccess($user, $patient);
        }

        return false;
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
