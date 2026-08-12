<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Policies;

use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Services\DoctorPatientScopeService;

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
        private readonly DoctorPatientScopeService $doctorScope,
    ) {}

    /**
     * LEGACY-RME-PDF-1D — two different actors read a published archive.
     *
     * `view_legacy_rme_imports` is the INTAKE operator (Master Data RME), and
     * `view_legacy_rme_archive` is the clinical reader (a doctor treating the
     * patient). Either one may READ; neither implies the other, and neither
     * implies review/publish/void, which keep their own named permissions.
     *
     * Branch scope still decides WHICH records: only the intake permissions are
     * governance-tier in LegacyRmeWorkspaceScope, so a clinical reader stays
     * pinned to their own branch.
     *
     * @var list<string>
     */
    public const READ_PERMISSIONS = [
        'view_legacy_rme_imports',
        'view_legacy_rme_archive',
    ];

    public function viewAny(User $user): bool
    {
        return $user->canAny(self::READ_PERMISSIONS);
    }

    public function view(User $user, LegacyRmeRecord $record): bool
    {
        return $this->viewAny($user)
            && $this->inScope($user, $record)
            && $this->withinClinicalScope($user, $record);
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

    /**
     * LEGACY-RME-PDF-ROLL-2 pilot finding — a legacy archive must never be
     * MORE visible to a clinician than the patient's native record.
     *
     * Branch scope alone was the whole boundary here, but a doctor's native
     * access is narrower than their branch: DoctorPatientScopeService admits a
     * patient only through a real clinical relationship (an active
     * patient-doctor assignment, or a visit with that doctor). A same-branch
     * doctor with no relationship to the patient is refused the native record
     * and was still able to open the legacy one — the archive was the broader
     * door.
     *
     * The canonical native scope is reused verbatim rather than reimplemented,
     * so the two can never drift into disagreeing about who is treating whom.
     * Non-doctor actors are unaffected: the intake and governance tiers keep
     * branch scope plus their own named permissions.
     */
    private function withinClinicalScope(User $user, LegacyRmeRecord $record): bool
    {
        if (! $this->doctorScope->shouldApplyDoctorScope($user)) {
            return true;
        }

        $patient = $record->relationLoaded('patient')
            ? $record->patient
            : Patient::query()->find($record->patient_id);

        if (! $patient instanceof Patient) {
            return false;
        }

        return $this->doctorScope->doctorCanAccessPatient($user, $patient);
    }
}
