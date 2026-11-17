<?php

namespace App\Modules\ClinicVisit\Middleware;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ActiveEncounterResolver;
use App\Modules\MedicalRecord\Services\PatientRmWorkspaceResolver;
use App\Modules\RME\Services\DoctorPatientScopeService;
use Closure;
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
            return redirect()
                ->route('rme.visits.show', $visit)
                ->with('error', 'Pasien belum ditempatkan ke ruangan perawatan.');
        }

        return $next($request);
    }

    /**
     * Rule 243 §2.11 (PR A3, review MEDIUM-1) — on the patient's RM workspace
     * routes the URL visit is the canonical ANCHOR, where the book is stored,
     * not necessarily the visit under examination. With the doctor encounter
     * scope applying to the user (flag ON, a scoped Doctor), an anchor the
     * doctor may not read — another doctor's pre-exam visit, possibly roomless —
     * is never the visit this gate guards: it asks instead the visit the doctor
     * works from, the request's `source_visit_id` (same patient, RME branch,
     * readable by the doctor), else the patient's live encounter when the doctor
     * may read it (their own), else nothing (no encounter of theirs is under
     * examination; every write still needs the doctor's own consented
     * in-progress encounter, RmeVisitConsentService). Unchanged with the flag
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

        $source = $workspace->resolveSourceVisit($patientId, $request->integer('source_visit_id') ?: null);

        if ($source !== null && $scope->listsVisitFor($user, $source)) {
            return $source;
        }

        $encounter = app(ActiveEncounterResolver::class)->currentFor($patientId);

        return $encounter !== null && $scope->listsVisitFor($user, $encounter) ? $encounter : null;
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
