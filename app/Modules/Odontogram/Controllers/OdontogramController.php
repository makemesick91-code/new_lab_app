<?php

namespace App\Modules\Odontogram\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPatientHistoryService;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Requests\UpdateOdontogramPlaceholderRequest;
use App\Modules\Odontogram\Services\OdontogramPrintFormatter;
use App\Modules\Odontogram\Services\OdontogramService;
use App\Modules\RME\Services\DoctorRoomScopeService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OdontogramController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OdontogramService $service,
        private readonly DoctorRoomScopeService $doctorRoomScope,
    ) {}

    public function show(
        Request $request,
        ClinicVisit $clinicVisit,
        OdontogramPrintFormatter $formatter,
        LegacyOdontogramPatientHistoryService $legacyHistoryService,
    ): View {

        $this->authorize('create', [Odontogram::class, $clinicVisit]);

        /*
         * POST-RME-ODONTOGRAM-STABILIZATION-1 / FIX-01 — OPENING THE CHART IS A
         * READ.
         *
         * This used to call getOrCreateForVisit(), so every GET — including one
         * by a view-only operator, and one on a visit whose consent forbids
         * authoring — INSERTed an empty trx_odontograms row. Viewing is not a
         * clinical act and must leave no clinical record behind.
         *
         * draftForVisit() returns the saved chart, or an UNSAVED instance when
         * nothing has been charted yet. The first real write goes through
         * store() below, which creates the row only after consent passes.
         */
        $odontogram = $this->service->draftForVisit($clinicVisit);

        $clinicVisit->loadMissing(['followUpOf.odontogram', 'patient', 'doctor']);

        $parentOdontogram = $clinicVisit->followUpOf?->odontogram;

        // Prev/next arrow navigation across the patient's visits. Scoped to the
        // visit, not to the odontogram, so it is unaffected by whether this
        // visit has been charted yet (Sprint 59).
        $adjacentVisits = app(ClinicVisitService::class)->adjacentVisits($clinicVisit);

        // Sprint 63.1.1 — read-only saved-result table rendered below the visual
        // preview. Reuses the Sprint 63.1 print formatter row-building logic so the
        // screen read-back and the print/PDF table stay in lockstep (no duplication,
        // no mutation, presentation-only).
        $structured = $formatter->format($odontogram);

        /*
         * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-04 — the patient's
         * PREVIOUS odontograms, read-only, below the active chart.
         *
         * The patient comes from the already-authorized visit, never from a
         * request parameter, and the service re-applies the RME branch set and the
         * doctor's clinical patient scope. The CURRENT visit is excluded so the
         * chart being edited is never also listed as its own history.
         */
        $odontogramHistory = $this->service->patientHistoryForVisit($clinicVisit, auth()->user());

        /*
         * FIX-04 — fold the PUBLISHED Legacy Odontogram archive into the same
         * read-only history, newest first alongside the native charts.
         *
         * The legacy side re-checks its own read permission, branch scope and
         * doctor clinical scope and returns an EMPTY collection for an operator
         * who may not read the archive, so a doctor without archive access simply
         * sees the native history. It is NOT gated by the migration feature flag:
         * a rollback must never remove a patient's history from their treating
         * doctor.
         */
        $odontogramHistory = $odontogramHistory
            ->concat($legacyHistoryService
                ->publishedRecordsFor($request->user(), (int) $clinicVisit->patient_id)
                ->map(fn (LegacyOdontogramRecord $record) => [
                    'source' => 'legacy',
                    'source_label' => 'Legacy',
                    'odontogram_id' => null,
                    'visit_id' => null,
                    'visit_number' => null,
                    'date' => $record->odontogram_date,
                    'branch_code' => $record->branch?->code,
                    'branch_name' => $record->branch?->name,
                    // The archive is a scan of a paper chart: it carries no
                    // structured tooth map and no attributable doctor. Never invent one.
                    'doctor_name' => null,
                    'status' => $record->status,
                    'dmft' => null,
                    'structured' => null,
                    'view_url' => route('rme.legacy-odontograms.show', $record),
                ]))
            // One clinical timeline, newest first, regardless of source.
            ->sortByDesc(fn (array $row) => $row['date'] instanceof \DateTimeInterface
                ? $row['date']->format('Y-m-d')
                : (string) $row['date'])
            ->values();

        /*
         * CORRECTIVE-03 — may this chart be EDITED right now?
         *
         * Presentation only. RmeVisitConsentService is the authority and refuses
         * the write itself, so a stale or crafted page cannot author anything;
         * this exists so the doctor is told why the controls are absent instead of
         * discovering it on submit.
         */
        $consents = app(RmeVisitConsentService::class);
        $odontogramAuthoringAllowed = $consents->canAuthorOdontogramFor($clinicVisit, $request->user());

        /*
         * REVISION-RME-CONSENT-ODONTOGRAM-PRECONSENT-EDIT-1 — an unsigned consent
         * no longer blocks THIS page, so the old "you may not chart yet" warning
         * would now be false. It is replaced by what is still true: the signature
         * is outstanding, and it still gates the RME and "Selesai Pemeriksaan".
         *
         * Saying so here is not decoration. The doctor can now complete the whole
         * chart without ever meeting the gate, and would otherwise first learn the
         * visit is stuck when they try to finish it.
         */
        $consentPendingForRme = $clinicVisit->status === ClinicVisit::STATUS_IN_PROGRESS
            && ! $consents->hasValidConsent($clinicVisit);

        return view('rme.visits.odontogram.show', compact(
            'clinicVisit',
            'odontogram',
            'parentOdontogram',
            'adjacentVisits',
            'structured',
            'odontogramHistory',
            'odontogramAuthoringAllowed',
            'consentPendingForRme',
        ));
    }

    /**
     * POST-RME-ODONTOGRAM-STABILIZATION-1 / FIX-01 — the FIRST save for a visit
     * that has no chart yet.
     *
     * Creation moved off the GET, so the write surface needs an entry point
     * that is not bound to a row which does not exist yet. This is visit-scoped
     * and lives inside the same `permission:manage_clinic_visits` + `visit.room`
     * group as update/finalize, so the Sprint 60.8 room gate and the manage
     * permission apply identically to a first chart and to a revision.
     *
     * Authorized with the `author` ability — the WRITE-level counterpart of
     * `create`, which is only view-level and is what the read page uses. The
     * service then re-asserts the signed consent before inserting anything.
     */
    public function store(UpdateOdontogramPlaceholderRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        $this->authorize('author', [Odontogram::class, $clinicVisit]);

        $this->service->saveForVisit($clinicVisit, $request->validated(), auth()->user());

        return redirect()
            ->route('rme.visits.odontogram.show', $clinicVisit)
            ->with('status', 'Odontogram berhasil disimpan.');
    }

    public function update(UpdateOdontogramPlaceholderRequest $request, Odontogram $odontogram): RedirectResponse
    {
        $this->authorize('update', $odontogram);

        $this->service->updatePlaceholder($odontogram, $request->validated(), auth()->user());

        return redirect()
            ->route('rme.visits.odontogram.show', $odontogram->clinicVisit)
            ->with('status', 'Odontogram berhasil disimpan.');
    }

    public function finalize(Odontogram $odontogram): RedirectResponse
    {
        $this->authorize('finalize', $odontogram);

        $this->service->finalize($odontogram, auth()->user());

        return redirect()
            ->route('rme.visits.odontogram.show', $odontogram->clinicVisit)
            ->with('status', 'Odontogram berhasil difinalisasi.');
    }

    public function print(Odontogram $odontogram, OdontogramPrintFormatter $formatter): View
    {
        // §23 — record a genuine Doctor attempt to print an odontogram before
        // the policy denies it. Controller-side so a hidden button never logs.
        $user = request()->user();

        if ($user !== null && $this->doctorRoomScope->deniesClinicalPrint($user)) {
            $this->doctorRoomScope->auditOdontogramPrintRejected($user, $odontogram);
        }

        $this->authorize('print', $odontogram);

        $odontogram->load(['clinicVisit.patient', 'clinicVisit.doctor', 'clinicVisit.branch', 'finalizer']);
        $clinicVisit = $odontogram->clinicVisit;

        $structured = $formatter->format($odontogram);

        return view('rme.visits.odontogram.print', compact('odontogram', 'clinicVisit', 'structured'));
    }
}
