<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Support;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Doctor\Services\DoctorClinicalBranchResolver;

/**
 * FIX-04b — server-side branch scope for the legacy odontogram archive.
 *
 * A DEDICATED SCOPE, NOT A REUSE. LegacyRmeWorkspaceScope::GOVERNANCE_PERMISSIONS
 * is a hard-coded list of the three legacy RME intake permissions. Adding this
 * capability's permissions to that constant would silently widen legacy RME
 * archive visibility to every RME branch for anyone holding an odontogram
 * permission — a capability they were never granted. The two scopes have the
 * same SHAPE on purpose and completely separate MEMBERSHIP.
 *
 * The branch is NEVER read from the request. Either the caller governs the
 * archive across the RME branch set, or they are pinned to the branches the
 * system already knows they work in — and an unresolvable scope yields an empty
 * list, which every repository turns into "deny everything" rather than
 * "no filter".
 *
 * `origin_branch_id` on a legacy row is derived from the patient's Nomor RM and
 * IS the authorization anchor here; it is never operator-supplied, which is
 * exactly what makes it safe to scope on.
 */
class LegacyOdontogramWorkspaceScope
{
    /**
     * Holding any of these means the operator governs the odontogram archive
     * across the whole RME branch set rather than a single clinic.
     *
     * The read-only permission `view_legacy_odontogram_archive` is deliberately
     * ABSENT: a clinical reader stays pinned to their own branches, so granting
     * a doctor read access can never widen them to every branch's archive.
     *
     * @var list<string>
     */
    public const GOVERNANCE_PERMISSIONS = [
        'create_legacy_odontogram_imports',
        'review_legacy_odontogram_imports',
        'publish_legacy_odontogram_imports',
        'void_legacy_odontogram_records',
    ];

    public function __construct(
        private readonly BranchService $branches,
        private readonly BranchContext $context,
        private readonly DoctorClinicalBranchResolver $doctorBranches,
    ) {}

    /**
     * @return list<int>
     */
    public function branchIdsFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $all = $this->branches->rmeEnabledIds();

        if ($user->canAny(self::GOVERNANCE_PERMISSIONS)) {
            return $all;
        }

        // A doctor is multi-branch by design, and their practice set — not a
        // single online session — is the system's own statement of where they
        // work. Resolving them through BranchContext would fall back to MAIN
        // when no session is active and refuse every read. Empty (no doctor
        // master link, inactive master, no practice branch) still denies.
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
    public function includesUnscopedRowsFor(?User $user): bool
    {
        return $user !== null && $user->canAny(self::GOVERNANCE_PERMISSIONS);
    }

    public function allows(?User $user, ?int $branchId): bool
    {
        if ($branchId === null) {
            return $this->includesUnscopedRowsFor($user);
        }

        return in_array((int) $branchId, $this->branchIdsFor($user), true);
    }
}
