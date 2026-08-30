<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramImportRepositoryInterface;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramPatientRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImportPage;
use App\Modules\LegacyOdontogram\Requests\LookupLegacyOdontogramPatientRequest;
use App\Modules\LegacyOdontogram\Requests\PublishLegacyOdontogramImportRequest;
use App\Modules\LegacyOdontogram\Requests\StoreLegacyOdontogramImportRequest;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramAuditService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramBranchBindingService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramDateRuleService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramFeatureGuard;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramImportService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPatientLookupService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramProcessingService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPublishService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramStorageService;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramAuditEvent;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportPageStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramWorkspaceScope;
use App\Modules\Patient\Models\Patient;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FIX-04b — Master Data RME → Impor Arsip Odontogram Lama (intake side).
 *
 * THIN BY CONSTRUCTION. Every rule — the date bounds, the branch derivation,
 * the PDF validation, the transitions, the publish atomicity — lives in a
 * service. This class resolves, authorizes, delegates and redirects.
 *
 * THREE GATES ON EVERY ACTION, in this order:
 *   1. the migration capability (404 when off, so a disabled surface reveals
 *      nothing about itself);
 *   2. `resolve()`, which is branch-scoped — an out-of-scope id is a 404, never
 *      a 403 that would confirm the row exists in a branch the caller cannot
 *      see;
 *   3. the policy, which adds the named permission and re-checks the scope.
 *
 * Unlike the published-record viewer, EVERY action here is part of migration —
 * including the reads, which show work in progress rather than clinical
 * evidence — so all of them sit behind the capability flag.
 */
class LegacyOdontogramImportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LegacyOdontogramImportRepositoryInterface $imports,
        private readonly LegacyOdontogramImportService $importService,
        private readonly LegacyOdontogramStorageService $storage,
        private readonly LegacyOdontogramWorkspaceScope $scope,
        private readonly LegacyOdontogramFeatureGuard $feature,
        private readonly LegacyOdontogramBranchBindingService $branchBinding,
        private readonly LegacyOdontogramDateRuleService $dateRules,
        private readonly LegacyOdontogramPatientLookupService $patientLookup,
        private readonly LegacyOdontogramPatientRepositoryInterface $patients,
        // Streaming a staged page emits full-resolution clinical bytes; the read
        // is audited, so this is a dependency of the controller, not an extra.
        private readonly LegacyOdontogramAuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->assertMigrationCapabilityEnabled();
        $this->authorize('viewAny', LegacyOdontogramImport::class);

        $user = $request->user();

        return view('settings.legacy-odontograms.index', [
            'imports' => $this->imports->paginateInBranches(
                $this->scope->branchIdsFor($user),
                [
                    'status' => $request->string('status')->toString() ?: null,
                    'patient' => $request->string('patient')->toString() ?: null,
                ],
                $this->scope->includesUnscopedRowsFor($user),
            ),
            'statuses' => LegacyOdontogramImportStatus::ALL,
            'filters' => [
                'status' => $request->string('status')->toString(),
                'patient' => $request->string('patient')->toString(),
            ],
        ]);
    }

    public function create(LookupLegacyOdontogramPatientRequest $request): View
    {
        $this->assertMigrationCapabilityEnabled();
        $this->authorize('create', LegacyOdontogramImport::class);

        $lookup = $this->patientLookup->lookup(
            $request->user(),
            $request->patientId(),
            $request->medicalRecordNumber(),
            $request->identifierSupplied(),
        );

        $branchResolution = null;
        $earliestNative = null;

        if ($lookup->isFound()) {
            // Re-read the row for the two DERIVED facts below. They are shown so
            // the operator sees the owning branch and the date ceiling BEFORE
            // uploading; both are re-derived server-side on store, so this is
            // guidance and never the decision.
            $patient = $this->resolvePatient($request->user(), $lookup->identity->id);

            if ($patient !== null) {
                $branchResolution = $this->branchBinding->resolveForPatient($patient, $request->user());
                $earliestNative = $this->dateRules->snapshotCutoff($patient);
            }
        }

        return view('settings.legacy-odontograms.create', [
            'lookup' => $lookup,
            // The RESOLVED patient's Nomor RM wins over whatever was typed: an
            // explicit `patient_id` takes precedence in the lookup, so echoing
            // the submitted `rm` unchanged would let a hand-edited URL label one
            // patient's card with another patient's identifier.
            'submittedMedicalRecordNumber' => $lookup->isFound()
                ? $lookup->identity->medicalRecordNumber
                : $request->medicalRecordNumber(),
            'branchResolution' => $branchResolution,
            'earliestNativeOdontogramDate' => $earliestNative,
        ]);
    }

    public function store(StoreLegacyOdontogramImportRequest $request): RedirectResponse
    {
        $this->assertMigrationCapabilityEnabled();

        /*
         * Re-resolved from the SUBMITTED id, never from whatever the previous
         * page happened to display. A patient shown on screen is an aid to the
         * operator, not a grant: the FormRequest has already re-checked that
         * this id exists and is not soft-deleted, and the branch binding below
         * re-checks that the caller may archive for that patient's branch.
         */
        // Read from the VALIDATED payload, never with `Request::integer()`.
        // It is already safe here — the FormRequest has enforced
        // `integer` + `exists` before this line runs — but rule 131 §4 forbids
        // `intval()`-family coercion on a patient identifier anywhere in this
        // module, and a rule the code contradicts on day one is worse than no
        // rule. `validated()` may still hand back a numeric string, so the cast
        // is explicit rather than implicit (this file is strict_types).
        $patient = $this->resolvePatient($request->user(), (int) $request->validated('patient_id'));

        abort_if($patient === null, 404);

        $import = $this->importService->createFromUpload(
            $patient,
            $request->string('selected_odontogram_date')->toString(),
            $request->file('document'),
            $request->user(),
        );

        return redirect()
            ->route('settings.rme.legacy-odontograms.show', $import->getKey())
            ->with('status', 'Dokumen odontogram lama berhasil diunggah dan sedang diproses.');
    }

    public function show(Request $request, int $import): View
    {
        $this->assertMigrationCapabilityEnabled();

        $staged = $this->resolve($request, $import);
        $this->authorize('view', $staged);

        return view('settings.legacy-odontograms.show', [
            'import' => $staged->load([
                'patient:id,name,medical_record_number',
                'originBranch:id,name,code',
                'uploadedBy:id,name',
                'reviewedBy:id,name',
                'publishedBy:id,name',
            ]),
            'pages' => $this->imports->pagesFor($staged),
            'record' => $staged->record,
        ]);
    }

    /**
     * Stream one rendered STAGING page for the review screen.
     *
     * The page is resolved THROUGH its already-scoped import, so neither an id
     * nor a page number can traverse to another patient's archive.
     */
    public function page(Request $request, int $import, int $page): StreamedResponse
    {
        $this->assertMigrationCapabilityEnabled();

        $staged = $this->resolve($request, $import);
        $this->authorize('viewFile', $staged);

        $pageRecord = $this->imports->findPage($staged, $page);

        abort_if($pageRecord === null, 404);
        abort_unless(LegacyOdontogramImportPageStatus::isViewable($pageRecord->status), 404);

        $wantsThumbnail = $request->string('variant')->toString() === 'thumbnail';
        $path = $this->resolvePagePath($pageRecord, $wantsThumbnail);

        $disk = $this->storage->diskFor($pageRecord->image_disk);

        abort_unless($path !== null && $disk->exists($path), 404);

        /*
         * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 — audit the read.
         *
         * Found by adversarial review: this streams FULL-RESOLUTION clinical
         * document bytes out of a private disk, and left no trail at all, so a
         * staged chart could be read repeatedly with nothing to show for it. The
         * sibling Legacy RME import controller already audits the same action.
         * Both variants are logged; the variant key distinguishes them, so the
         * thumbnail cannot become an un-audited read path.
         */
        $this->audit->logImportEvent(
            LegacyOdontogramAuditEvent::IMPORT_PAGE_VIEWED,
            $staged,
            ['page_number' => $page, 'variant' => $wantsThumbnail ? 'thumbnail' : 'full'],
            $request->user(),
        );

        return $disk->response($path, sprintf('halaman-%04d.png', $page), [
            'Content-Type' => 'image/png',
            'Content-Disposition' => sprintf('inline; filename="halaman-%04d.png"', $page),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function retry(Request $request, LegacyOdontogramProcessingService $processing, int $import): RedirectResponse
    {
        $this->assertMigrationCapabilityEnabled();

        $staged = $this->resolve($request, $import);
        $this->authorize('retry', $staged);

        $processing->retry($staged, $this->importService, $request->user());

        return redirect()
            ->route('settings.rme.legacy-odontograms.show', $staged->getKey())
            ->with('status', 'Dokumen diproses ulang.');
    }

    public function cancel(Request $request, LegacyOdontogramProcessingService $processing, int $import): RedirectResponse
    {
        $this->assertMigrationCapabilityEnabled();

        $staged = $this->resolve($request, $import);
        $this->authorize('cancel', $staged);

        $processing->cancel($staged, $request->user());

        return redirect()
            ->route('settings.rme.legacy-odontograms.index')
            ->with('status', 'Impor arsip odontogram lama dibatalkan.');
    }

    public function review(Request $request, LegacyOdontogramPublishService $publisher, int $import): RedirectResponse
    {
        $this->assertMigrationCapabilityEnabled();

        $staged = $this->resolve($request, $import);
        $this->authorize('review', $staged);

        $publisher->review($staged, $request->user());

        return redirect()
            ->route('settings.rme.legacy-odontograms.show', $staged->getKey())
            ->with('status', 'Arsip ditandai sudah direview dan siap dipublikasikan.');
    }

    public function publish(PublishLegacyOdontogramImportRequest $request, LegacyOdontogramPublishService $publisher, int $import): RedirectResponse
    {
        $this->assertMigrationCapabilityEnabled();

        $staged = $this->resolve($request, $import);
        $this->authorize('publish', $staged);

        $record = $publisher->publish($staged, $request->archiveAttributes(), $request->user());

        return redirect()
            ->route('rme.legacy-odontograms.show', $record->getKey())
            ->with('status', 'Arsip odontogram lama dipublikasikan dan kini bersifat permanen (read-only).');
    }

    /**
     * Out of scope is a 404 rather than a 403: an operator must not be able to
     * probe which archive ids exist in a branch they cannot see.
     */
    private function resolve(Request $request, int $id): LegacyOdontogramImport
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

    /**
     * The patient row behind an ALREADY-VALIDATED surrogate key.
     *
     * Routed through the repository rather than `Patient::query()->find()` so
     * there is exactly one door to `mst_patients` in this module, it projects
     * identity columns only, and it can never resolve a soft-deleted patient.
     *
     * Resolving a patient here is NOT authorization: the owning branch is
     * derived from the patient's own Nomor RM and re-checked against the
     * caller's scope by LegacyOdontogramBranchBindingService before anything is
     * stored.
     */
    private function resolvePatient(?User $actor, int $patientId): ?Patient
    {
        return $this->patients->findSelectableById($actor, $patientId);
    }

    private function resolvePagePath(LegacyOdontogramImportPage $page, bool $wantsThumbnail): ?string
    {
        if ($wantsThumbnail) {
            return is_string($page->thumbnail_path) && $page->thumbnail_path !== ''
                ? $page->thumbnail_path
                : null;
        }

        return is_string($page->image_path) && $page->image_path !== ''
            ? $page->image_path
            : null;
    }

    /**
     * A 404 rather than a 403, so a disabled migration capability reveals
     * nothing about itself.
     */
    private function assertMigrationCapabilityEnabled(): void
    {
        abort_unless($this->feature->migrationEnabled(), 404);
    }
}
