<?php

namespace App\Modules\Consent\Policies;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Illuminate\Auth\Access\Response;

/**
 * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01.
 *
 * Consent is bound to one visit at one branch. A consent signed for patient A
 * at branch X must never be reachable — let alone usable — from branch Y, so
 * every ability here is branch-scoped through the same RmeWorkingBranchScope
 * the clinic and cashier surfaces already use, and patient access goes through
 * the same DoctorPatientScopeService as the rest of RME.
 */
class RmeVisitConsentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_rme_consents');
    }

    public function view(User $user, RmeVisitConsent $consent): Response|bool
    {
        if (! $user->can('view_rme_consents') || ! $this->withinWorkingBranchScope($user, $consent->branch_id)) {
            return false;
        }

        $visit = $consent->clinicVisit;

        if ($visit === null) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $visit);
    }

    /**
     * Capturing a signature. Note this is an authorisation question only —
     * WHEN a consent may be signed is a business rule and lives in
     * RmeVisitConsentService::assertSignable(), so it is enforced even for
     * callers that never touch this policy. Since
     * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-02 that window is every
     * non-terminal visit, so consent is taken at the start of the examination
     * rather than on the way to the cashier.
     */
    public function create(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $user->can('manage_rme_consents') || ! $this->withinWorkingBranchScope($user, $visit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $visit);
    }

    /**
     * Voiding is the correction path for signed evidence, so it is deliberately
     * held to the same authority as signing.
     */
    public function void(User $user, RmeVisitConsent $consent): Response|bool
    {
        if (! $user->can('manage_rme_consents') || ! $this->withinWorkingBranchScope($user, $consent->branch_id)) {
            return false;
        }

        if ($consent->isVoided()) {
            return false;
        }

        $visit = $consent->clinicVisit;

        if ($visit === null) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $visit);
    }

    /**
     * Signed consent is immutable evidence. There is no edit path at all, and
     * saying so explicitly keeps a future `authorize('update', ...)` from
     * silently falling through to a permissive default.
     */
    public function update(User $user, RmeVisitConsent $consent): bool
    {
        return false;
    }

    public function delete(User $user, RmeVisitConsent $consent): bool
    {
        return false;
    }

    private function withinWorkingBranchScope(User $user, ?int $branchId): bool
    {
        return app(RmeWorkingBranchScope::class)->allows($user, $branchId);
    }
}
