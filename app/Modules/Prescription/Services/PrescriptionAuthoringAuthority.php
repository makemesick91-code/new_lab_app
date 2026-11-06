<?php

namespace App\Modules\Prescription\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Support\AccessControl\ActiveAccountResolver;
use App\Support\AccessControl\FrontOfficeRole;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * DMS-RME-REVISION-PROGRAM-8A / P8-N1 — who may WRITE a prescription.
 *
 * A prescription is a clinical document printed under the visit doctor's name
 * with a drawn doctor signature, so it follows the authoring authority the rest
 * of the clinical record already follows (rule 223 §3, rule 226):
 *
 *  1. The front-desk-only tier never authors it. {@see FrontOfficeRole::isVisitFrontDeskOnly()}
 *     is the single definition of that tier; `manage_clinic_visits` is the
 *     registration permission the front office needs, so holding it can never
 *     be what makes a prescription writable.
 *  2. A write is scoped to the actor's WORKING branch through the canonical
 *     {@see RmeWorkingBranchScope::allows()} — the same per-record guard the
 *     visit's own write authority uses. A context-bound role (Perawat, Admin
 *     Klinik, Kasir) writes only in the branch it is working in and fails closed
 *     without a context; every other actor, Doctors included, keeps the full
 *     active RME set (this per-record guard deliberately ignores the doctor
 *     branch lock, as ClinicVisitPolicy does), so this only ever narrows.
 *  3. The doctor-patient scope still binds a Doctor.
 *
 * This mirrors `RmeVisitConsentService::actorMayWorkOnEncounter()` on purpose
 * and deliberately does NOT add what that method's callers add (a live,
 * consented encounter): prescriptions have no consent prerequisite and no room
 * rule, and both would be new product policy (OD-7-2), not this fix.
 *
 * The policy asks {@see permitsActorAtBranch()} and then the doctor-patient
 * scope itself (it carries its own deny message); the service re-asserts the
 * whole predicate through {@see assertPermits()} (CG-06), so a caller that never
 * passes through the policy is refused the same way. Super Admin's
 * `Gate::before` bypasses only the policy; it holds a clinical role and full
 * branch scope, so the service re-assertion admits it too.
 */
final class PrescriptionAuthoringAuthority
{
    public const REFUSAL_MESSAGE = 'Akun ini tidak berwenang menulis atau mengubah resep dokter pada kunjungan ini.';

    public function __construct(
        private readonly RmeWorkingBranchScope $workingBranch,
        private readonly DoctorPatientScopeService $doctorScope,
        private readonly ActiveAccountResolver $accounts,
    ) {}

    /**
     * Parts 1 and 2: not the front-desk-only tier, and inside the actor's
     * working branch scope.
     */
    public function permitsActorAtBranch(User $actor, ?int $branchId): bool
    {
        if (FrontOfficeRole::isVisitFrontDeskOnly($actor)) {
            return false;
        }

        return $this->workingBranch->allows($actor, $branchId);
    }

    /**
     * The whole predicate: a usable (persisted, active, non-deleted) account,
     * parts 1 and 2, and the doctor-patient scope.
     */
    public function permits(User $actor, ?ClinicVisit $visit): bool
    {
        if ($visit === null || ! $this->accounts->isUsable($actor)) {
            return false;
        }

        return $this->permitsActorAtBranch($actor, $visit->branch_id)
            && $this->doctorScope->doctorCanAccessVisit($actor, $visit);
    }

    /**
     * @throws AuthorizationException
     */
    public function assertPermits(User $actor, ?ClinicVisit $visit): void
    {
        if (! $this->permits($actor, $visit)) {
            throw new AuthorizationException(self::REFUSAL_MESSAGE);
        }
    }
}
