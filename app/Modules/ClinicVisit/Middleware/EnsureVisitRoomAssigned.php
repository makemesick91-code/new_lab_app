<?php

namespace App\Modules\ClinicVisit\Middleware;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Services\PatientRmWorkspaceResolver;
use App\Modules\RME\Services\DoctorPatientScopeService;
use Closure;
use Illuminate\Auth\Access\Response as AuthorizationResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hotfix Sprint 60.8 — RME room-assignment gate before doctor examination.
 *
 * Blocks doctor examination input (Rekam Medis & Odontogram routes) for any
 * active pre-examination visit that has not been placed into a treatment room
 * yet. This is the authoritative, controller-level enforcement of the gate —
 * hiding buttons in Blade is only a UX hint. Terminal and cashier_pending
 * visits are exempt (handled by ClinicVisit::requiresRoomBeforeExam()), so
 * post-examination editing introduced in Sprint 59 is never blocked.
 */
class EnsureVisitRoomAssigned
{
    /** The patient RM workspace routes whose {clinicVisit} is the book's storage anchor. */
    private const WORKSPACE_ROUTES = [
        'rme.visits.medical-record.show',
        'rme.visits.medical-record.store',
        'rme.visits.medical-record.handwriting.store',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $visit = $this->gatedVisit($request, $this->resolveVisit($request));

        if ($visit instanceof ClinicVisit && $visit->requiresRoomBeforeExam()) {
            $this->refuseUnreadable($request, $visit);

            return redirect()
                ->route('rme.visits.show', $visit)
                ->with('error', 'Pasien belum ditempatkan ke ruangan perawatan.');
        }

        return $next($request);
    }

    /**
     * Rule 243 §2.12 (PR A4, closes §2.8 item 8 (c)) — authorise before the room
     * redirect. With the encounter scope applying to the user (flag ON, a scoped
     * Doctor), a visit the doctor may not read answers the scope's 404 here,
     * exactly as it does once placed, so the redirect never tells another
     * doctor's roomless pre-exam visit apart from one that does not exist. The
     * doctor's own roomless visit is readable and keeps the redirect; the flag
     * OFF and every other account keep it unconditionally.
     */
    private function refuseUnreadable(Request $request, ClinicVisit $visit): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return;
        }

        $scope = app(DoctorPatientScopeService::class);

        if ($scope->encounterScopeAppliesTo($user) && ! $scope->listsVisitFor($user, $visit)) {
            // The scope's own refusal (no message), rendered like every other
            // out-of-scope 404 (rule 243 §2.10 "Existence").
            AuthorizationResponse::denyAsNotFound()->authorize();
        }
    }

    /**
     * Rule 243 §2.11 (PR A3, review MEDIUM-1) — on the patient's RM workspace
     * routes the URL visit is the canonical ANCHOR, where the book is stored,
     * not necessarily the visit under examination. With the doctor encounter
     * scope applying to the user (flag ON, a scoped Doctor), an anchor the
     * doctor may not read — another doctor's pre-exam visit, possibly roomless —
     * is never the visit this gate guards: it asks instead the visit the doctor
     * works from, chosen on the server (rule 243 §2.12, PR A4, closes §2.8 item
     * 8 (a)): the doctor's own live encounter, else the `source_visit_id` visit
     * when it is any RME visit of the doctor's own, else the doctor's latest own
     * pre-exam visit of the patient
     * ({@see PatientRmWorkspaceResolver::doctorWorkingVisit()}); else
     * nothing (no visit of theirs is queued or under examination; every write
     * still needs the doctor's own consented in-progress encounter,
     * RmeVisitConsentService). Unchanged with the flag
     * OFF, for every other account, on every other route, and whenever the URL
     * visit is readable or is not the anchor — a new patient's first visit IS
     * the doctor's own encounter and stays gated.
     */
    private function gatedVisit(Request $request, ?ClinicVisit $visit): ?ClinicVisit
    {
        $user = $request->user();

        if (! $visit instanceof ClinicVisit
            || ! $user instanceof User
            || ! in_array($request->route()?->getName(), self::WORKSPACE_ROUTES, true)) {
            return $visit;
        }

        $scope = app(DoctorPatientScopeService::class);

        if (! $scope->encounterScopeAppliesTo($user) || $scope->listsVisitFor($user, $visit)) {
            return $visit;
        }

        $workspace = app(PatientRmWorkspaceResolver::class);
        $patientId = (int) $visit->patient_id;

        if ((int) $workspace->resolveCanonicalWorkspaceVisit($patientId)?->getKey() !== (int) $visit->getKey()) {
            return $visit;
        }

        $doctor = $scope->resolveDoctorForUser($user);

        return $doctor === null
            ? null
            : $workspace->doctorWorkingVisit($patientId, (int) $doctor->getKey(), $request->integer('source_visit_id') ?: null);
    }

    /**
     * Resolve the clinic visit from the route. Most exam routes bind
     * {clinicVisit} directly; the odontogram update/finalize routes bind only
     * {odontogram}, so we fall back to the odontogram's owning visit.
     */
    private function resolveVisit(Request $request): ?ClinicVisit
    {
        $visit = $request->route('clinicVisit');

        if ($visit instanceof ClinicVisit) {
            return $visit;
        }

        $odontogram = $request->route('odontogram');

        return $odontogram?->clinicVisit;
    }
}
