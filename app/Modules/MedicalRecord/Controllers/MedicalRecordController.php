<?php

namespace App\Modules\MedicalRecord\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ActiveEncounterResolver;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\LegacyRme\Services\LegacyRmePatientHistoryService;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordHandwritingRepositoryInterface;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Requests\FinalizeMedicalRecordRequest;
use App\Modules\MedicalRecord\Requests\StoreMedicalRecordRequest;
use App\Modules\MedicalRecord\Requests\UpdateMedicalRecordRequest;
use App\Modules\MedicalRecord\Services\DiagnosisRolloutService;
use App\Modules\MedicalRecord\Services\MedicalRecordHandwritingService;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\MedicalRecord\Services\PatientRmWorkspaceResolver;
use App\Modules\MedicalRecord\Services\RmeWorkspaceDocumentPresenter;
use App\Modules\MedicalRecord\Services\RmeWorkspacePageSequencer;
use App\Modules\MedicalRecord\Services\UnifiedMedicalRecordIndexService;
use App\Modules\MedicalRecord\Support\UnifiedMedicalRecordSource;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Services\CrossBranchPatientLookupService;
use App\Modules\Patient\Services\PatientIdentityPresenter;
use App\Modules\PerformedTreatment\Services\RmePerformedTreatmentService;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MedicalRecordController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MedicalRecordService $service,
        private readonly MedicalRecordRepositoryInterface $medicalRecords,
        private readonly MedicalRecordHandwritingRepositoryInterface $handwritings,
        private readonly DiagnosisRolloutService $diagnosisRollout,
        // LEGACY-RME-PDF-HISTORY-1 — the patient's clinical history inside the
        // RME workspace. `$visits` supplies the canonical native side (the same
        // RME-branch-scoped definition the visit page uses, eager-loaded so the
        // list costs no per-row query); `$legacyHistory` folds in the published
        // archive under its own flag, permission, branch and doctor scope.
        private readonly ClinicVisitService $visits,
        private readonly LegacyRmePatientHistoryService $legacyHistory,
        // LEGACY-RME-DOCTOR-WORKSPACE-1 — the same documents as a selector at
        // the TOP of the workspace, so a published legacy archive no longer has
        // to be discovered by scrolling past the handwriting canvas.
        private readonly RmeWorkspaceDocumentPresenter $workspaceDocuments,
        // LEGACY-RME-DOCTOR-WORKSPACE-1A — merges the native handwriting book
        // and the published archive into ONE page sequence, so the archive is
        // reached with the same previous/next/swipe as a handwritten page.
        private readonly RmeWorkspacePageSequencer $workspacePages,
        // Rule 243 §2.11 (PR A3) — with the encounter scope ON, every list in the
        // workspace names only what the viewer may read.
        private readonly DoctorPatientScopeService $doctorScope,
        // Rule 243 §3 (memo M3.1) — the patient's identity as the viewer may see it.
        private readonly PatientIdentityPresenter $identities,
    ) {}

    /**
     * FEATURE-RME-MEDICAL-RECORDS-UNIFIED-NATIVE-LEGACY-1 — a unified,
     * patient-centric clinical read index: one row per patient holding a
     * native medical record OR a PUBLISHED legacy RME OR a PUBLISHED legacy
     * odontogram the actor may read. Read-only.
     */
    public function index(
        Request $request,
        CrossBranchPatientLookupService $rmLookup,
        UnifiedMedicalRecordIndexService $unified,
    ): View {
        $this->authorize('viewAny', MedicalRecord::class);

        $status = $request->string('status')->toString() ?: null;

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => in_array($status, MedicalRecord::STATUSES, true) ? $status : null,
            'visit_date_from' => $this->dateFilter($request->string('visit_date_from')->toString()),
            'visit_date_to' => $this->dateFilter($request->string('visit_date_to')->toString()),
            'source' => UnifiedMedicalRecordSource::normalize($request->string('source')->toString() ?: null),
        ];

        $result = $unified->index($request->user(), $filters);

        return view('rme.visits.medical-record.index', [
            'patients' => $result['patients'],
            'summary' => $result['summary'],
            'latestNative' => $result['latestNative'],
            'scope' => $result['scope'],
            'filters' => $filters,
            'statuses' => MedicalRecord::STATUSES,
            'sources' => UnifiedMedicalRecordSource::LABELS,
            'rmLookup' => $rmLookup->lookupByMedicalRecordNumberAcrossBranches($request->string('rm_lookup')->toString()),
        ]);
    }

    /**
     * Patient-keyed, read-only medical-record workspace. Works for a patient
     * with ZERO native records: it never assumes a medical record, a visit or
     * a native sheet exists, and never fabricates one. A patient outside the
     * actor's index answers 404, exactly like a patient that does not exist.
     */
    public function patient(Request $request, int $patientId, UnifiedMedicalRecordIndexService $unified, PatientRepositoryInterface $patients): View|RedirectResponse
    {
        $this->authorize('viewAny', MedicalRecord::class);

        // FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — a merged patient's
        // records now live under the canonical patient; open that workspace.
        // The canonical workspace applies its own authorization unchanged.
        $mergedInto = $patients->findById($patientId)?->merged_into_patient_id;

        if ($mergedInto !== null) {
            // Redirect ONLY when the actor may open the canonical workspace;
            // otherwise answer exactly as for an unknown patient, so the
            // redirect cannot reveal that the patient exists or where it went.
            abort_if($unified->patientWorkspace($request->user(), (int) $mergedInto) === null, 404);

            return redirect()->route('rme.medical-records.patients.show', $mergedInto)
                ->with('status', 'Pasien ini telah digabungkan. Anda dialihkan ke rekam medis pasien hasil penggabungan.');
        }

        $workspace = $unified->patientWorkspace($request->user(), $patientId);
        abort_if($workspace === null, 404);

        return view('rme.visits.medical-record.patient', $workspace);
    }

    /** A strict Y-m-d calendar date, or null — never an unparsed string in SQL. */
    private function dateFilter(string $value): ?string
    {
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }

    public function show(Request $request, ClinicVisit $clinicVisit, PatientRmWorkspaceResolver $workspace): View|RedirectResponse
    {
        $clinicVisit->loadMissing('patient');
        $patientId = $clinicVisit->patient_id;

        // Sprint 64.0 — patient-centric redirect. Any visit's RM URL resolves to
        // the patient's canonical workspace: the earliest non-cancelled RME visit
        // REGARDLESS of whether an MR exists yet (zero-MR fix). When the opened
        // visit is NOT canonical, bounce to the canonical URL carrying
        // source_visit_id so a new sheet defaults to the visit the doctor came
        // from. Deterministic anchor → no redirect loop. Null anchor only when
        // the opened visit is not an eligible RME-branch visit → 404 as before.
        // Check eligibility before authorize so non-RME visits 404 instead of 403.
        $canonicalVisit = $workspace->resolveCanonicalWorkspaceVisit($patientId);
        abort_if($canonicalVisit === null, 404);

        // Rule 243 §2.11 (PR A3) — the canonical address is the patient's
        // workspace (`viewWorkspace`: exactly the visit's `view` unless the
        // encounter scope applies, where the anchor is storage and the read
        // mode decides); any other visit's RM URL is that visit's own `view`.
        if ($canonicalVisit->id === $clinicVisit->id) {
            $this->authorize('viewWorkspace', [MedicalRecord::class, $clinicVisit]);
        } else {
            $this->authorize('view', $clinicVisit);
        }

        if ($canonicalVisit->id !== $clinicVisit->id) {
            return redirect()->route('rme.visits.medical-record.show', [
                $canonicalVisit,
                'source_visit_id' => $clinicVisit->id,
            ]);
        }

        $workspaceVisit = $canonicalVisit;
        $viewer = $request->user();

        // All sheets of the patient the viewer may read, chronological (swipe
        // order). Rule 243 §2.11 (PR A3) — with the encounter scope ON a doctor
        // sees the sheets MedicalRecordPolicy::view admits: the book in read mode
        // HISTORY, every other sheet by its own visit (OWN mode: own sheets only).
        $sheets = $workspace->sheetsForPatient($patientId, $viewer);

        // The visit the doctor opened the workspace from (IDOR-validated). Rule
        // 243 §2.11 — a visit the viewer may not read is never named as the
        // source, whatever the URL says.
        $sourceVisit = $workspace->resolveSourceVisit($patientId, $request->integer('source_visit_id') ?: null);
        if ($sourceVisit !== null && ! $this->doctorScope->listsVisitFor($viewer, $sourceVisit)) {
            $sourceVisit = null;
        }

        // LEGACY-RME-PDF-HISTORY-1 — one patient-centric clinical history for
        // this workspace: the patient's native RME visits with their PUBLISHED
        // legacy archive folded in, newest first, current encounter marked.
        //
        // The patient is the one already resolved from the authorized visit —
        // never a request parameter — and the legacy side re-checks its own
        // feature flag, permission, branch scope and doctor clinical scope, so
        // an operator who may not read the archive simply gets the native
        // history. Read-only: no visit and no medical record is created here.
        // LEGACY-RME-DOCTOR-WORKSPACE-1A — the patient's published archive is
        // resolved ONCE per request through the canonical read path (read
        // permissions + branch scope + doctor patient scope + published-only)
        // and then shared by every surface that needs it: the clinical history,
        // the document rail and the unified page sequence. Each resolving it
        // for itself would repeat the same query and invite the three surfaces
        // to drift apart about what this user may see.
        $legacyRecords = $this->legacyHistory->publishedRecordsFor($request->user(), $patientId);

        $clinicalHistory = $this->legacyHistory->patientClinicalHistoryFor(
            $request->user(),
            $patientId,
            // Rule 243 §2.6 — scoped to the viewer when the encounter scope is ON.
            $this->visits->patientVisitHistory($patientId, viewer: $request->user()),
            (int) ($sourceVisit?->getKey() ?? $workspaceVisit->getKey()),
            $legacyRecords,
        );

        // Sprint 64.0 zero-MR fix — the patient has visits but no RM sheet yet.
        // Render a safe empty-state workspace shell (no $medicalRecord) instead
        // of 404ing, with an opt-in "Buat Lembar RM Pertama" control for users
        // who may manage RME. The first sheet is created against the source
        // visit (the one the doctor came from) or the canonical visit otherwise.
        if ($sheets->isEmpty()) {
            $this->authorize('viewAny', MedicalRecord::class);

            $addSheetVisit = $workspaceVisit;
            $addSheetVisit->loadMissing(['patient', 'doctor', 'initialTreatment', 'branch']);

            // Rule 243 §2.11 — a doctor may see no sheet while the PATIENT has
            // some (OWN mode, or only sheets of visits they may not read). The
            // book is started only while the patient has none at all — store()
            // decides on that same fact — so nothing is offered then. With the
            // encounter scope not applying, $sheets is every sheet: no query.
            $patientHasSheets = $this->doctorScope->encounterScopeAppliesTo($viewer)
                && $workspace->sheetsForPatient($patientId)->isNotEmpty();

            // LEGACY-RME-DOCTOR-WORKSPACE-1A — a migrated patient whose only RME
            // is the archive still gets the real page experience: the unified
            // sequence here is purely legacy pages, navigated with the same
            // previous/next/swipe and the same `?rm_page=` index. The workspace
            // must not look empty just because the NATIVE sheet count is zero.
            $emptyStateWorkspacePages = $this->workspacePages->sequenceFor(
                $request->user(),
                $patientId,
                [],
                $legacyRecords,
            );
            $emptyStateTotalPages = $emptyStateWorkspacePages->count();
            $emptyStateActiveNumber = $emptyStateTotalPages > 0
                ? max(1, min($request->integer('rm_page') ?: 1, $emptyStateTotalPages))
                : 1;

            return view('rme.visits.medical-record.empty', [
                'workspacePages' => $emptyStateWorkspacePages,
                'activeWorkspacePage' => $this->workspacePages->resolveActivePage(
                    $emptyStateWorkspacePages,
                    $emptyStateActiveNumber,
                ),
                'activePageNumber' => $emptyStateActiveNumber,
                'totalRmPages' => max($emptyStateTotalPages, 1),
                'workspaceVisit' => $workspaceVisit,
                'sourceVisit' => $sourceVisit,
                'addSheetVisit' => $addSheetVisit,
                'patient' => $addSheetVisit->patient,
                // Who may manage RME here at all: permission, branch, clinical
                // scope. NOT "may they write right now" — that is the next key.
                // Rule 243 §2.11 — starting the book asks `startBook` while the
                // encounter scope applies, otherwise exactly `create` on the anchor.
                'canEdit' => ! $patientHasSheets
                    && ($viewer?->can($this->doctorScope->abilityFor($viewer, 'startBook', 'create'), [MedicalRecord::class, $workspaceVisit]) ?? false),
                // Rule 243 §2.11 — the anchor is named only to a viewer who may
                // read it (always with the encounter scope OFF).
                'workspaceVisitNamed' => $this->doctorScope->listsVisitFor($viewer, $workspaceVisit),
                /*
                 * BUGFIX-RME-PRECONSENT-FIRST-PAGE-UI-GATE-1 — the empty state
                 * joins the authority the populated workspace already uses.
                 *
                 * `canEdit` alone answered a narrower question than the button
                 * implied. MedicalRecordPolicy::create says nothing about
                 * consent, because consent is not a permission: it is a property
                 * of the encounter. So an in-progress unconsented visit rendered
                 * an actionable "Buat Halaman RM Pertama" that
                 * MedicalRecordService::createDraft() then refused — UI ALLOW,
                 * BACKEND DENY.
                 *
                 * Creating the first sheet is a current-RME write like any
                 * other, so it follows the same single authority the sheet-add
                 * control follows, under the same key. Presentation only; the
                 * service asserts the same gate, so a crafted POST is refused
                 * regardless of what this renders.
                 */
                'addSheetConsentRequired' => ! app(RmeVisitConsentService::class)
                    ->canAuthorRmeForPatient($patientId, $request->user()),
                // The encounter the doctor is actually in, if any — so the
                // refusal names the real reason rather than a generic lock.
                // Rule 243 §2.11 — never another doctor's encounter the viewer
                // may not read.
                'activeEncounter' => $this->encounterNamedFor($patientId, $viewer),
                'encounterBannerOwnOnly' => $this->doctorScope->encounterScopeAppliesTo($viewer),
                // A patient may hold a published legacy archive while having no
                // native RM sheet yet — the archive is exactly the history the
                // doctor needs here, so the empty state still shows it.
                'clinicalHistory' => $clinicalHistory,
                // LEGACY-RME-DOCTOR-WORKSPACE-1 — a migrated patient whose only
                // RME is the archive is precisely the case where the documents
                // must be reachable without any native sheet to hang them on.
                'workspaceDocuments' => $this->workspaceDocuments->documentsFor(
                    $request->user(),
                    $patientId,
                    $sheets,
                    null,
                    (int) $workspaceVisit->getKey(),
                    $legacyRecords,
                ),
            ] + $this->ptmBannerFor((int) $patientId, $request->user()));
        }

        // Active sheet: explicit ?sheet=, else the source visit's sheet, else the
        // canonical visit's sheet, else the first sheet. Always validated against
        // the patient's own sheet set (no cross-patient access).
        $activeSheet = null;
        if ($request->integer('sheet')) {
            $activeSheet = $sheets->firstWhere('id', $request->integer('sheet'));
        }
        if ($activeSheet === null && $sourceVisit !== null) {
            $activeSheet = $sheets->firstWhere('clinic_visit_id', $sourceVisit->id);
        }
        if ($activeSheet === null) {
            $activeSheet = $sheets->firstWhere('clinic_visit_id', $workspaceVisit->id) ?? $sheets->first();
        }

        $this->authorize('view', $activeSheet);

        // The active sheet's OWN visit drives the header + the update/finalize/
        // handwriting forms, so finalize transitions only that visit (gate intact).
        $medicalRecord = $activeSheet;
        $activeVisit = $activeSheet->clinicVisit;
        $activeVisit->loadMissing(['patient', 'doctor', 'initialTreatment', 'followUpOf', 'branch']);

        // "Tambah Lembar RM" target — the source visit when it has no sheet yet
        // AND it is the patient's current examination (DMS-RME-COMPREHENSIVE-
        // CLOSURE-1, OD-5-6): MedicalRecordService::createDraft() refuses a new
        // sheet on any other visit, so the page never offers one. Before consent
        // the button still renders disabled (addSheetConsentRequired below).
        $currentEncounter = app(ActiveEncounterResolver::class)->currentFor($patientId);
        $addSheetVisit = ($sourceVisit !== null
            && $sheets->firstWhere('clinic_visit_id', $sourceVisit->id) === null
            && $currentEncounter !== null
            && (int) $currentEncounter->getKey() === (int) $sourceVisit->getKey())
            ? $sourceVisit
            : null;

        $notice = ($sourceVisit !== null && $sourceVisit->id !== $workspaceVisit->id)
            ? 'Anda membuka buku RM tulisan tangan dari Kunjungan #'.$sourceVisit->visit_number.'. Halaman baru ditambahkan ke buku RM kunjungan pertama.'
            : null;

        // Sprint 64.0.2 — one patient = one handwriting RM book on the canonical
        // visit. Virtual navigation merges same-patient pages from all sheets;
        // new pages are always added to the canonical medical record.
        // Rule 240 §9.5 (memo M3.3 PR7) — a correction page's label as this
        // viewer may read it: the amended visit, its date and the approver only
        // when the viewer may open that visit (the service decides, per viewer).
        // Rule 243 §2.11 — only the pages of the sheets the viewer may read.
        $handwritingBook = app(RmeAmendmentService::class)->correctionPagesFor(
            $request->user(),
            $workspace->orderedHandwritingBookForPatient($patientId, $viewer),
        );
        $handwritingRecord = $workspace->canonicalMedicalRecord($patientId);

        // LEGACY-RME-DOCTOR-WORKSPACE-1A — ONE page navigation experience, two
        // data sources, built from the archive already resolved above.
        $workspacePages = $this->workspacePages->sequenceFor(
            $request->user(),
            $patientId,
            $handwritingBook,
            $legacyRecords,
        );

        // The global "Halaman X dari Y" counts the UNIFIED sequence: native
        // handwriting pages plus every authorized legacy archive page.
        $totalRmPages = max($workspacePages->count(), 1);

        $activePageNumber = $request->integer('rm_page')
            ?: (int) $request->session()->get('focus_rm_page', 1);
        $activePageNumber = max(1, min($activePageNumber, $totalRmPages));

        $activeWorkspacePage = $this->workspacePages->resolveActivePage($workspacePages, $activePageNumber);
        $isLegacyActivePage = $activeWorkspacePage?->isLegacy() ?? false;

        // The handwriting form, the canvas and "+ Tambah Halaman RM" are ALWAYS
        // bound to a real native record. On a read-only archive page there is
        // no native page under the cursor, so the nearest native page anchors
        // the form instead — archive evidence is never a save target.
        $nativeAnchorPage = $this->workspacePages->nativeAnchorFor($workspacePages, $activePageNumber);
        $nativeActivePageNumber = $nativeAnchorPage?->workspaceIndex ?? 1;

        $activeRmPage = ($nativeAnchorPage?->nativePage ?: null)
            ?? $handwritingBook->first()
            ?? [
                'page_number' => 1,
                'virtual_page_number' => 1,
                'storage_page_number' => 1,
                'is_legacy' => true,
                'preview_url' => null,
                'saved_at' => null,
                'has_content' => false,
                'medical_record_id' => $handwritingRecord?->id ?? $activeSheet->id,
                'clinic_visit_id' => $handwritingRecord?->clinic_visit_id ?? $activeSheet->clinic_visit_id,
            ];

        $handwritingFormRecord = MedicalRecord::query()->find($activeRmPage['medical_record_id'])
            ?? $handwritingRecord
            ?? $activeSheet;
        $handwritingFormVisit = $handwritingFormRecord->clinicVisit ?? $activeVisit;

        $rmPageNumbers = $workspacePages->pluck('workspaceIndex');
        if ($rmPageNumbers->isEmpty()) {
            $rmPageNumbers = collect([1]);
        }

        $nextRmPageNumber = $handwritingRecord !== null
            ? $this->handwritings->nextPageNumber($handwritingRecord->id)
            : 1;

        /*
         * Rule 240 §4 (memo M3.3) — a page the live encounter may not overwrite
         * (sealed by a finalize, or written by another encounter) renders
         * read-only with the service's reason, so the page never offers an
         * overwrite the service refuses (rule 135). Presentation only;
         * MedicalRecordHandwritingService::save() is the authority. An archive
         * page is read-only anyway. On such a page the shared editor targets only
         * the next, new page of the book — what "+ Tambah Halaman RM" opens.
         */
        $storagePageNumber = (int) ($activeRmPage['storage_page_number'] ?? $activePageNumber);
        $activePageLockMessage = $isLegacyActivePage ? null : app(MedicalRecordHandwritingService::class)->pageLockMessage(
            $handwritingFormRecord,
            $storagePageNumber,
            $currentEncounter !== null ? (int) $currentEncounter->getKey() : null,
        );
        $canonicalHandwritingFormAction = route('rme.visits.medical-record.handwriting.store', [
            $handwritingRecord?->clinicVisit ?? $workspaceVisit,
            $handwritingRecord ?? $handwritingFormRecord,
        ]);
        $editorFormAction = $activePageLockMessage !== null
            ? $canonicalHandwritingFormAction
            : route('rme.visits.medical-record.handwriting.store', [$handwritingFormVisit, $handwritingFormRecord]);
        $editorPageNumber = $activePageLockMessage !== null ? $nextRmPageNumber : $storagePageNumber;
        $editorRevision = $activePageLockMessage !== null
            ? MedicalRecord::HANDWRITING_REVISION_NONE
            : (string) ($activeRmPage['revision'] ?? '');

        $prevRmPage = $activePageNumber > 1 ? $activePageNumber - 1 : null;
        $nextRmPage = $activePageNumber < $totalRmPages ? $activePageNumber + 1 : null;

        // Rule 243 §2.5 (PR A1b, rule 135 mirror) — each control is gated on the
        // ability of the record it WRITES, decided here and not in Blade: a page
        // of the patient's BOOK on `appendPage` (patient-level), an in-place page
        // of a per-visit sheet on `update` of that sheet, "+ Tambah Lembar RM" on
        // `create` for the visit it creates on. With the encounter scope OFF
        // these answer exactly what `update` on the active sheet answered.
        $handwritingIsBook = $handwritingRecord !== null
            && (int) $handwritingRecord->getKey() === (int) $handwritingFormRecord->getKey();
        $canEditHandwritingTarget = $viewer?->can($handwritingIsBook ? 'appendPage' : 'update', $handwritingFormRecord) ?? false;
        $canAddSheet = $addSheetVisit !== null
            && ($viewer?->can('create', [MedicalRecord::class, $addSheetVisit]) ?? false);
        // Rule 243 §2.5 (PR A1c) — "+ Tambah Halaman RM" always writes a NEW page
        // to the patient's BOOK, whatever page is shown, so it follows
        // `appendPage` on the book, never the active page's record: on a page of
        // another doctor's sheet the in-place editor is refused while the book
        // still takes new pages. Without a book it posts to the form record,
        // exactly as the editor does.
        $canAppendBookPage = $handwritingRecord !== null
            ? ($viewer?->can('appendPage', $handwritingRecord) ?? false)
            : $canEditHandwritingTarget;

        // Rule 243 §2.11 (PR A3) — the book is read patient-level, but its own
        // (anchor) visit may be one the viewer may not read: another doctor's
        // pre-exam visit. Such a visit is never named in the sheet tabs or the
        // rail. Only ever the book; empty with the encounter scope OFF.
        $unnamedSheetIds = $sheets
            ->filter(fn (MedicalRecord $sheet): bool => $sheet->clinicVisit !== null
                && ! $this->doctorScope->listsVisitFor($viewer, $sheet->clinicVisit))
            ->map(fn (MedicalRecord $sheet): int => (int) $sheet->getKey())
            ->values()
            ->all();
        // Review HIGH-1 — and for the ACTIVE sheet the server decides, through
        // the policy, whether its anchor-bound content is readable: the visit
        // (header, details, follow-up alert, performed treatments) and
        // everything typed onto the sheet (SOAP, diagnoses and their rollout
        // state, status, recorded/finalized metadata). When it is not, only the
        // handwriting pages remain. Always true with the encounter scope OFF.
        $anchorContentReadable = $viewer?->can('viewAnchorContent', $activeSheet) ?? false;
        // Rule 243 §2.13 (PR A4, closes §2.8 item 8 (b)) — the workflow tabs show
        // their visit's room state and link its pages, so on an anchor the viewer
        // may not read they follow the visit the viewer came from (readable by
        // construction above), or are omitted. Always the active sheet's visit
        // with the encounter scope OFF.
        $workflowNavVisit = $anchorContentReadable ? $activeVisit : $sourceVisit;
        $activeFollowUpOf = $activeVisit->followUpOf !== null
            && $this->doctorScope->listsVisitFor($viewer, $activeVisit->followUpOf)
                ? $activeVisit->followUpOf
                : null;

        return view('rme.visits.medical-record.show', [
            'anchorContentReadable' => $anchorContentReadable,
            'workflowNavVisit' => $workflowNavVisit,
            'activeFollowUpOf' => $activeFollowUpOf,
            'unnamedSheetIds' => $unnamedSheetIds,
            'clinicVisit' => $activeVisit,
            'medicalRecord' => $medicalRecord,
            'workspaceVisit' => $workspaceVisit,
            'sheets' => $sheets,
            'activeSheet' => $activeSheet,
            'sourceVisit' => $sourceVisit,
            'addSheetVisit' => $addSheetVisit,
            'notice' => $notice,
            'patient' => $activeVisit->patient,
            // Rule 243 §3 (memo M3.1, PR C1) — the "Informasi Kunjungan" biodata
            // rows: for a Doctor the memo list only (no address, phone/WhatsApp,
            // email; never a NIK for anyone). Decided here, never in Blade.
            'patientIdentity' => $this->identities->forViewer($activeVisit->patient, $viewer),
            // Rule 243 §3 (PR C2) — the biodata grid a NEW page 1 bakes into its
            // PNG: the allowed fields only, whoever draws it.
            'rmPageOneBiodata' => $this->identities->rmPageOneBiodata($activeVisit->patient),
            'activeRmPage' => $activeRmPage,
            'activePageNumber' => $activePageNumber,
            'totalRmPages' => $totalRmPages,
            // LEGACY-RME-DOCTOR-WORKSPACE-1A — the unified sequence the page
            // navigation walks, plus the active page's explicit kind. The view
            // branches on `isLegacy()`; it never infers the kind from an index.
            'workspacePages' => $workspacePages,
            'activeWorkspacePage' => $activeWorkspacePage,
            'isLegacyActivePage' => $isLegacyActivePage,
            'nativeActivePageNumber' => $nativeActivePageNumber,
            'rmPageNumbers' => $rmPageNumbers,
            'nextRmPageNumber' => $nextRmPageNumber,
            'handwritingRecord' => $handwritingRecord,
            'handwritingFormRecord' => $handwritingFormRecord,
            'handwritingFormVisit' => $handwritingFormVisit,
            'activePageLockMessage' => $activePageLockMessage,
            'storagePageNumber' => $storagePageNumber,
            'canonicalHandwritingFormAction' => $canonicalHandwritingFormAction,
            'editorFormAction' => $editorFormAction,
            'editorPageNumber' => $editorPageNumber,
            'editorRevision' => $editorRevision,
            'canEditHandwritingTarget' => $canEditHandwritingTarget,
            'canAddSheet' => $canAddSheet,
            'canAppendBookPage' => $canAppendBookPage,
            'prevRmPage' => $prevRmPage,
            'nextRmPage' => $nextRmPage,
            'hasRequiredHandwriting' => $this->service->hasRequiredHandwriting($activeSheet),
            /*
             * FIX-02 / CORRECTIVE-02 — may this user author current RME right now?
             *
             * Resolved by POSITIVE authority: the patient must have an authorized,
             * consented examination in progress. Not "does this sheet's visit lack
             * consent" — that question let a terminal visit read as writable.
             *
             * Presentation only. Disabling a control is a courtesy; the service
             * asserts the same authority, so a crafted POST is refused regardless.
             */
            'canAuthorRme' => app(RmeVisitConsentService::class)
                ->canAuthorRmeForPatient($patientId, $request->user()),
            // The encounter the doctor is actually in, if any — drives the banner
            // and the "sign consent" link so both name the right visit. Rule 243
            // §2.11 — never another doctor's encounter the viewer may not read.
            'activeEncounter' => $this->encounterNamedFor($patientId, $viewer),
            'encounterBannerOwnOnly' => $this->doctorScope->encounterScopeAppliesTo($viewer),
            // Adding a sheet is a current-RME write like any other, so it follows
            // the same single authority rather than a per-visit predicate.
            'addSheetConsentRequired' => ! app(RmeVisitConsentService::class)
                ->canAuthorRmeForPatient($patientId, $request->user()),
            // SATUSEHAT-4B — branch-scoped rollout state for the active sheet
            // (drives the informational/warning/pilot banner + override form).
            'diagnosisEnforcement' => $anchorContentReadable ? $this->diagnosisRollout->enforcementStateFor($activeSheet) : null,
            // Rule 240 §6 (memo M3.3 PR4) — the "Amandemen" block of a final sheet
            // (null on a draft); the request offer is the service's own answer.
            // Rule 243 §2.11 — it describes the sheet's typed content, so it is
            // withheld with the rest of an unreadable anchor's content.
            'amendmentPanel' => $anchorContentReadable
                ? app(RmeAmendmentService::class)->panelFor($activeSheet, $request->user())
                : null,
            // FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — the treating
            // doctor's "Tindakan Dilakukan" record of the ACTIVE SHEET's visit; null
            // while the capability is OFF, so the page renders exactly as before.
            // Authoring controls mirror the service authority (never a permission).
            'performedTreatments' => $anchorContentReadable
                ? app(RmePerformedTreatmentService::class)->workspaceFor($activeVisit, $request->user())
                : null,
            // LEGACY-RME-PDF-HISTORY-1 — native RME history + published legacy
            // archive as one read-only patient-centric history.
            'clinicalHistory' => $clinicalHistory,
            // LEGACY-RME-DOCTOR-WORKSPACE-1 — the patient's selectable RME
            // documents (native sheets + published legacy archive) for the rail
            // at the top of the workspace. The native side is the sheet list
            // already resolved above, so the rail costs no extra sheet query.
            'workspaceDocuments' => $this->workspaceDocuments->documentsFor(
                $request->user(),
                $patientId,
                $sheets,
                (int) $activeSheet->getKey(),
                // Anchor on the CANONICAL workspace visit — the same anchor the
                // sheet navigation uses. Anchoring on a sheet's own visit would
                // trip the canonical redirect, which drops `sheet`.
                (int) $workspaceVisit->getKey(),
                $legacyRecords,
                $unnamedSheetIds,
            ),
            // DMS memo M1.1–M1.3 PR2 (rule 241 §2; MEMO-F5) — "Selesai
            // Pemeriksaan" in the header is offered only on an EXECUTED consent;
            // decided here, never by a service call in Blade.
            'clinicVisitConsentExecuted' => app(RmeVisitConsentService::class)->hasExecutedConsent($activeVisit),
        ] + $this->ptmBannerFor((int) $patientId, $request->user()));
    }

    /**
     * DMS memo M1.1–M1.3 PR2 (rule 241 §3; rule 135 mirror; MEMO-F5) — what the
     * RME authoring banner says when the account may not write: the PTM state of
     * the patient's live encounter (awaiting the patient / the doctor /
     * executed), and, for the treating doctor, the consent to countersign or the
     * reason they cannot. Presentation only; the services decide every write.
     * Rule 243 §2.11 — the encounter the banner may name (encounterNamedFor()):
     * under the doctor encounter scope, never another doctor's encounter the
     * viewer may not read, so its PTM state is not described either.
     *
     * @return array<string, mixed>
     */
    private function ptmBannerFor(int $patientId, ?User $viewer): array
    {
        $consents = app(RmeVisitConsentService::class);
        $encounter = $this->encounterNamedFor($patientId, $viewer);
        $outlook = $encounter !== null && $viewer !== null
            ? ($consents->countersignOutlookFor($viewer, [$encounter])[(int) $encounter->getKey()] ?? null)
            : null;

        return [
            'activeEncounterPtmState' => $consents->ptmStateFor($encounter),
            'activeEncounterCountersignConsent' => $outlook !== null && $outlook['refusal'] === null ? $outlook['consent'] : null,
            'activeEncounterCountersignRefusal' => $outlook['refusal'] ?? null,
        ];
    }

    /**
     * The patient's current examination for the consent banner. Rule 243 §2.11
     * (PR A3) — with the encounter scope ON, null when the viewer may not read
     * it (another doctor's live encounter), so the banner never names it; the
     * view then says no examination OF THE VIEWER is running, which is true
     * whether or not another doctor is examining.
     */
    private function encounterNamedFor(int $patientId, ?User $viewer): ?ClinicVisit
    {
        $encounter = app(ActiveEncounterResolver::class)->currentFor($patientId);

        return $encounter !== null && $this->doctorScope->listsVisitFor($viewer, $encounter) ? $encounter : null;
    }

    public function store(StoreMedicalRecordRequest $request, ClinicVisit $clinicVisit, PatientRmWorkspaceResolver $workspace): RedirectResponse
    {
        $patientId = $clinicVisit->patient_id;
        $canonicalVisit = $workspace->resolveCanonicalWorkspaceVisit($patientId) ?? $clinicVisit;
        // DMS-RME-REVISION-PROGRAM-5 (CW5-06, AIR-RMC-23) — the source visit is
        // recorded on the new sheet, so it goes through the same IDOR check the
        // show page applies: same patient, RME branch. Another patient's visit, or
        // an id that does not exist (an FK violation and an HTTP 500 on
        // PostgreSQL), falls back to the visit in the URL.
        $sourceVisitId = $workspace->resolveSourceVisit(
            (int) $patientId,
            $request->integer('source_visit_id') ?: null,
        )?->id ?? $clinicVisit->id;

        $patientHasSheets = MedicalRecord::query()
            ->where('patient_id', $patientId)
            ->whereIn('branch_id', $workspace->rmeBranchIds())
            ->exists();

        // Sprint 64.0.2 — the handwriting RM book starts on the canonical visit
        // when the patient has zero sheets. Per-visit "Tambah Lembar RM" (when
        // sheets already exist) still creates on the submitted visit.
        $targetVisit = $patientHasSheets ? $clinicVisit : $canonicalVisit;

        // Rule 243 §2.11 (PR A3) — the first sheet STARTS the book on the anchor
        // (`startBook`, the patient-level `appendPage` rule, only while the
        // encounter scope applies to the user; otherwise exactly `create`, as
        // before); a later sheet is `create` on its visit.
        $this->authorize(
            $patientHasSheets ? 'create' : $this->doctorScope->abilityFor($request->user(), 'startBook', 'create'),
            [MedicalRecord::class, $targetVisit],
        );

        $data = array_merge($request->validated(), ['source_visit_id' => $sourceVisitId]);

        if (! $patientHasSheets) {
            // DMS-RME-COMPREHENSIVE-CLOSURE-1 (R7-1-FS) — the first sheet is the
            // book: the service decides its visit under the canonical-visit lock,
            // adopts a book a concurrent first save created, and re-asserts the
            // policy for this user on the visit it locked.
            [, $created] = $this->service->startBook($clinicVisit, $request->user(), $data);
        } elseif ($this->medicalRecords->findByVisitId($targetVisit->id) === null) {
            $this->service->createDraft($targetVisit, auth()->id(), $data);
            $created = true;
        } else {
            $created = false;
        }

        $status = $created ? 'Rekam medis berhasil dibuat.' : 'Rekam medis sudah ada untuk kunjungan ini.';

        $redirectVisit = $workspace->resolveCanonicalWorkspaceVisit($patientId) ?? $targetVisit;
        $redirectParams = [$redirectVisit];
        if ($sourceVisitId !== $redirectVisit->id) {
            $redirectParams['source_visit_id'] = $sourceVisitId;
        }

        return redirect()
            ->route('rme.visits.medical-record.show', $redirectParams)
            ->with('status', $status);
    }

    public function update(UpdateMedicalRecordRequest $request, ClinicVisit $clinicVisit, MedicalRecord $medicalRecord): RedirectResponse
    {
        abort_if($medicalRecord->clinic_visit_id !== $clinicVisit->id, 404);

        // Rule 240 §1 — a final sheet is locked (the service re-decides it).
        $this->authorize('editSheet', $medicalRecord);

        $this->service->updateDraft($medicalRecord, $request->validated());

        return redirect()
            ->route('rme.visits.medical-record.show', $clinicVisit)
            ->with('status', 'Rekam medis berhasil diperbarui.');
    }

    public function finalize(FinalizeMedicalRecordRequest $request, ClinicVisit $clinicVisit, MedicalRecord $medicalRecord): RedirectResponse
    {
        abort_if($medicalRecord->clinic_visit_id !== $clinicVisit->id, 404);

        $this->authorize('finalize', $medicalRecord);

        $this->service->finalize($medicalRecord);

        return redirect()
            ->route('rme.visits.medical-record.show', $clinicVisit)
            ->with('status', 'Rekam medis berhasil difinalisasi.');
    }
}
