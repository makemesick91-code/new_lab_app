<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Policies;

use App\Models\User;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramRecordStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramWorkspaceScope;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RME\Services\DoctorRoomScopeService;
use Illuminate\Auth\Access\Response;

/**
 * FIX-04b — authorization for PUBLISHED legacy odontogram records.
 *
 * A published record is immutable, so `update` and `delete` are hard-wired to
 * FALSE for everyone — not omitted, but explicitly denied, so a future
 * `authorize('update', $record)` cannot silently fall through to a permissive
 * default. The only state change is VOID, which has its own named permission.
 */
class LegacyOdontogramRecordPolicy
{
    /**
     * Two different actors read a published archive.
     *
     * `view_legacy_odontogram_imports` is the INTAKE operator (Master Data
     * RME); `view_legacy_odontogram_archive` is the clinical reader (a doctor
     * treating the patient). Either may READ; neither implies the other, and
     * neither implies review/publish/void, which keep their own permissions.
     *
     * Branch scope still decides WHICH records: the read-only permission is
     * deliberately absent from LegacyOdontogramWorkspaceScope's governance
     * tier, so granting a doctor read access never widens them to every RME
     * branch's archive.
     *
     * @var list<string>
     */
    public const READ_PERMISSIONS = [
        'view_legacy_odontogram_imports',
        'view_legacy_odontogram_archive',
    ];

    public function __construct(
        private readonly LegacyOdontogramWorkspaceScope $scope,
        private readonly DoctorPatientScopeService $doctorScope,
        private readonly DoctorRoomScopeService $printDeny,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->canAny(self::READ_PERMISSIONS);
    }

    /**
     * Rule 243 §2.10 (PR A2) — with the encounter scope ON a Doctor outside an
     * own in-service encounter with the patient is refused as NOT FOUND (404);
     * with it OFF the answer is today's plain bool.
     */
    public function view(User $user, LegacyOdontogramRecord $record): Response|bool
    {
        if (! $this->viewAny($user) || ! $this->inScope($user, $record)) {
            return false;
        }

        return $this->withinClinicalScope($user, $record);
    }

    /**
     * Streaming the record's private source PDF or one of its rendered pages.
     *
     * Same boundary as `view`, plus one restriction: a VOIDed record no longer
     * streams. VOID exists precisely for a mis-filed archive (canonically:
     * attached to the WRONG patient), so continuing to serve those bytes under
     * that patient's record would keep serving the very leak the void retracted.
     * The row itself stays readable — retracted, not erased — so the metadata
     * and the void reason remain auditable.
     */
    public function viewFile(User $user, LegacyOdontogramRecord $record): Response|bool
    {
        $view = $this->view($user, $record);

        // A2 review INFO-3 — decide on whether `view` allowed, so a future
        // Response::allow() never passes a VOIDed record through.
        $allowed = $view instanceof Response ? $view->allowed() : $view === true;

        return $allowed ? $record->isPublished() : $view;
    }

    /**
     * Rule 243 §1 (owner revision 2026-11-17, plan default 4) — the source PDF
     * is a download. A Doctor is refused through the one print-deny authority
     * (rule 138) and reads the archive as inline page images instead
     * (`viewFile`); every other reader keeps exactly the boundary they had.
     */
    public function download(User $user, LegacyOdontogramRecord $record): Response|bool
    {
        $print = $this->printDeny->authorizeClinicalPrint($user);

        if ($print !== true) {
            return $print;
        }

        return $this->viewFile($user, $record);
    }

    public function void(User $user, LegacyOdontogramRecord $record): bool
    {
        return $user->can('void_legacy_odontogram_records')
            && $this->inScope($user, $record)
            && LegacyOdontogramRecordStatus::canTransition($record->status, LegacyOdontogramRecordStatus::VOID);
    }

    /**
     * Explicitly denied for everyone: a published legacy odontogram is never
     * edited in place and never hard-deleted. Corrections go through void()
     * plus a fresh import.
     */
    public function update(User $user, LegacyOdontogramRecord $record): bool
    {
        return false;
    }

    public function delete(User $user, LegacyOdontogramRecord $record): bool
    {
        return false;
    }

    private function inScope(User $user, LegacyOdontogramRecord $record): bool
    {
        return $this->scope->allows(
            $user,
            $record->branch_id !== null ? (int) $record->branch_id : null,
        );
    }

    /**
     * A legacy archive must never be MORE visible to a clinician than the
     * patient's native record.
     *
     * Branch scope alone is not enough for a doctor: DoctorPatientScopeService
     * admits a patient only through a real clinical relationship (an active
     * patient-doctor assignment, or a visit with that doctor). A same-branch
     * doctor with no relationship to the patient is refused the native record,
     * so the archive must refuse them too — otherwise the archive is the
     * broader door.
     *
     * The canonical native scope is reused verbatim rather than reimplemented,
     * so the two can never drift into disagreeing about who is treating whom.
     * Non-doctor actors are unaffected: intake and governance tiers keep branch
     * scope plus their own named permissions.
     *
     * Rule 243 §2.10 (PR A2) — the answer is DoctorPatientScopeService's
     * archive rule, shared with the controller's re-assertion and the history
     * list: the same relationship with the encounter scope OFF, an own
     * in-service encounter (read mode `history`) with it ON, 404 outside.
     */
    private function withinClinicalScope(User $user, LegacyOdontogramRecord $record): Response|bool
    {
        if (! $this->doctorScope->shouldApplyDoctorScope($user)) {
            return true;
        }

        $patient = $record->relationLoaded('patient')
            ? $record->patient
            : Patient::query()->find($record->patient_id);

        return $this->doctorScope->authorizeLegacyArchiveRead($user, $patient instanceof Patient ? $patient : null);
    }
}
