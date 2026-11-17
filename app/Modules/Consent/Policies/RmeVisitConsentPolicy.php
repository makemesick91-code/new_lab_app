<?php

namespace App\Modules\Consent\Policies;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RME\Services\DoctorRoomScopeService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Validation\ValidationException;

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
     * Rule 243 §1 (owner revision 2026-11-17, memo M2.4) — the PTM print. A
     * Doctor reads the signed consent on screen (`view`) but never prints it;
     * the refusal comes from the one print-deny authority (rule 138), which
     * also hides the print control under `@can('print', …)`. Every other
     * reader keeps exactly the `view` boundary.
     */
    public function print(User $user, RmeVisitConsent $consent): Response|bool
    {
        $print = app(DoctorRoomScopeService::class)->authorizeClinicalPrint($user);

        if ($print !== true) {
            return $print;
        }

        return $this->view($user, $consent);
    }

    /**
     * Capturing a signature. Note this is an authorisation question only —
     * WHEN a consent may be signed is a business rule and lives in
     * RmeVisitConsentService::assertSignable(), so it is enforced even for
     * callers that never touch this policy: the visit must be in_progress (the
     * examination has started), so consent is taken at the start of the
     * examination rather than on the way to the cashier.
     */
    public function create(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $user->can('manage_rme_consents') || ! $this->withinWorkingBranchScope($user, $visit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $visit);
    }

    /**
     * Voiding is the correction path for signed evidence. DMS-RME-COMPREHENSIVE-
     * CLOSURE-1 (OD-5-8, owner decision 2026-11-16): a SUPERVISED act behind its
     * own permission (`void_rme_consents`, granted to Supervisor RME), never the
     * signing permission; only while the examination runs. Maker ≠ checker is an
     * identity rule and lives in the service, where Gate::before cannot skip it.
     */
    public function void(User $user, RmeVisitConsent $consent): Response|bool
    {
        if (! $user->can(RmeVisitConsentService::VOID_PERMISSION) || ! $this->withinWorkingBranchScope($user, $consent->branch_id)) {
            return false;
        }

        if ($consent->isVoided()) {
            return false;
        }

        $visit = $consent->clinicVisit;

        if ($visit === null || $visit->status !== ClinicVisit::STATUS_IN_PROGRESS) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $visit);
    }

    /**
     * DMS memo M1.1–M1.3, PR1 (rule 241 §1) — the treating doctor's
     * countersignature: its own permission (`countersign_rme_consents`, Doctor),
     * branch and patient scope, a live consent on an `in_progress` visit, and the
     * doctor identity. Gate::before answers this for Super Admin without reaching
     * here, so RmeVisitConsentService::countersign() decides every condition
     * again under the visit lock — that is what binds Super Admin.
     */
    public function countersign(User $user, RmeVisitConsent $consent): Response|bool
    {
        if (! $user->can(RmeVisitConsentService::COUNTERSIGN_PERMISSION) || ! $this->withinWorkingBranchScope($user, $consent->branch_id)) {
            return false;
        }

        if ($consent->isVoided()) {
            return false;
        }

        $visit = $consent->clinicVisit;

        if ($visit === null || $visit->status !== ClinicVisit::STATUS_IN_PROGRESS) {
            return false;
        }

        $scope = app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $visit);

        if ($scope === false || ($scope instanceof Response && $scope->denied())) {
            return $scope;
        }

        // Identity and encounter authority, the service's own decision (no lock
        // here). The service runs every authorization guard (permission, scope,
        // encounter, doctor identity) BEFORE any state guard, so a
        // ValidationException can only reach an account that passed all of
        // them: a state refusal (already countersigned, maker = checker) is left
        // to the service so the operator sees why.
        try {
            app(RmeVisitConsentService::class)->assertCountersignable($consent, $user);
        } catch (AuthorizationException) {
            return false;
        } catch (ValidationException) {
            return true;
        }

        return true;
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
