<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Branch\Services\BranchService;

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
