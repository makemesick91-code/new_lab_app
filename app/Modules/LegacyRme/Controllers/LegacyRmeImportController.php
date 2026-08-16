<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeImportPage;
use App\Modules\LegacyRme\Requests\PublishLegacyRmeImportRequest;
use App\Modules\LegacyRme\Requests\StoreLegacyRmeImportRequest;
use App\Modules\LegacyRme\Services\LegacyRmeAuditService;
use App\Modules\LegacyRme\Services\LegacyRmeBranchAdmissionService;
use App\Modules\LegacyRme\Services\LegacyRmeBranchResolver;
use App\Modules\LegacyRme\Services\LegacyRmeImportLifecycleService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmePatientLookupService;
use App\Modules\LegacyRme\Services\LegacyRmeStorageService;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeImportPageStatus;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleAction;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use App\Modules\LegacyRme\Support\SeparatePublisherGuard;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * LEGACY-RME-PDF-1B — Master Data RME → Impor Arsip RME Lama.
 *
 * Thin by contract: it resolves, authorizes and hands off. Every rule lives in
 * a service (dates in 1A's LegacyRmeDateRuleService, intake in
 * LegacyRmeImportService, rendering in LegacyRmeImportProcessingService).
 *
 * A staged import is ALWAYS resolved through the repository with the caller's
 * server-resolved branch scope, so an id from the URL can never reach a row
 * outside that scope; the policy is then a second, independent gate.
 *
 * Nothing here rasterizes a PDF — that only ever happens in the queued job.
 *
 * LEGACY-RME-OPS-CLI-1 — THE FOUR LIFECYCLE ACTIONS SHARE ONE BUSINESS PATH.
 *
 * `retry`, `cancel`, `review` and `publish` no longer resolve, authorize and
 * delegate here; they hand the import id to LegacyRmeImportLifecycleService,
 * which is the SAME class the `legacy-rme:import-admin` command calls. That is
 * what makes "the CLI is not a weaker door" a fact rather than a promise: there
 * is one implementation of the scope resolution, the route permission, the
 * policy and the separation-of-duties rule, so the two surfaces cannot drift.
 *
 * The observable HTTP contract is unchanged. An import outside the caller's
 * branch scope still answers 404 (LegacyRmeImportNotInScope extends
 * NotFoundHttpException), a refused policy still answers 403, and a service
 * refusal still surfaces as a field error — the same statuses these actions
 * have always returned.
 */
class LegacyRmeImportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LegacyRmeImportRepositoryInterface $imports,
        private readonly LegacyRmeImportService $importService,
        private readonly LegacyRmePatientLookupService $patients,
        private readonly LegacyRmeStorageService $storage,
        private readonly LegacyRmeWorkspaceScope $scope,
        private readonly LegacyRmeAuditService $audit,
        private readonly LegacyRmeFeatureGuard $feature,
        private readonly LegacyRmeBranchResolver $branchResolver,
        private readonly LegacyRmeBranchAdmissionService $admission,
        private readonly LegacyRmeImportLifecycleService $lifecycle,
        private readonly SeparatePublisherGuard $separation,
    ) {}

    public function index(Request $request): View
    {
        $this->assertFeatureEnabled();
        $this->authorize('viewAny', LegacyRmeImport::class);

        $user = $request->user();

        $imports = $this->imports->paginateInBranches(
            $this->scope->branchIdsFor($user),
            [
                'status' => $request->string('status')->toString() ?: null,
                'patient' => $request->string('patient')->toString() ?: null,
            ],
            $this->scope->includesUnscopedRowsFor($user),
        );

        return view('settings.rme.legacy-imports.index', [
            'imports' => $imports,
            'statuses' => LegacyRmeImportStatus::ALL,
            'filters' => [
                'status' => $request->string('status')->toString(),
                'patient' => $request->string('patient')->toString(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->assertFeatureEnabled();
        $this->authorize('create', LegacyRmeImport::class);

        $lookup = null;
        $patient = null;
        $summary = null;

        $rm = $request->string('rm')->toString();

        if ($rm !== '') {
            $lookup = $this->patients->search($rm);
        }

        // Resolved through the lookup service so selecting by id is not a
        // weaker door into patient data than the search that produced the link.
        $patient = $this->patients->findSelectable($request->user(), $request->integer('patient_id'));

        $branchResolution = null;
        $admissionDecision = null;

        if ($patient !== null) {
            $summary = $this->patients->summarize($patient);

            // FIX-ROLL2-1: the branch is DERIVED from the patient's Nomor RM,
            // so the screen only ever DISPLAYS it. There is no branch picker to
            // populate any more — the same resolver runs again server-side on
            // store(), and that call is the boundary.
            $branchResolution = $this->branchResolver->resolveForPatient($patient, $request->user());

            // ROLL-3: show whether the RESOLVED branch is admitted to the
            // running wave, so a blocked operator reads why here instead of
            // discovering it after preparing and uploading a document. This is
            // presentation only — store() re-decides server-side, and that call
            // is the boundary.
            $admissionDecision = $this->admission->decide($branchResolution);
        }

        return view('settings.rme.legacy-imports.create', [
            'lookup' => $lookup,
            'rm' => $rm,
            'patient' => $patient,
            'summary' => $summary,
            'branchResolution' => $branchResolution,
            'admissionDecision' => $admissionDecision,
            // A convenience bound for the date picker only, and only when the
            // patient actually HAS a native RME — a patient without one has no
            // upper bound beyond "before today", which the server enforces.
            // Every rule is re-evaluated server-side regardless of what the
            // browser allowed.
            'maxSelectableDate' => $summary['earliest_native_rme_date'] ?? null,
        ]);
    }

    public function store(StoreLegacyRmeImportRequest $request): RedirectResponse
    {
        $this->assertFeatureEnabled();

        // Same scoped resolution as `create()`: the write path must never be a
        // weaker door into patient data than the screen that produced the form.
        $patient = $this->patients->findSelectable($request->user(), $request->integer('patient_id'));

        abort_if($patient === null, 404);

        $latestRmeDate = $request->string('latest_rme_date')->toString();

        $import = $this->importService->createFromUpload(
            $patient,
            $request->string('selected_rme_date')->toString(),
            // LEGACY-RME-SOURCE-RM-BINDING-1 — the Nomor RM the operator read on
            // the document, passed through verbatim. The controller neither
            // normalizes it nor compares it with the selected patient: the
            // binding is decided in the service, so a direct service call and a
            // CLI cannot be weaker doors than this one.
            $request->string('source_rm_raw')->toString(),
            // Passed only so a mismatch with the RM-derived branch is rejected
            // explicitly. It is never used as the answer.
            $request->input('origin_branch_id') !== null ? $request->integer('origin_branch_id') : null,
            $request->file('document'),
            $request->user(),
            $latestRmeDate !== '' ? $latestRmeDate : null,
        );

        return redirect()
            ->route('settings.rme.legacy-imports.show', $import->getKey())
            ->with('status', 'Dokumen berhasil diunggah dan sedang diproses.');
    }

    public function show(Request $request, int $import): View
    {
        $this->assertFeatureEnabled();

        $record = $this->resolve($request, $import);
        $this->authorize('view', $record);

        $actor = $request->user();

        return view('settings.rme.legacy-imports.show', [
            // `record` (LEGACY-RME-PDF-1C) is the published archive this import
            // produced, if any — used only to link to the final viewer.
            'import' => $record->load([
                'patient:id,name,medical_record_number',
                'originBranch:id,name,code',
                'uploadedBy:id,name',
                'record:id,source_import_id,status',
            ]),
            'pages' => $this->imports->pagesFor($record),

            // LEGACY-RME-SOD-1 — computed here, never in Blade. The view uses
            // these to explain WHY a checker action is unavailable instead of
            // rendering a button that is certain to be refused. They are a
            // courtesy, not a control: the server refuses the POST regardless,
            // twice (lifecycle gate 5, then again under the row lock).
            'separationBlocksReview' => $this->separation->violates(
                LegacyRmeLifecycleAction::REVIEW,
                $record,
                $actor,
            ),
            'separationBlocksPublish' => $this->separation->violates(
                LegacyRmeLifecycleAction::PUBLISH,
                $record,
                $actor,
            ),
            'separationMessage' => $this->separation->message(LegacyRmeLifecycleAction::PUBLISH),
        ]);
    }

    /**
     * Polling endpoint for the processing screen. Status only — no path, no
     * checksum, no patient identity.
     */
    public function status(Request $request, int $import): JsonResponse
    {
        $this->assertFeatureEnabled();

        $record = $this->resolve($request, $import);
        $this->authorize('view', $record);

        return response()->json([
            'status' => $record->status,
            'page_count' => $record->page_count,
            'failure_code' => $record->failure_code,
            'failure_message' => $record->failure_message,
            'processing_started_at' => $record->processing_started_at?->toIso8601String(),
            'processing_completed_at' => $record->processing_completed_at?->toIso8601String(),
            'is_ready' => $record->status === LegacyRmeImportStatus::READY_FOR_REVIEW,
            'is_failed' => $record->status === LegacyRmeImportStatus::FAILED,
        ]);
    }

    public function source(Request $request, int $import): StreamedResponse
    {
        $this->assertFeatureEnabled();

        $record = $this->resolve($request, $import);
        $this->authorize('viewFile', $record);

        $path = (string) $record->source_pdf_path;

        abort_unless($path !== '' && $this->storage->exists($path), 404);

        $this->audit->logImportEvent(LegacyRmeAuditEvent::SOURCE_VIEWED, $record, [], $request->user());

        // A generic download name: the stored original filename is operator
        // text and the storage path identifies a patient's archive, so neither
        // is ever echoed back to the browser.
        return $this->storage->disk()->response($path, 'arsip-rme-lama.pdf', [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="arsip-rme-lama.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function page(Request $request, int $import, int $page): StreamedResponse
    {
        $this->assertFeatureEnabled();

        $record = $this->resolve($request, $import);
        $this->authorize('viewFile', $record);

        // Nested ownership: the page is looked up THROUGH its import, so a page
        // number can never reach another import's rendered output.
        $pageRecord = $this->imports->findPage($record, $page);

        abort_if($pageRecord === null, 404);
        // LEGACY-RME-PDF-1C: viewable, not publishable. A published import's
        // pages move to PUBLISHED, and this screen is the operator's evidence
        // of what was reviewed — it must stay readable afterwards.
        abort_unless(LegacyRmeImportPageStatus::isViewable($pageRecord->status), 404);

        $wantsThumbnail = $request->string('variant')->toString() === 'thumbnail';
        $path = $this->resolvePagePath($pageRecord, $wantsThumbnail);

        abort_unless($path !== null && $this->storage->exists($path), 404);

        $this->audit->logImportEvent(LegacyRmeAuditEvent::PAGE_VIEWED, $record, [
            'page_number' => $page,
            'variant' => $wantsThumbnail ? 'thumbnail' : 'page',
        ], $request->user());

        return $this->storage->disk()->response($path, sprintf('halaman-%04d.png', $page), [
            'Content-Type' => 'image/png',
            'Content-Disposition' => sprintf('inline; filename="halaman-%04d.png"', $page),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function retry(Request $request, int $import): RedirectResponse
    {
        $this->assertFeatureEnabled();

        $outcome = $this->lifecycle->perform(
            $request->user(),
            $import,
            LegacyRmeLifecycleAction::RETRY,
            channel: LegacyRmeAuditEvent::CHANNEL_HTTP,
        );

        return redirect()
            ->route('settings.rme.legacy-imports.show', $outcome->importId)
            ->with('status', 'Dokumen dimasukkan kembali ke antrean pemrosesan.');
    }

    public function cancel(Request $request, int $import): RedirectResponse
    {
        $this->assertFeatureEnabled();

        $outcome = $this->lifecycle->perform(
            $request->user(),
            $import,
            LegacyRmeLifecycleAction::CANCEL,
            channel: LegacyRmeAuditEvent::CHANNEL_HTTP,
        );

        return redirect()
            ->route('settings.rme.legacy-imports.show', $outcome->importId)
            ->with('status', 'Impor dibatalkan.');
    }

    /**
     * LEGACY-RME-PDF-1C — mark a rendered import as reviewed by a human.
     *
     * The 1A transition map makes this a real gate: PUBLISHED is only reachable
     * from REVIEWED, so an unreviewed document can never be published.
     */
    public function review(Request $request, int $import): RedirectResponse
    {
        $this->assertFeatureEnabled();

        $outcome = $this->lifecycle->perform(
            $request->user(),
            $import,
            LegacyRmeLifecycleAction::REVIEW,
            channel: LegacyRmeAuditEvent::CHANNEL_HTTP,
        );

        return redirect()
            ->route('settings.rme.legacy-imports.show', $outcome->importId)
            ->with('status', 'Dokumen ditandai sudah ditinjau dan siap dipublikasikan.');
    }

    /**
     * LEGACY-RME-PDF-1C — publish a reviewed import as an immutable legacy RME
     * record.
     *
     * Everything that decides the outcome (patient, branch, historical date,
     * source file, rendered pages, status) is read from the staged row inside
     * the service's locked transaction; the request contributes only the two
     * optional archive labels.
     */
    public function publish(PublishLegacyRmeImportRequest $request, int $import): RedirectResponse
    {
        $this->assertFeatureEnabled();

        $outcome = $this->lifecycle->perform(
            $request->user(),
            $import,
            LegacyRmeLifecycleAction::PUBLISH,
            $request->archiveAttributes(),
            LegacyRmeAuditEvent::CHANNEL_HTTP,
        );

        return redirect()
            ->route('rme.legacy-records.show', $outcome->recordId)
            ->with('status', 'Arsip RME lama berhasil dipublikasikan ke riwayat pasien.');
    }

    /**
     * Resolve a staged import inside the caller's branch scope. Out of scope is
     * a 404, not a 403: an operator must not be able to probe which ids exist
     * in a branch they cannot see.
     */
    private function resolve(Request $request, int $id): LegacyRmeImport
    {
        $user = $request->user();

        $import = $this->imports->findByIdInBranches(
            $this->scope->branchIdsFor($user),
            $id,
            $this->scope->includesUnscopedRowsFor($user),
        );

        abort_if($import === null, 404);

        return $import;
    }

    private function resolvePagePath(LegacyRmeImportPage $page, bool $wantsThumbnail): ?string
    {
        if ($wantsThumbnail) {
            return is_string($page->thumbnail_path) && $page->thumbnail_path !== ''
                ? $page->thumbnail_path
                : null;
        }

        return is_string($page->background_path) && $page->background_path !== ''
            ? $page->background_path
            : null;
    }

    /**
     * The migration capability is behind a feature flag that defaults to OFF.
     * While it is off the capability simply does not exist — a 404, not a 403,
     * so the disabled surface reveals nothing about itself.
     *
     * LEGACY-RME-PDF-HISTORY-1A — this whole controller IS the migration
     * workspace (upload, staging, processing, retry, cancel, review, publish),
     * so every action here is correctly gated, including the staging reads: a
     * staged import is work in progress, not published clinical evidence. The
     * published archive a doctor reads lives in LegacyRmeRecordController and is
     * deliberately NOT gated on this flag.
     */
    private function assertFeatureEnabled(): void
    {
        abort_unless($this->feature->migrationEnabled(), 404);
    }
}
