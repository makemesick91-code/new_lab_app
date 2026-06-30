<?php

namespace App\Modules\ClinicVisit\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RME\Services\DoctorPatientScopeService;
use Illuminate\Auth\Access\Response;

class ClinicVisitPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $this->canView($user) || ! $this->belongsToActiveRmeBranch($visit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeSpecificVisitAccess($user, $visit);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $this->canManage($user) || ! $this->belongsToActiveRmeBranch($visit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeSpecificVisitAccess($user, $visit);
    }

    public function transition(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $this->canManage($user) || ! $this->belongsToActiveRmeBranch($visit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeSpecificVisitAccess($user, $visit);
    }

    public function print(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $this->canView($user) || ! $this->belongsToActiveRmeBranch($visit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeSpecificVisitAccess($user, $visit);
    }

    private function canView(User $user): bool
    {
        return $user->canAny(['view_clinic_visits', 'manage_clinic_visits']);
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage_clinic_visits');
    }

    private function belongsToActiveRmeBranch(?int $branchId): bool
    {
        if ($branchId === null) {
            return false;
        }

        return in_array($branchId, app(BranchService::class)->rmeEnabledIds(), true);
    }
}
