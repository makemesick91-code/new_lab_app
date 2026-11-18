<?php

namespace App\Modules\RME\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RmeOnlineContext\Services\DoctorUserResolver;
use App\Services\Foundation\FeatureFlagService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * Sprint 66.2 — Doctor RM visibility scope by assignment and visit history.
 *
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 (rule 243 §2) — the ENCOUNTER
 * scope, behind `rme.doctor_encounter_scope` (risky, default OFF, read only
 * through FeatureFlagService, CG-08). With the flag OFF every method below
 * answers exactly as before. With it ON, for a scoped Doctor:
 *
 *  - read mode per patient ({@see readModeFor()}): HISTORY while the doctor has
 *    an own in-service encounter with the patient ({@see DoctorEncounterWindow}),
 *    OWN when they only have own visits, NONE otherwise. Assignment rows no
 *    longer grant anything (plan default 1);
 *  - a visit is readable when it is the doctor's own (any date), or a
 *    non-pre-exam visit of the patient while the mode is HISTORY; the patient
 *    as a whole (workspace, patient lists) only in HISTORY;
 *  - out-of-scope reads answer 404 (plan default 6), so existence never leaks;
 *  - writes only on the doctor's own in-service encounter
 *    ({@see doctorMayWriteVisit()}, {@see authorizeVisitWrite()}: 403
 *    "ditangani dokter lain");
 *  - every list scope stays the mirror image of its per-record check;
 *  - the published legacy archives (PR A2) only in HISTORY
 *    ({@see authorizeLegacyArchiveRead()}), 404 otherwise;
 *  - the patient's RM workspace (PR A3) in HISTORY and OWN
 *    ({@see authorizeWorkspaceRead()}), each list in it naming only what the
 *    viewer may read ({@see listsVisitFor()}).
 *
 * This class stays the single authority: Owner, Super Admin and Supervisor RME
 * are exempt ({@see shouldApplyDoctorScope()}), whatever the flag.
 */
class DoctorPatientScopeService
{
    /** Rule 243 §2 — the encounter scope switch (risky, default OFF). */
    public const ENCOUNTER_SCOPE_FLAG = 'rme.doctor_encounter_scope';

    public const READ_NONE = 'none';

    public const READ_OWN = 'own';

    public const READ_HISTORY = 'history';

    public const OUT_OF_SCOPE_MESSAGE = 'Anda tidak memiliki akses ke RM pasien ini.';

    public const OTHER_DOCTOR_MESSAGE = 'Kunjungan ini ditangani dokter lain. Anda hanya dapat menulis pada pemeriksaan Anda sendiri.';

    public const NOT_IN_SERVICE_MESSAGE = 'Pemeriksaan ini sudah selesai dilayani. Rekam medisnya hanya dapat dibaca.';

    private const UNLINKED_MESSAGE = 'Akun dokter belum terhubung ke Master Dokter.';

    private const MEMO_PREFIX = 'dms.doctor_read_mode.';

    public function __construct(
        private readonly DoctorUserResolver $doctorResolver,
        private readonly FeatureFlagService $flags,
        private readonly DoctorEncounterWindow $window,
    ) {}

    /**
     * Rule 243 §2 — the only place the encounter scope switch is read.
     */
    public function encounterScopeEnabled(): bool
    {
        return $this->flags->enabled(self::ENCOUNTER_SCOPE_FLAG);
    }

    /**
     * Rule 243 §2 — how much of this patient's record the account may read:
     * READ_HISTORY (everything this class admits), READ_OWN (only the doctor's
     * own visits) or READ_NONE. An exempt account is READ_HISTORY. With the flag
     * OFF the answer is today's relationship rule (HISTORY or NONE).
     *
     * Memoised for the current ROUTED request only, keyed by account and patient
     * (the DoctorIdentityResolver pattern, rule 237 §12): a list asks once per
     * row, and the answer cannot change between rows of one request. Queue jobs,
     * commands and direct calls are never memoised.
     */
    public function readModeFor(User $user, int $patientId): string
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return self::READ_HISTORY;
        }

        $doctor = $this->resolveDoctorForUser($user);

        if ($doctor === null) {
            return self::READ_NONE;
        }

        if (! $this->encounterScopeEnabled()) {
            return $this->doctorHasPatientAccess((int) $doctor->id, $patientId) ? self::READ_HISTORY : self::READ_NONE;
        }

        $request = $this->routedRequest();
        $key = self::MEMO_PREFIX.$user->getKey().'.'.$patientId;

        if ($request !== null && $request->attributes->has($key)) {
            return (string) $request->attributes->get($key);
        }

        $mode = match (true) {
            $this->hasInServiceEncounter((int) $doctor->id, $patientId) => self::READ_HISTORY,
            $this->hasOwnVisit((int) $doctor->id, $patientId) => self::READ_OWN,
            default => self::READ_NONE,
        };

        $request?->attributes->set($key, $mode);

        return $mode;
    }

    /**
     * Rule 243 §2.11 (PR A3) — does the encounter scope decide for this
     * account? The flag is ON and the account is a scoped Doctor. False without
     * an account, with the flag OFF and for the exempt roles, so every list that
     * asks it stays exactly as before there.
     */
    public function encounterScopeAppliesTo(?User $user): bool
    {
        return $user !== null && $this->encounterScopeEnabled() && $this->shouldApplyDoctorScope($user);
    }

    /**
     * Rule 243 (flag-OFF parity) — the ability a call site asks: `$scoped` while
     * the encounter scope applies to $user, otherwise `$base`, the ability that
     * call site asked before the encounter scope existed. A scope-only ability
     * whose policy falls back to `$base` by calling it directly would skip every
     * Gate hook and event registered for `$base`; asking `$base` itself keeps the
     * flag-OFF (and exempt-role) authorization exactly as it was.
     */
    public function abilityFor(?User $user, string $scoped, string $base): string
    {
        return $this->encounterScopeAppliesTo($user) ? $scoped : $base;
    }

    /**
     * Rule 243 §2.11 (PR A3) — may a list shown to $viewer name $visit (its
     * number, date, doctor, a link to it)? With the encounter scope deciding,
     * exactly when the viewer may read the visit ({@see doctorCanAccessVisit()}),
     * so a list never names what the record refuses; otherwise always. Costs no
     * query per visit once the identity and the read mode are memoised.
     */
    public function listsVisitFor(?User $viewer, ClinicVisit $visit): bool
    {
        if ($viewer === null || ! $this->encounterScopeAppliesTo($viewer)) {
            return true;
        }

        return $this->doctorCanAccessVisit($viewer, $visit);
    }

    /**
     * Rule 243 §2.11 (PR A3) — may a scoped Doctor open the patient's RM
     * WORKSPACE (MedicalRecordPolicy::viewWorkspace)? The workspace is
     * patient-level: it opens in read mode HISTORY (everything this class
     * admits) and OWN (the after-service workspace: the doctor's own visits and
     * sheets only), and answers 404 in NONE; an unlinked doctor account keeps
     * its 403. What the workspace then lists is decided per record. With the
     * flag OFF {@see readModeFor()} is today's relationship rule.
     */
    public function authorizeWorkspaceRead(User $user, int $patientId): Response|bool
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        if ($this->resolveDoctorForUser($user) === null) {
            return Response::deny(self::UNLINKED_MESSAGE);
        }

        return $this->readModeFor($user, $patientId) === self::READ_NONE ? $this->outOfScope() : true;
    }

    public function shouldApplyDoctorScope(User $user): bool
    {
        if ($user->hasRole(['Owner', 'Super Admin', 'Supervisor RME'])) {
            return false;
        }

        return $user->hasRole('Doctor');
    }

    public function resolveDoctorForUser(User $user): ?Doctor
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return null;
        }

        return $this->doctorResolver->resolveForUser($user);
    }

    public function doctorCanAccessPatient(User $user, Patient $patient): bool
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        $doctor = $this->resolveDoctorForUser($user);

        if ($doctor === null) {
            return false;
        }

        // Rule 243 §2 — the patient as a whole only inside an own in-service
        // encounter; the doctor's own visits stay readable per visit.
        if ($this->encounterScopeEnabled()) {
            return $this->readModeFor($user, (int) $patient->id) === self::READ_HISTORY;
        }

        return $this->doctorHasPatientAccess((int) $doctor->id, (int) $patient->id);
    }

    public function doctorCanAccessVisit(User $user, ClinicVisit $visit): bool
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        if ($visit->patient_id === null) {
            return false;
        }

        if ($this->encounterScopeEnabled()) {
            return $this->doctorCanReadVisitInEncounterScope($user, $visit);
        }

        $patient = $visit->relationLoaded('patient')
            ? $visit->patient
            : Patient::query()->find($visit->patient_id);

        if ($patient === null) {
            return false;
        }

        return $this->doctorCanAccessPatient($user, $patient);
    }

    /**
     * Rule 243 §2 — may this account WRITE on this visit? With the flag ON a
     * scoped Doctor writes only on their OWN encounter while it is in service;
     * another doctor's encounter is never co-authored (owner M2.1). With the
     * flag OFF this is exactly {@see doctorCanAccessVisit()}, so the third
     * layers that ask it (RmeVisitConsentService::actorMayWorkOnEncounter,
     * PrescriptionAuthoringAuthority::permits) behave as before.
     */
    public function doctorMayWriteVisit(User $user, ClinicVisit $visit): bool
    {
        if (! $this->encounterScopeEnabled()) {
            return $this->doctorCanAccessVisit($user, $visit);
        }

        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        $doctor = $this->resolveDoctorForUser($user);

        return $doctor !== null
            && (int) $visit->doctor_id === (int) $doctor->id
            && $this->window->isInService($visit);
    }

    /**
     * @param  Builder<Patient>  $query
     * @return Builder<Patient>
     */
    public function applyPatientScopeForUser(User $user, Builder $query): Builder
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return $query;
        }

        $doctor = $this->resolveDoctorForUser($user);

        if ($doctor === null) {
            return $query->whereRaw('1 = 0');
        }

        // Rule 243 §2 — the mirror of doctorCanAccessPatient(): only patients
        // with an own in-service encounter.
        if ($this->encounterScopeEnabled()) {
            $doctorId = (int) $doctor->id;

            return $query->whereExists(
                fn (QueryBuilder $sub) => $this->ownInServiceEncounterFor($sub, $doctorId, 'mst_patients.id'),
            );
        }

        return $this->applyPatientScopeForDoctor($query, (int) $doctor->id);
    }

    /**
     * @param  Builder<ClinicVisit>  $query
     * @return Builder<ClinicVisit>
     */
    public function applyVisitScopeForUser(User $user, Builder $query): Builder
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return $query;
        }

        $doctor = $this->resolveDoctorForUser($user);

        if ($doctor === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->encounterScopeEnabled()) {
            return $this->applyVisitEncounterScopeForDoctor($query, (int) $doctor->id);
        }

        return $this->applyVisitScopeForDoctor($query, (int) $doctor->id);
    }

    public function authorizePatientAccess(User $user, Patient $patient): Response|bool
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        if ($this->resolveDoctorForUser($user) === null) {
            return Response::deny(self::UNLINKED_MESSAGE);
        }

        if (! $this->doctorCanAccessPatient($user, $patient)) {
            return $this->outOfScope();
        }

        return true;
    }

    /**
     * Rule 243 §2.10 (PR A2) — may this account read the patient's published
     * LEGACY archive (legacy RME, legacy odontogram)? The one answer behind the
     * archive policies' `view`/`viewFile`, their controllers' re-assertion and
     * the archive lists (LegacyRmePatientHistoryService,
     * LegacyOdontogramPatientHistoryService), so a list can never offer what
     * the record refuses. The archive's branch gate (origin branch in
     * DoctorClinicalBranchResolver's set) is the caller's and stays untouched.
     *
     * Flag OFF: today's answer exactly — the treating relationship of
     * {@see doctorCanAccessPatient()} as a plain bool (the policy's 403).
     * Flag ON: an archive is patient-level history, so a scoped Doctor reads it
     * only in READ_HISTORY ({@see readModeFor()}, never re-derived); otherwise
     * 404, so the archive's existence does not leak. An unlinked doctor account
     * keeps its 403.
     */
    public function authorizeLegacyArchiveRead(User $user, ?Patient $patient): Response|bool
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        if (! $this->encounterScopeEnabled()) {
            return $patient instanceof Patient && $this->doctorCanAccessPatient($user, $patient);
        }

        if ($this->resolveDoctorForUser($user) === null) {
            return Response::deny(self::UNLINKED_MESSAGE);
        }

        if ($patient instanceof Patient && $this->readModeFor($user, (int) $patient->getKey()) === self::READ_HISTORY) {
            return true;
        }

        // A2 review LOW-1 — no message: a 404 out of scope must read exactly like
        // a 404 for an id that does not exist (the JSON body carries the message).
        return Response::denyAsNotFound();
    }

    /**
     * Rule 243 §2.10 — the serving path's re-assertion of
     * {@see authorizeLegacyArchiveRead()}, asked by the archive controllers
     * after the policy, so a bypassed policy (a regression, `Gate::before`)
     * still cannot serve a doctor outside the scope. Throws the same 404/403.
     * A no-op with the flag OFF (A1's pattern for the service assertions): the
     * policy stays today's only clinical gate there. The patient is loaded only
     * when the rule applies, so other readers pay no query for it.
     *
     * @throws AuthorizationException
     */
    public function assertLegacyArchiveReadable(User $user, int $patientId): void
    {
        if (! $this->encounterScopeEnabled() || ! $this->shouldApplyDoctorScope($user)) {
            return;
        }

        $decision = $this->authorizeLegacyArchiveRead($user, Patient::query()->find($patientId));

        if ($decision !== true) {
            ($decision instanceof Response ? $decision : Response::deny())->authorize();
        }
    }

    public function authorizeVisitAccess(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        if ($this->resolveDoctorForUser($user) === null) {
            return Response::deny(self::UNLINKED_MESSAGE);
        }

        if (! $this->doctorCanAccessVisit($user, $visit)) {
            return $this->outOfScope();
        }

        return true;
    }

    /**
     * Rule 243 §2 — the policy form of {@see doctorMayWriteVisit()}. Out of the
     * read scope is 404 (nothing leaks); a readable visit of another doctor is
     * 403 "ditangani dokter lain"; the doctor's own visit out of service is 403.
     * With the flag OFF this is exactly {@see authorizeVisitAccess()}.
     */
    public function authorizeVisitWrite(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $this->encounterScopeEnabled()) {
            return $this->authorizeVisitAccess($user, $visit);
        }

        $read = $this->authorizeVisitAccess($user, $visit);

        if ($read !== true) {
            return $read;
        }

        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        $doctor = $this->resolveDoctorForUser($user);

        if ($doctor === null || (int) $visit->doctor_id !== (int) $doctor->id) {
            return Response::deny(self::OTHER_DOCTOR_MESSAGE);
        }

        if (! $this->window->isInService($visit)) {
            return Response::deny(self::NOT_IN_SERVICE_MESSAGE);
        }

        return true;
    }

    /**
     * Strict visit-level scope for specific visit detail/actions (not patient workspace).
     */
    public function doctorCanAccessSpecificVisit(User $user, ClinicVisit $visit): bool
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        if ($visit->patient_id === null) {
            return false;
        }

        $doctor = $this->resolveDoctorForUser($user);

        if ($doctor === null) {
            return false;
        }

        $doctorId = (int) $doctor->id;

        if ((int) $visit->doctor_id === $doctorId) {
            return true;
        }

        return PatientDoctorAssignment::query()
            ->where('patient_id', (int) $visit->patient_id)
            ->where('doctor_id', $doctorId)
            ->whereNull('unassigned_at')
            ->exists();
    }

    public function authorizeSpecificVisitAccess(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        if ($this->resolveDoctorForUser($user) === null) {
            return Response::deny('Akun dokter belum terhubung ke Master Dokter.');
        }

        if (! $this->doctorCanAccessSpecificVisit($user, $visit)) {
            return Response::deny('Anda tidak memiliki akses ke detail kunjungan pasien ini.');
        }

        return true;
    }

    /**
     * Rule 243 §2.6 (PR A1b) — with the encounter scope ON this panel describes
     * the assignment-based scope that no longer grants anything, and it names
     * other doctors; a doctor viewer is shown nothing. Every other viewer, and
     * every viewer with the flag OFF, sees it exactly as before.
     *
     * @return array<int, array{doctor: Doctor, badges: array<int, string>}>
     */
    public function doctorsWithAccessSummary(Patient $patient, ?User $viewer = null): array
    {
        if ($viewer !== null && $this->encounterScopeEnabled() && $this->shouldApplyDoctorScope($viewer)) {
            return [];
        }

        $patientId = (int) $patient->id;
        $summary = [];

        $assignments = $patient->doctorAssignments()
            ->with('doctor')
            ->orderByDesc('assigned_at')
            ->get();

        foreach ($assignments as $assignment) {
            if ($assignment->doctor === null) {
                continue;
            }

            $doctorId = (int) $assignment->doctor_id;
            $badges = $summary[$doctorId]['badges'] ?? [];

            if ($assignment->isActive()) {
                $badges[] = 'Aktif';
            } else {
                $badges[] = 'Pernah Assigned';
            }

            $badges[] = match ($assignment->assignment_type) {
                PatientDoctorAssignment::TYPE_AUTO_VISIT => 'Auto dari Antrian',
                PatientDoctorAssignment::TYPE_SHARED => 'Shared/Reassigned',
                PatientDoctorAssignment::TYPE_REASSIGNED => 'Shared/Reassigned',
                PatientDoctorAssignment::TYPE_MANUAL => 'Manual',
                default => ucfirst(str_replace('_', ' ', $assignment->assignment_type)),
            };

            $summary[$doctorId] = [
                'doctor' => $assignment->doctor,
                'badges' => array_values(array_unique($badges)),
            ];
        }

        $visitDoctorIds = ClinicVisit::query()
            ->where('patient_id', $patientId)
            ->whereNotNull('doctor_id')
            ->distinct()
            ->pluck('doctor_id');

        $visitDoctors = Doctor::query()->whereIn('id', $visitDoctorIds)->get()->keyBy('id');

        foreach ($visitDoctorIds as $doctorId) {
            $doctor = $visitDoctors->get($doctorId);
            if ($doctor === null) {
                continue;
            }

            $doctorId = (int) $doctorId;
            $badges = $summary[$doctorId]['badges'] ?? [];
            $badges[] = 'Pernah Menangani';
            $summary[$doctorId] = [
                'doctor' => $doctor,
                'badges' => array_values(array_unique($badges)),
            ];
        }

        return array_values($summary);
    }

    /**
     * Rule 243 §2 — a refusal outside the scope is 404 with the flag ON, so a
     * doctor cannot probe which visits or patients exist; today's 403 with it
     * OFF.
     */
    private function outOfScope(): Response
    {
        // The 404 carries no message, so it reads exactly like a missing id
        // (A2 review LOW-1: the JSON body would otherwise tell them apart).
        return $this->encounterScopeEnabled()
            ? Response::denyAsNotFound()
            : Response::deny(self::OUT_OF_SCOPE_MESSAGE);
    }

    /**
     * Rule 243 §2 — the per-record read; {@see applyVisitEncounterScopeForDoctor()}
     * is its mirror image. Own visits at any date (but never a soft-deleted
     * one); another doctor's visit only when it is past the pre-exam stage and
     * the doctor is inside an own in-service encounter with the patient.
     */
    private function doctorCanReadVisitInEncounterScope(User $user, ClinicVisit $visit): bool
    {
        $doctor = $this->resolveDoctorForUser($user);

        if ($doctor === null || $visit->trashed()) {
            return false;
        }

        if ((int) $visit->doctor_id === (int) $doctor->id) {
            return true;
        }

        if (in_array($visit->status, ClinicVisit::PRE_EXAM_STATUSES, true)) {
            return false;
        }

        return $this->readModeFor($user, (int) $visit->patient_id) === self::READ_HISTORY;
    }

    /**
     * Rule 243 §2 — the list mirror of {@see doctorCanReadVisitInEncounterScope()}.
     *
     * @param  Builder<ClinicVisit>  $query
     * @return Builder<ClinicVisit>
     */
    private function applyVisitEncounterScopeForDoctor(Builder $query, int $doctorId): Builder
    {
        return $query->where(function (Builder $q) use ($doctorId) {
            $q->where('trx_clinic_visits.doctor_id', $doctorId)
                ->orWhere(function (Builder $history) use ($doctorId) {
                    $history->whereNotIn('trx_clinic_visits.status', ClinicVisit::PRE_EXAM_STATUSES)
                        ->whereExists(fn (QueryBuilder $sub) => $this->ownInServiceEncounterFor($sub, $doctorId, 'trx_clinic_visits.patient_id'));
                });
        });
    }

    /**
     * "This doctor has an own in-service encounter with the patient in
     * `$patientColumn`" as a correlated subquery; the window is
     * {@see DoctorEncounterWindow::constrainInService()}, never re-derived.
     */
    private function ownInServiceEncounterFor(QueryBuilder $sub, int $doctorId, string $patientColumn): QueryBuilder
    {
        $sub->selectRaw('1')
            ->from('trx_clinic_visits as dew')
            ->whereColumn('dew.patient_id', $patientColumn)
            ->where('dew.doctor_id', $doctorId);

        $this->window->constrainInService($sub, 'dew');

        return $sub;
    }

    private function hasInServiceEncounter(int $doctorId, int $patientId): bool
    {
        $query = ClinicVisit::query()
            ->where('trx_clinic_visits.patient_id', $patientId)
            ->where('trx_clinic_visits.doctor_id', $doctorId);

        $this->window->constrainInService($query);

        return $query->exists();
    }

    private function hasOwnVisit(int $doctorId, int $patientId): bool
    {
        return ClinicVisit::query()
            ->where('patient_id', $patientId)
            ->where('doctor_id', $doctorId)
            ->exists();
    }

    /**
     * The current ROUTED HTTP request, or null (DoctorIdentityResolver's rule:
     * nothing is memoised in a queue job, a command or a direct call).
     */
    private function routedRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request && $request->route() !== null ? $request : null;
    }

    private function doctorHasPatientAccess(int $doctorId, int $patientId): bool
    {
        if (ClinicVisit::query()
            ->where('patient_id', $patientId)
            ->where('doctor_id', $doctorId)
            ->exists()) {
            return true;
        }

        return PatientDoctorAssignment::query()
            ->where('patient_id', $patientId)
            ->where('doctor_id', $doctorId)
            ->whereNull('unassigned_at')
            ->exists();
    }

    /**
     * @param  Builder<Patient>  $query
     * @return Builder<Patient>
     */
    private function applyPatientScopeForDoctor(Builder $query, int $doctorId): Builder
    {
        return $query->where(function (Builder $q) use ($doctorId) {
            $q->whereExists(function ($sub) use ($doctorId) {
                $sub->selectRaw('1')
                    ->from('trx_rme_patient_doctor_assignments as pda')
                    ->whereColumn('pda.patient_id', 'mst_patients.id')
                    ->where('pda.doctor_id', $doctorId)
                    ->whereNull('pda.unassigned_at');
            })->orWhereExists(function ($sub) use ($doctorId) {
                $sub->selectRaw('1')
                    ->from('trx_clinic_visits as v')
                    ->whereColumn('v.patient_id', 'mst_patients.id')
                    ->where('v.doctor_id', $doctorId)
                    // Match doctorHasPatientAccess(): a soft-deleted visit is
                    // no clinical relationship (ClinicVisit uses SoftDeletes).
                    ->whereNull('v.deleted_at');
            });
        });
    }

    /**
     * @param  Builder<ClinicVisit>  $query
     * @return Builder<ClinicVisit>
     */
    private function applyVisitScopeForDoctor(Builder $query, int $doctorId): Builder
    {
        return $query->where(function (Builder $q) use ($doctorId) {
            $q->whereExists(function ($sub) use ($doctorId) {
                $sub->selectRaw('1')
                    ->from('trx_rme_patient_doctor_assignments as pda')
                    ->whereColumn('pda.patient_id', 'trx_clinic_visits.patient_id')
                    ->where('pda.doctor_id', $doctorId)
                    ->whereNull('pda.unassigned_at');
            })->orWhereExists(function ($sub) use ($doctorId) {
                // A raw subquery does not inherit the model's SoftDeletes scope.
                // A soft-deleted visit is no relationship: the per-record
                // predicate (doctorHasPatientAccess) already says so, so the list
                // must too, or it offers visits whose detail answers 403
                // (DMS-RME-REVISION-PROGRAM-7; its two siblings were fixed in
                // 4f69d8d7, this one was missed).
                $sub->selectRaw('1')
                    ->from('trx_clinic_visits as own_visit')
                    ->whereColumn('own_visit.patient_id', 'trx_clinic_visits.patient_id')
                    ->where('own_visit.doctor_id', $doctorId)
                    ->whereNull('own_visit.deleted_at');
            });
        });
    }
}
