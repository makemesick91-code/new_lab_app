<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Doctor\Services\DoctorClinicalBranchResolver;

/**
 * LEGACY-RME-PDF-1A — server-side branch scope for the legacy RME archive.
 *
 * Mirrors the SATUSEHAT-4A workspace scope: a governance-tier holder sees every
 * RME-enabled branch; anyone else is pinned to their resolved BranchContext
 * branch. The branch is NEVER read from the request, and an unresolvable branch
 * yields an empty list — fail closed, deny everything.
 *
 * `origin_branch_id` on a legacy row is provenance metadata about where the
 * paper archive came from; it is never an authorization bypass.
 *
 * LEGACY-RME-PDF-HISTORY-1B — A DOCTOR IS SCOPED BY PRACTICE, NOT BY SESSION.
 *
 * A doctor is not an operator sitting at one clinic desk: they are multi-branch
 * by design, and `mst_doctor_branches` is the system's own source of truth for
 * where they may practise. Resolving them through `BranchContext` asked the
 * wrong question and produced two failures — an expired or absent online
 * session fell back to MAIN (a branch the doctor has no relationship with, and
 * never RME-enabled, so every read was refused), and a doctor standing in one
 * of their branches could not read a patient's archive that originated in
 * another of their own branches.
 *
 * Doctors are therefore resolved through DoctorClinicalBranchResolver, which is
 * deterministic, session-independent and fail-closed. This is NOT a relaxation:
 * the doctor's set is bounded by their assigned practice branches intersected
 * with the active RME-enabled branches, and the treating-relationship gate
 * (DoctorPatientScopeService, enforced in LegacyRmeRecordPolicy and in the
 * history service) still applies on top of it. Branch membership alone has
 * never authorized a read and still does not.
 */
class LegacyRmeWorkspaceScope
{
    /**
     * Holding any of these means the operator governs the legacy archive across
     * the whole RME branch set rather than a single clinic.
     *
     * @var list<string>
     */
    public const GOVERNANCE_PERMISSIONS = [
        'review_legacy_rme_imports',
        'publish_legacy_rme_imports',
        'void_legacy_rme_imports',
    ];

    public function __construct(
        private readonly BranchService $branches,
        private readonly BranchContext $context,
        private readonly DoctorClinicalBranchResolver $doctorBranches,
    ) {}

    /**
     * @return list<int>
     */
    public function branchIdsFor(User $user): array
    {
        $all = $this->branches->rmeEnabledIds();

        if ($user->canAny(self::GOVERNANCE_PERMISSIONS)) {
            return $all;
        }

        // HISTORY-1B: a doctor's branch authority is their assigned practice
        // set, never the single branch of a session that may not exist. Empty
        // (no doctor master link, inactive master, no practice branch) denies.
        if ($this->doctorBranches->appliesTo($user)) {
            return $this->doctorBranches->branchIdsFor($user);
        }

        $own = $this->context->forUser($user);

        return $own !== null && in_array((int) $own, $all, true) ? [(int) $own] : [];
    }

    /**
     * Rows with no origin branch carry no provenance and are therefore only
     * visible to the governance tier.
     */
    public function includesUnscopedRowsFor(User $user): bool
    {
        return $user->canAny(self::GOVERNANCE_PERMISSIONS);
    }

    public function allows(User $user, ?int $branchId): bool
    {
        if ($branchId === null) {
            return $this->includesUnscopedRowsFor($user);
        }

        return in_array((int) $branchId, $this->branchIdsFor($user), true);
    }
}
