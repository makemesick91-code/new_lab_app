<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Policies;

use App\Models\User;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramWorkspaceScope;

/**
 * FIX-04b — authorization for legacy odontogram STAGING rows.
 *
 * Fail closed:
 *  - every ability requires an explicit named permission;
 *  - every per-row ability additionally requires the row's origin branch to be
 *    inside the caller's server-resolved scope (an unresolvable scope denies
 *    everything);
 *  - no before() hook — the single global Gate::before in
 *    RepositoryServiceProvider is the only Super Admin bypass, and a second one
 *    here would shadow it;
 *  - the sidebar and the UI are never the security boundary.
 *
 * Reviewing and publishing carry SEPARATE named permissions so the two duties
 * can be split between people, and each is additionally gated on the status map
 * so a policy can never authorize an impossible transition.
 */
class LegacyOdontogramImportPolicy
{
    public function __construct(
        private readonly LegacyOdontogramWorkspaceScope $scope,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can('view_legacy_odontogram_imports');
    }

    public function view(User $user, LegacyOdontogramImport $import): bool
    {
        return $this->viewAny($user) && $this->inScope($user, $import);
    }

    public function create(User $user): bool
    {
        return $user->can('create_legacy_odontogram_imports');
    }

    public function review(User $user, LegacyOdontogramImport $import): bool
    {
        return $user->can('review_legacy_odontogram_imports')
            && $this->inScope($user, $import)
            && $import->canTransitionTo(LegacyOdontogramImportStatus::REVIEWED);
    }

    public function publish(User $user, LegacyOdontogramImport $import): bool
    {
        return $user->can('publish_legacy_odontogram_imports')
            && $this->inScope($user, $import)
            && $import->canTransitionTo(LegacyOdontogramImportStatus::PUBLISHED);
    }

    /**
     * Cancelling a staged import. A published import is terminal and must be
     * corrected through a VOID on the produced record instead.
     */
    public function cancel(User $user, LegacyOdontogramImport $import): bool
    {
        return $user->can('create_legacy_odontogram_imports')
            && $this->inScope($user, $import)
            && $import->canTransitionTo(LegacyOdontogramImportStatus::CANCELLED);
    }

    public function retry(User $user, LegacyOdontogramImport $import): bool
    {
        return $user->can('create_legacy_odontogram_imports')
            && $this->inScope($user, $import)
            && (
                $import->canTransitionTo(LegacyOdontogramImportStatus::QUEUED)
                || $import->status === LegacyOdontogramImportStatus::PROCESSING
            );
    }

    /**
     * Streaming the private source PDF or a rendered staging page.
     *
     * Deliberately the same boundary as `view`: reading the archive bytes is
     * exactly as sensitive as reading the row, and the file route must never be
     * a weaker door than the detail page.
     */
    public function viewFile(User $user, LegacyOdontogramImport $import): bool
    {
        return $this->view($user, $import);
    }

    private function inScope(User $user, LegacyOdontogramImport $import): bool
    {
        return $this->scope->allows(
            $user,
            $import->origin_branch_id !== null ? (int) $import->origin_branch_id : null,
        );
    }
}
