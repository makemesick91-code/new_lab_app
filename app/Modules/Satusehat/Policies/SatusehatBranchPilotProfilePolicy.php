<?php

namespace App\Modules\Satusehat\Policies;

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatBranchPilotProfile;
use App\Modules\Satusehat\Support\SatusehatWorkspaceScope;

/**
 * SATUSEHAT-4C — branch readiness / internal pilot authorization.
 *
 * Read is gated by the readiness/metrics permissions; write actions each map to
 * a dedicated least-privilege permission. Every per-record check re-asserts the
 * branch is inside the actor's server-side scope (executive → all RME-enabled
 * branches; Admin Klinik → pinned BranchContext branch) so a forged branch id
 * can never cross a branch boundary. Super Admin passes via Gate::before.
 */
class SatusehatBranchPilotProfilePolicy
{
    public function __construct(
        private readonly SatusehatWorkspaceScope $scope,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->canAny([
            'view_satusehat_branch_readiness',
            'view_satusehat_pilot_metrics',
            'manage_satusehat_branch_remediation',
        ]);
    }

    public function view(User $user, SatusehatBranchPilotProfile $profile): bool
    {
        return $this->viewAny($user) && $this->inScope($user, $profile);
    }

    public function recalculate(User $user, SatusehatBranchPilotProfile $profile): bool
    {
        return $user->can('manage_satusehat_branch_remediation') && $this->inScope($user, $profile);
    }

    public function configure(User $user, SatusehatBranchPilotProfile $profile): bool
    {
        return $user->can('configure_satusehat_internal_pilot') && $this->inScope($user, $profile);
    }

    public function approve(User $user, SatusehatBranchPilotProfile $profile): bool
    {
        return $user->can('approve_satusehat_internal_pilot') && $this->inScope($user, $profile);
    }

    public function rehearse(User $user, SatusehatBranchPilotProfile $profile): bool
    {
        return $user->can('run_satusehat_pilot_rehearsal') && $this->inScope($user, $profile);
    }

    public function remediate(User $user, SatusehatBranchPilotProfile $profile): bool
    {
        return $user->can('manage_satusehat_branch_remediation') && $this->inScope($user, $profile);
    }

    private function inScope(User $user, SatusehatBranchPilotProfile $profile): bool
    {
        return in_array((int) $profile->branch_id, $this->scope->branchIdsFor($user), true);
    }
}
