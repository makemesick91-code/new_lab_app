<?php

namespace App\Modules\Odontogram\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ActiveEncounterResolver;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPatientHistoryService;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Requests\UpdateOdontogramPlaceholderRequest;
use App\Modules\Odontogram\Services\OdontogramPrintFormatter;
use App\Modules\Odontogram\Services\OdontogramService;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RME\Services\DoctorRoomScopeService;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;
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
        private readonly DoctorPatientScopeService $doctorScope,
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
         * by a view-only operator, and one on a visit nobody may author —
         * INSERTed an empty trx_odontograms row. Viewing is not a
         * clinical act and must leave no clinical record behind.
         *
         * draftForVisit() returns the saved chart, or an UNSAVED instance when
         * nothing has been charted yet. The first real write goes through
         * store() below, which creates the row only after the authoring
         * authority passes (rule 134 — no consent condition).
         */
        $odontogram = $this->service->draftForVisit($clinicVisit);

        $clinicVisit->loadMissing(['followUpOf.odontogram', 'patient', 'doctor']);

        // Rule 243 §2.11 (PR A3b) — the parent card ("Odontogram Kunjungan
        // Sebelumnya") names the parent visit, so it appears only when the
        // viewer may open that visit (always with the encounter scope OFF).
        $parentOdontogram = $clinicVisit->followUpOf !== null
            && $this->doctorScope->listsVisitFor($request->user(), $clinicVisit->followUpOf)
                ? $clinicVisit->followUpOf->odontogram
                : null;

        // Prev/next arrow navigation across the patient's visits. Scoped to the
        // visit, not to the odontogram, so it is unaffected by whether this
        // visit has been charted yet (Sprint 59). Only a visit whose odontogram
        // page this viewer may open (the `create` ability authorized above) is
        // offered (DMS-RME-ADJACENT-VISITS-BRANCH-SCOPE-1, rule 227 §1.8c).
        $adjacentVisits = app(ClinicVisitService::class)->adjacentVisits(
            $clinicVisit,
            $request->user(),
            'create',
            Odontogram::class,
        );

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
         * DMS memo M1.1–M1.3 PR2 (rule 241 §2–§3; supersedes the
         * REVISION-RME-CONSENT-ODONTOGRAM-PRECONSENT-EDIT-1 reminder) — charting
         * now waits for BOTH PTM signatures. When the live encounter would be
         * chartable by this account but for the PTM, the page names that state
         * (awaiting the patient / the treating doctor) instead of offering a
         * form the server refuses; the treating doctor gets the countersign link,
         * or the reason they cannot sign (MESSAGE_COUNTERSIGN_SELF). The page
         * and the history stay readable either way. Decided here (MEMO-F5).
         */
        $odontogramPtmBlock = $consents->odontogramPtmBlockFor($clinicVisit, $request->user());
        // MEMO-F5 — "is this the live encounter's chart?", asked here rather than from Blade.
        $odontogramIsLiveEncounter = app(ActiveEncounterResolver::class)->isCurrentEncounter($clinicVisit);

        /*
         * Rule 240 §1.7 (memo M3.3) — which state banners the page shows, decided
         * here so the Blade only renders them. Each banner has one reason (an
         * account may see two, e.g. the front office on a finalized live chart
         * sees the lock and its own read-only banner, as before the lock):
         *
         *  - `lockedNotice`: a FINALIZED chart of the live encounter. It is locked
         *    (OdontogramPolicy::update and OdontogramService refuse it); a
         *    correction is a supervised amendment. It answers before the PTM
         *    (rule 240 §1.8): a finalized chart shows no PTM banner, because
         *    signing the PTM would not make it writable.
         *  - the PTM banner (rule 241 §3): a draft live chart this account may
         *    chart but for the PTM.
         *  - `readOnlyNotice = history`: a chart of a visit that is not the live
         *    encounter, finalized or not, shown to an account that could otherwise
         *    chart that visit. "Past visit" is the reason it cannot be written; a
         *    finalized history chart therefore shows the history banner, not the
         *    lock banner.
         *  - `readOnlyNotice = front_office`: the live encounter's chart, and the
         *    account itself may not chart it (CW5-05) — not merely the PTM.
         *
         * `author` is the scope-only twin of `update` for this visit's chart (manage
         * permission, RME branch, room, doctor-patient scope; no lock), so the
         * audience stays exactly the one the banners had before the lock.
         */
        $mayChartThisVisit = $request->user()?->can('author', [Odontogram::class, $clinicVisit]) ?? false;
        $odontogramLockedNotice = $odontogramIsLiveEncounter && $odontogram->isFinalized();
        $odontogramReadOnlyNotice = match (true) {
            ! $mayChartThisVisit => null,
            ! $odontogramIsLiveEncounter => 'history',
            ! $odontogramAuthoringAllowed && $odontogramPtmBlock === null => 'front_office',
            default => null,
        };

        if ($odontogram->isFinalized()) {
            $odontogramPtmBlock = null;
        }

        $countersignOutlook = $odontogramPtmBlock === RmeVisitConsentService::PTM_AWAITING_DOCTOR
            ? ($consents->countersignOutlookFor($request->user(), [$clinicVisit])[(int) $clinicVisit->getKey()] ?? null)
            : null;
        $countersignConsent = $countersignOutlook !== null && $countersignOutlook['refusal'] === null ? $countersignOutlook['consent'] : null;
        $countersignRefusal = $countersignOutlook['refusal'] ?? null;

        // When and by whom ANY finalized chart was finalized — live or history,
        // for every viewer, as before the lock. Clinic-local time (CG-12, WITA).
        $odontogramFinalizedLine = null;
        if ($odontogram->isFinalized() && $odontogram->finalized_at !== null) {
            $odontogramFinalizedLine = 'Difinalisasi pada '
                .CarbonImmutable::instance($odontogram->finalized_at)
                    ->setTimezone(app(ClinicalClock::class)->timezone())
                    ->format('d/m/Y H:i')
                .' WITA'
                .($odontogram->finalizer?->name !== null ? ' oleh '.$odontogram->finalizer->name : '')
                .'.';
        }

        // Rule 240 §6 (memo M3.3 PR4) — the "Amandemen" block of a finalized chart
        // (null on a draft or an unsaved chart); the request offer is the
        // service's own answer, so it binds Super Admin like the service does.
        $amendmentPanel = app(RmeAmendmentService::class)->panelFor($odontogram, $request->user());

        return view('rme.visits.odontogram.show', compact(
            'clinicVisit',
            'odontogram',
            'parentOdontogram',
            'adjacentVisits',
            'structured',
            'odontogramHistory',
            'odontogramAuthoringAllowed',
            'odontogramPtmBlock',
            'countersignConsent',
            'countersignRefusal',
            'odontogramLockedNotice',
            'odontogramReadOnlyNotice',
            'odontogramFinalizedLine',
            'amendmentPanel',
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
     * service then re-asserts the authoring authority (live encounter, actor,
     * chart; rule 134 — no consent condition) before inserting anything.
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
