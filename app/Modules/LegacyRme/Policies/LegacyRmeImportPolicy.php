<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Policies;

use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;

/**
 * LEGACY-RME-PDF-1A — authorization for legacy RME staging rows.
 *
 * Fail-closed, following the SATUSEHAT-4C policy shape:
 *  - every ability requires an explicit named permission;
 *  - every per-row ability additionally requires the row's origin branch to be
 *    inside the caller's server-resolved scope (an unresolvable scope denies
 *    everything);
 *  - no before() hook — the single global Gate::before in
 *    RepositoryServiceProvider is the only Super Admin bypass;
 *  - the sidebar and the UI are never the security boundary.
 */
class LegacyRmeImportPolicy
{
    public function __construct(
        private readonly LegacyRmeWorkspaceScope $scope,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can('view_legacy_rme_imports');
    }

    public function view(User $user, LegacyRmeImport $import): bool
    {
        return $this->viewAny($user) && $this->inScope($user, $import);
    }

    public function create(User $user): bool
    {
        return $user->can('create_legacy_rme_imports');
    }

    /**
     * Reviewing a staged import (checking the pages and the chosen date).
     */
    public function review(User $user, LegacyRmeImport $import): bool
    {
        return $user->can('review_legacy_rme_imports')
            && $this->inScope($user, $import)
            && ! LegacyRmeImportStatus::isTerminal($import->status);
    }

    /**
     * Publishing turns a staged import into an immutable legacy record. It is
     * only reachable from a reviewed, non-terminal import.
     */
    public function publish(User $user, LegacyRmeImport $import): bool
    {
        return $user->can('publish_legacy_rme_imports')
            && $this->inScope($user, $import)
            && $import->canTransitionTo(LegacyRmeImportStatus::PUBLISHED);
    }

    /**
     * Cancelling a staged import. A published import is terminal and must be
     * corrected through a VOID on the produced record instead.
     */
    public function cancel(User $user, LegacyRmeImport $import): bool
    {
        return $user->can('create_legacy_rme_imports')
            && $this->inScope($user, $import)
            && $import->canTransitionTo(LegacyRmeImportStatus::CANCELLED);
    }

    private function inScope(User $user, LegacyRmeImport $import): bool
    {
        return $this->scope->allows(
            $user,
            $import->origin_branch_id !== null ? (int) $import->origin_branch_id : null,
        );
    }
}
