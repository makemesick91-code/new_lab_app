<?php

namespace App\Modules\RME\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeOnlineContext\Models\UserOnlineContext;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 1 — the single authority for
 * the two device-independent Doctor restrictions.
 *
 * ROOM SCOPE (§16). A Doctor works one treatment room at a time. The room is read
 * from the server-side online context (Sprint 66.0 `startDoctorSession`) and NEVER
 * from the request, so a crafted `clinic_room_id` / `branch_id` / `visit_id` cannot
 * widen it. Applied both as a list-query scope and as a per-visit authorization
 * guard, so hiding a row and denying a direct URL are the same rule.
 *
 * HISTORY BOUNDARY (§17). The restriction covers ONLY the ACTIVE operational set
 * (`ClinicVisit::PRE_EXAM_STATUSES` = registered/waiting/in_progress) — precisely
 * the set the room worklist and patient queue already show. Post-examination
 * (`cashier_pending`) and historical (`completed`/`cancelled`) records stay
 * reachable under the pre-existing Sprint 66.2 doctor-patient scope, so this
 * sprint can never hide clinical history from the doctor who created it.
 *
 * PRINT DENIAL (§18/§19). A Doctor may read and edit native RME/Odontogram under
 * the existing lifecycle rules but may not print them: the three routes of rule
 * 138 §6 — `rme.visits.print`, `rme.visits.pdf`, `rme.odontograms.print`.
 *
 * DOWNLOAD DENIAL (rule 243 §1 — the owner's 2026-11-17 revision of rule 93 §12,
 * memo M2.4). The same predicate now also refuses a Doctor the published legacy
 * RME print, export and source PDF, the legacy odontogram source PDF, and the PTM
 * (consent) print. A Doctor still READS those documents — legacy archives as
 * inline page images, the consent on screen. Prescription printing is unaffected.
 * There is no second definition: every one of those doors asks this class.
 *
 * "Is this a scoped Doctor" is delegated to {@see DoctorPatientScopeService} so
 * there is exactly one definition of it — Owner / Super Admin / Supervisor RME
 * are exempt there and stay exempt here.
 */
class DoctorRoomScopeService
{
    public const AUDIT_ROOM_ACCESS_REJECTED = 'DOCTOR_ROOM_ACCESS_REJECTED';

    public const AUDIT_PRINT_RME_REJECTED = 'DOCTOR_PRINT_RME_REJECTED';

    public const AUDIT_PRINT_ODONTOGRAM_REJECTED = 'DOCTOR_PRINT_ODONTOGRAM_REJECTED';

    /** Rule 243 §1 — legacy RME print, export or source PDF refused to a Doctor. */
    public const AUDIT_PRINT_LEGACY_RME_REJECTED = 'DOCTOR_PRINT_LEGACY_RME_REJECTED';

    /** Rule 243 §1 — legacy odontogram source PDF refused to a Doctor. */
    public const AUDIT_PRINT_LEGACY_ODONTOGRAM_REJECTED = 'DOCTOR_PRINT_LEGACY_ODONTOGRAM_REJECTED';

    /** Rule 243 §1 — PTM (consent) print refused to a Doctor. */
    public const AUDIT_PRINT_CONSENT_REJECTED = 'DOCTOR_PRINT_CONSENT_REJECTED';

    public const CLINICAL_PRINT_DENIED_MESSAGE = 'Dokter tidak diizinkan mencetak atau mengunduh rekam medis.';

    public function __construct(
        private readonly DoctorPatientScopeService $doctorScope,
        private readonly UserOnlineContextService $onlineContext,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function shouldApplyRoomScope(User $user): bool
    {
        return $this->doctorScope->shouldApplyDoctorScope($user);
    }

    /**
     * The doctor's current treatment room, resolved server-side. Returns null
     * unless there is a live ONLINE doctor context carrying a room — an offline,
     * inactive or non-doctor context is never trusted.
     */
    public function currentRoomIdFor(User $user): ?int
    {
        $context = $this->onlineContext->currentContextFor($user);

        if ($context === null
            || $context->role_context !== UserOnlineContext::ROLE_DOCTOR
            || $context->status !== UserOnlineContext::STATUS_ONLINE
            || $context->clinic_room_id === null) {
            return null;
        }

        return (int) $context->clinic_room_id;
    }

    /**
     * Narrow a visit listing so ACTIVE patients of other rooms disappear.
     *
     * This must stay the mirror image of {@see deniesActiveVisitOutsideRoom}:
     * hiding a row and denying a direct URL are the same rule. In particular it
     * must NOT emit a bare `where('clinic_room_id', …)` — the same closure also
     * feeds Daftar Kunjungan (`ClinicVisitService::paginate`), which lists
     * historical visits, and a blanket room filter there would hide the doctor's
     * own completed records (§17).
     *
     * Fails closed on the ACTIVE set when no room resolves; history stays
     * readable either way.
     *
     * @param  Builder<ClinicVisit>  $query
     * @return Builder<ClinicVisit>
     */
    public function applyRoomScope(User $user, Builder $query): Builder
    {
        if (! $this->shouldApplyRoomScope($user)) {
            return $query;
        }

        $roomId = $this->currentRoomIdFor($user);

        if ($roomId === null) {
            return $query->whereNotIn('status', ClinicVisit::PRE_EXAM_STATUSES);
        }

        return $query->where(function (Builder $inner) use ($roomId) {
            $inner->whereNotIn('status', ClinicVisit::PRE_EXAM_STATUSES)
                ->orWhere('clinic_room_id', $roomId);
        });
    }

    /**
     * True when this visit is an ACTIVE patient of some OTHER room and must
     * therefore be invisible to this doctor. Terminal and post-examination
     * visits are history and are never denied here (§17).
     */
    public function deniesActiveVisitOutsideRoom(User $user, ClinicVisit $visit): bool
    {
        if (! $this->shouldApplyRoomScope($user)) {
            return false;
        }

        if (! in_array($visit->status, ClinicVisit::PRE_EXAM_STATUSES, true)) {
            return false;
        }

        $roomId = $this->currentRoomIdFor($user);

        if ($roomId === null) {
            return true;
        }

        return (int) $visit->clinic_room_id !== $roomId;
    }

    public function authorizeActiveVisitRoom(User $user, ClinicVisit $visit): Response|bool
    {
        if ($this->deniesActiveVisitOutsideRoom($user, $visit)) {
            return Response::deny('Pasien ini tidak berada di ruangan perawatan Anda saat ini.');
        }

        return true;
    }

    public function deniesClinicalPrint(User $user): bool
    {
        return $this->shouldApplyRoomScope($user);
    }

    public function authorizeClinicalPrint(User $user): Response|bool
    {
        if ($this->deniesClinicalPrint($user)) {
            return Response::deny(self::CLINICAL_PRINT_DENIED_MESSAGE);
        }

        return true;
    }

    /**
     * Rule 243 §1 — the serving path's own re-assertion (CG-06 third layer).
     *
     * Called by the controller that streams or renders a document AFTER the
     * policy, so a crafted URL is refused even if the policy is ever bypassed:
     * the single global Gate::before answers every ability without reaching the
     * policy. The same predicate as the policy, never a second definition.
     *
     * @throws AuthorizationException
     */
    public function assertClinicalPrintAllowed(User $user): void
    {
        if ($this->deniesClinicalPrint($user)) {
            throw new AuthorizationException(self::CLINICAL_PRINT_DENIED_MESSAGE);
        }
    }

    /**
     * Audit a REAL rejected attempt. Deliberately called from controllers, never
     * from the policies: policies are also evaluated for `@can` UI gating, and
     * auditing there would record a "rejection" every time a button is merely
     * hidden. Payload is identifiers and status only — no patient PII.
     */
    public function auditRoomAccessRejected(User $user, ClinicVisit $visit): void
    {
        $this->auditLogs->log(
            'trx_clinic_visits',
            (int) $visit->id,
            self::AUDIT_ROOM_ACCESS_REJECTED,
            null,
            [
                'visit_status' => $visit->status,
                'visit_room_id' => $visit->clinic_room_id,
                'doctor_room_id' => $this->currentRoomIdFor($user),
            ],
            $user,
        );
    }

    public function auditVisitPrintRejected(User $user, ClinicVisit $visit): void
    {
        $this->auditLogs->log(
            'trx_clinic_visits',
            (int) $visit->id,
            self::AUDIT_PRINT_RME_REJECTED,
            null,
            ['visit_status' => $visit->status],
            $user,
        );
    }

    public function auditOdontogramPrintRejected(User $user, Odontogram $odontogram): void
    {
        $this->auditLogs->log(
            'trx_odontograms',
            (int) $odontogram->id,
            self::AUDIT_PRINT_ODONTOGRAM_REJECTED,
            null,
            ['clinic_visit_id' => $odontogram->clinic_visit_id],
            $user,
        );
    }

    /**
     * Rule 243 §1 — `$action` is the door: `source`, `print`, `export` on the
     * clinical archive, `staging_source` on the intake screen (the same PDF
     * before and after publishing), `oversight_source|print|pdf` on the
     * oversight screens. Identifiers only: never the patient's name, RM number
     * or KTP/NIK.
     */
    public function auditLegacyRmePrintRejected(User $user, LegacyRmeRecord|LegacyRmeImport $record, string $action): void
    {
        $this->auditLogs->log(
            $record->getTable(),
            (int) $record->getKey(),
            self::AUDIT_PRINT_LEGACY_RME_REJECTED,
            null,
            ['action' => $action],
            $user,
        );
    }

    public function auditLegacyOdontogramPrintRejected(User $user, LegacyOdontogramRecord $record, string $action): void
    {
        $this->auditLogs->log(
            $record->getTable(),
            (int) $record->getKey(),
            self::AUDIT_PRINT_LEGACY_ODONTOGRAM_REJECTED,
            null,
            ['action' => $action],
            $user,
        );
    }

    public function auditConsentPrintRejected(User $user, RmeVisitConsent $consent): void
    {
        $this->auditLogs->log(
            $consent->getTable(),
            (int) $consent->getKey(),
            self::AUDIT_PRINT_CONSENT_REJECTED,
            null,
            ['action' => 'print', 'clinic_visit_id' => $consent->clinic_visit_id],
            $user,
        );
    }
}
