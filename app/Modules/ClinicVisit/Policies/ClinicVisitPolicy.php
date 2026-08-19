<?php

namespace App\Modules\ClinicVisit\Policies;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Illuminate\Auth\Access\Response;

class ClinicVisitPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $this->canView($user) || ! $this->withinWorkingBranchScope($user, $visit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $visit);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $this->canManage($user) || ! $this->withinWorkingBranchScope($user, $visit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $visit);
    }

    public function transition(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $this->canManage($user) || ! $this->withinWorkingBranchScope($user, $visit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $visit);
    }

    public function print(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $this->canView($user) || ! $this->withinWorkingBranchScope($user, $visit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $visit);
    }

    /**
     * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-05) — "Selesai Pemeriksaan".
     *
     * Closing a doctor's examination and handing the patient to the cashier is a
     * clinical act, so it is gated by its own `complete_rme_examination`
     * permission (Doctor, Perawat, Supervisor RME; Super Admin via Gate::before)
     * instead of the broad `manage_clinic_visits` the front office also holds.
     * Admin Klinik therefore keeps registration and room placement but can never
     * mark an examination finished — by button, by direct POST or by any other
     * caller. The cashier-owned `completed` transition is untouched: it still
     * happens only in RmePaymentService once the invoice is settled.
     */
    public function completeExamination(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $user->can('complete_rme_examination') || ! $this->withinWorkingBranchScope($user, $visit->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->authorizeVisitAccess($user, $visit);
    }

    /**
     * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-07) — may this user drive the
     * action controls on the visit DETAIL page?
     *
     * Admin Klinik operates the queue from Antrian Pasien (registration and room
     * placement) and its visit-detail page is deliberately read-only plus
     * "Cetak RME". Every other role keeps exactly what it had. This is a
     * presentation capability, so it is expressed once here rather than as a
     * role literal scattered through Blade; the security-critical actions are
     * additionally enforced by their own abilities.
     */
    public function operateFromDetail(User $user, ClinicVisit $visit): bool
    {
        if ($this->isFrontOfficeOnly($user)) {
            return false;
        }

        // Deliberately NOT gated on manage_clinic_visits: this ability only
        // answers "is this the read-only front office?". What each control
        // actually requires is decided by its own @can guard and its own
        // server-side authorisation, so a legitimate viewer (e.g. a
        // prescription viewer holding only view_clinic_visits) keeps exactly
        // the access it had before.
        return $this->canView($user) && $this->withinWorkingBranchScope($user, $visit->branch_id);
    }

    /**
     * A user whose only clinic-visit authority comes from the Admin Klinik
     * front-office role. Holding a clinical role as well keeps full access.
     */
    private function isFrontOfficeOnly(User $user): bool
    {
        return $user->hasRole('Admin Klinik')
            && ! $user->hasAnyRole(['Doctor', 'Perawat', 'Supervisor RME', 'Super Admin']);
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
     * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 — the server-side branch boundary.
     *
     * Delegated to the canonical {@see RmeWorkingBranchScope}: a context-bound
     * operational role (Admin Klinik, Perawat, Kasir) may only reach records of
     * the branch it is currently working in, and fails closed when it has no
     * valid working context. Governance/cross-branch roles and the Doctor
     * clinical branch model keep the full active RME-enabled set, so this is a
     * narrowing of the previous rule, never a widening. Enforced here rather
     * than in the view, so a crafted URL or direct request is denied too.
     */
    private function withinWorkingBranchScope(User $user, ?int $branchId): bool
    {
        return app(RmeWorkingBranchScope::class)->allows($user, $branchId);
    }
}
