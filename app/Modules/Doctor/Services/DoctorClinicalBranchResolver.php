<?php

declare(strict_types=1);

namespace App\Modules\Doctor\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RmeOnlineContext\Services\DoctorUserResolver;

/**
 * LEGACY-RME-PDF-HISTORY-1B — the canonical clinical BRANCH context of a doctor.
 *
 * WHY THIS EXISTS.
 *
 * `BranchContext::forUser()` answers a different question: "which single branch
 * is this operator working in right now?". For a doctor that is the branch they
 * selected in the Sprint 66 online context, and when no context is online it
 * falls through to `users.branch_id`, then the user `branches()` relation, and
 * finally to the MAIN branch. Two things follow from that, and both of them are
 * wrong for deciding which branches' clinical evidence a doctor may read:
 *
 *  1. IT IS EPHEMERAL. A doctor whose online session has expired — or any code
 *     path with no session at all, such as an artisan probe — resolves to MAIN,
 *     a branch the doctor has no clinical relationship with whatsoever. MAIN is
 *     never RME-enabled, so the result is an EMPTY scope and the doctor is
 *     refused evidence they are entitled to. That denial is accidental rather
 *     than designed: it depends on MAIN's module flags, not on the doctor.
 *
 *  2. IT IS NARROWER THAN THE DOCTOR'S REAL AUTHORITY. Doctors in this system
 *     are multi-branch. Sprint 66.1.1 introduced `mst_doctor_branches` and its
 *     model relation is documented as the "allowed RME practice branches
 *     (source of truth for online context)". The online branch is only TODAY'S
 *     SELECTION out of that set — `UserOnlineContextService::startDoctorSession`
 *     refuses any branch that is not in it ("Cabang yang dipilih tidak termasuk
 *     Cabang Praktik yang Diizinkan"). Pinning clinical reads to the selection
 *     means a doctor who practises at both TKM1 and LDK2, is standing at TKM1
 *     today, and is genuinely treating this patient, cannot read that patient's
 *     LDK2 archive.
 *
 * So the canonical clinical branch context of a doctor is the SET the system
 * already treats as authoritative:
 *
 *     mst_doctor_branches (Cabang Praktik yang Diizinkan)
 *         ∩ active, RME-enabled branches
 *
 * FAIL CLOSED, ALWAYS. Anything unresolvable yields an EMPTY list, never a
 * fallback: no doctor master link, an inactive doctor master, or no practice
 * branch at all. An empty list denies everything. There is deliberately no
 * fallback to `BranchContext`, to `users.branch_id`, to the patient's branch,
 * to MAIN, or to "all branches" — a fallback is exactly how a null context
 * would silently become global read.
 *
 * THE ONLINE CONTEXT IS NEVER A WIDENER. A stale `trx_user_online_contexts`
 * row pointing at a branch that has since been removed from the doctor's
 * practice set confers nothing here: the pivot is the authority, so revoking a
 * practice branch revokes the reads with it.
 *
 * THIS IS NOT AUTHORIZATION ON ITS OWN. It answers "WHICH BRANCHES", never
 * "WHICH PATIENTS". A doctor still reaches an individual patient only through
 * `DoctorPatientScopeService` (an active patient-doctor assignment, or a visit
 * with that doctor). Both gates apply, so a same-branch doctor with no clinical
 * relationship is still refused, and a treating doctor is still refused outside
 * their practice branches.
 *
 * Consequently a doctor's legacy reach stays strictly NARROWER than their
 * native reach: the native record is scoped by the clinical relationship alone
 * across the RME branch set, while the archive additionally requires the origin
 * branch to be one the doctor actually practises in.
 */
class DoctorClinicalBranchResolver
{
    public function __construct(
        private readonly DoctorPatientScopeService $doctorScope,
        private readonly DoctorUserResolver $doctors,
        private readonly BranchService $branches,
    ) {}

    /**
     * Whether this user acts as a DOCTOR and must therefore be scoped by their
     * practice branches rather than by an operator's single working branch.
     *
     * The canonical predicate is reused verbatim from the native scope service
     * (which also exempts Owner / Super Admin / Supervisor RME) so the two can
     * never drift into disagreeing about who counts as a doctor.
     */
    public function appliesTo(User $user): bool
    {
        return $this->doctorScope->shouldApplyDoctorScope($user);
    }

    /**
     * The doctor's canonical clinical branch set. EMPTY means deny.
     *
     * @return list<int>
     */
    public function branchIdsFor(User $user): array
    {
        $doctor = $this->doctors->resolveForUser($user);

        if (! $doctor instanceof Doctor || ! $doctor->is_active) {
            return [];
        }

        $rmeEnabled = $this->branches->rmeEnabledIds();

        if ($rmeEnabled === []) {
            return [];
        }

        $practice = $doctor->branches()
            ->pluck('mst_branches.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        // Intersect rather than trust the pivot: a branch that has been
        // deactivated or had RME switched off is no longer a place this doctor
        // practises, even while the assignment row survives.
        return array_values(array_unique(array_filter(
            $practice,
            static fn (int $branchId): bool => in_array($branchId, $rmeEnabled, true),
        )));
    }
}
