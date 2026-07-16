<?php

namespace App\Modules\Satusehat\Support;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Branch\Services\BranchService;

/**
 * SATUSEHAT-4A — server-side branch scope for the readiness workspace.
 *
 * Executive / RME-operational tier (holders of any SATUSEHAT submission
 * governance permission — Owner, Supervisor RME, Super Admin via Gate::before)
 * sees every RME-enabled branch, matching the SATUSEHAT-1 submissions
 * workspace. Branch operators (Admin Klinik tier) are pinned to their resolved
 * BranchContext branch — never the request, fail-closed when unresolvable.
 */
class SatusehatWorkspaceScope
{
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

        if ($user->canAny([
            'view_satusehat_submissions',
            'review_satusehat_submissions',
            'send_satusehat_submissions',
            'manage_satusehat_mappings',
        ])) {
            return $all;
        }

        $own = $this->context->forUser($user);

        return $own !== null && in_array((int) $own, $all, true) ? [(int) $own] : [];
    }
}
