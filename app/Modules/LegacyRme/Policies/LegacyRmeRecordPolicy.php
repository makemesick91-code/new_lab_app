<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Policies;

use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;

/**
 * LEGACY-RME-PDF-1A — authorization for published legacy RME records.
 *
 * A published record is immutable, so this policy exposes NO update and NO
 * delete ability at all: the only state change is VOID (with a reason), which
 * requires its own named permission.
 */
class LegacyRmeRecordPolicy
{
    public function __construct(
        private readonly LegacyRmeWorkspaceScope $scope,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can('view_legacy_rme_imports');
    }

    public function view(User $user, LegacyRmeRecord $record): bool
    {
        return $this->viewAny($user) && $this->inScope($user, $record);
    }

    public function void(User $user, LegacyRmeRecord $record): bool
    {
        return $user->can('void_legacy_rme_imports')
            && $this->inScope($user, $record)
            && LegacyRmeRecordStatus::canTransition($record->status, LegacyRmeRecordStatus::VOID);
    }

    /**
     * LEGACY-RME-PDF-1C — streaming the published record's private source PDF
     * or one of its rendered pages.
     *
     * Same boundary as `view` — reading the archive bytes is exactly as
     * sensitive as reading the row, and the file route must never be a weaker
     * door than the viewer page — plus one additional restriction: a VOIDed
     * record no longer streams.
     *
     * VOID exists precisely for a mis-filed archive (the canonical example is a
     * document attached to the WRONG patient), so continuing to serve those
     * bytes under that patient's record would keep serving the very leak the
     * void was meant to retract. The row itself stays readable — retracted, not
     * erased — so the metadata and the void reason remain auditable.
     */
    public function viewFile(User $user, LegacyRmeRecord $record): bool
    {
        return $this->view($user, $record) && $record->isPublished();
    }

    /**
     * Explicitly denied for everyone: a published legacy record is never edited
     * in place and never hard-deleted. Corrections go through void() plus a
     * fresh import.
     */
    public function update(User $user, LegacyRmeRecord $record): bool
    {
        return false;
    }

    public function delete(User $user, LegacyRmeRecord $record): bool
    {
        return false;
    }

    private function inScope(User $user, LegacyRmeRecord $record): bool
    {
        return $this->scope->allows(
            $user,
            $record->origin_branch_id !== null ? (int) $record->origin_branch_id : null,
        );
    }
}
