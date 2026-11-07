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
 * DMS-RME-REVISION-PROGRAM-8B / D7 (P8R-S1, P8R-S2) adds two parts, so the
 * predicate no longer leans on the route and the policy for anything:
 *
 *  0a. The actor holds the clinical write permission `manage_clinic_visits`
 *      itself. Before this, a caller that skipped the route and the policy (a
 *      job, a command, a future controller) was refused only for the reasons
 *      above, and rule 226 §1.3's "refused the same way" overstated it.
 *  0b. The actor holds a CLINICAL role ({@see self::AUTHORING_ROLES}). This is an
 *      ALLOWLIST, deliberately: the front-desk refusal (part 1) is a denylist of
 *      two named roles, so any OTHER role that is ever given
 *      `manage_clinic_visits` — through RoleSeeder or through the role
 *      management screen at runtime — would otherwise inherit prescription
 *      authoring without anyone deciding it. Production already holds such a
 *      role (manually created, permission granted, no holders today); a CI test
 *      over RoleSeeder cannot see a runtime role, so only an allowlist closes
 *      it. A new role that must write prescriptions is added here, on purpose,
 *      with its own review. The allowlist subsumes part 1 for every current
 *      role; part 1 stays as the named, canonical front-desk refusal.
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

    /** The clinical write permission every author must hold itself (part 0a). */
    public const WRITE_PERMISSION = 'manage_clinic_visits';

    /**
     * Part 0b — the only roles whose holders may author a prescription. The same
     * clinical tier {@see FrontOfficeRole::CLINICAL_ROLES} names, so "who is
     * clinical" has one definition. Pinned by
     * tests/Feature/RME/RmePrescriptionAuthoringRoleMatrixTest.php.
     */
    public const AUTHORING_ROLES = FrontOfficeRole::CLINICAL_ROLES;

    public function __construct(
        private readonly RmeWorkingBranchScope $workingBranch,
        private readonly DoctorPatientScopeService $doctorScope,
        private readonly ActiveAccountResolver $accounts,
    ) {}

    /**
     * Parts 0a, 0b, 1 and 2: the write permission, a clinical role, not the
     * front-desk-only tier, and inside the actor's working branch scope.
     */
    public function permitsActorAtBranch(User $actor, ?int $branchId): bool
    {
        // `can()` goes through the Gate, so Super Admin's Gate::before answers
        // here exactly as it does on the route.
        if (! $actor->can(self::WRITE_PERMISSION)) {
            return false;
        }

        if (FrontOfficeRole::isVisitFrontDeskOnly($actor)) {
            return false;
        }

        if (! $actor->hasAnyRole(self::AUTHORING_ROLES)) {
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
