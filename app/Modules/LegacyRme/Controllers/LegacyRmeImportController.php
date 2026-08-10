<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeImportPage;
use App\Modules\LegacyRme\Requests\StoreLegacyRmeImportRequest;
use App\Modules\LegacyRme\Services\LegacyRmeAuditService;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmePatientLookupService;
use App\Modules\LegacyRme\Services\LegacyRmeStorageService;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeImportPageStatus;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
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
 */
class LegacyRmeImportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LegacyRmeImportRepositoryInterface $imports,
        private readonly LegacyRmeImportService $importService,
        private readonly LegacyRmeImportProcessingService $processing,
        private readonly LegacyRmePatientLookupService $patients,
        private readonly LegacyRmeStorageService $storage,
        private readonly LegacyRmeWorkspaceScope $scope,
        private readonly LegacyRmeAuditService $audit,
        private readonly LegacyRmeFeatureGuard $feature,
        private readonly BranchService $branches,
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

        if ($patient !== null) {
            $summary = $this->patients->summarize($patient);
        }

        return view('settings.rme.legacy-imports.create', [
            'lookup' => $lookup,
            'rm' => $rm,
            'patient' => $patient,
            'summary' => $summary,
            // Only branches this operator may actually file an archive into —
            // `origin_branch_id` decides row ownership, so offering more would
            // invite a row the uploader could never manage. The server re-checks
            // regardless; this select is convenience, not the boundary.
            'branches' => $this->branches->listRmeEnabled()
                ->whereIn('id', $this->scope->branchIdsFor($request->user()))
                ->values(),
            // A convenience bound for the date picker only. The server
            // re-evaluates every rule regardless of what the browser allowed.
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

        $import = $this->importService->createFromUpload(
            $patient,
            $request->string('selected_rme_date')->toString(),
            $request->input('origin_branch_id') !== null ? $request->integer('origin_branch_id') : null,
            $request->file('document'),
            $request->user(),
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

        return view('settings.rme.legacy-imports.show', [
            'import' => $record->load(['patient:id,name,medical_record_number', 'originBranch:id,name,code', 'uploadedBy:id,name']),
            'pages' => $this->imports->pagesFor($record),
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
        abort_unless(LegacyRmeImportPageStatus::isPublishable($pageRecord->status), 404);

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

        $record = $this->resolve($request, $import);
        $this->authorize('retry', $record);

        $this->processing->retry($record, $this->importService, $request->user());

        return redirect()
            ->route('settings.rme.legacy-imports.show', $record->getKey())
            ->with('status', 'Dokumen dimasukkan kembali ke antrean pemrosesan.');
    }

    public function cancel(Request $request, int $import): RedirectResponse
    {
        $this->assertFeatureEnabled();

        $record = $this->resolve($request, $import);
        $this->authorize('cancel', $record);

        $this->processing->cancel($record, $request->user());

        return redirect()
            ->route('settings.rme.legacy-imports.show', $record->getKey())
            ->with('status', 'Impor dibatalkan.');
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
     * The archive is behind a feature flag that defaults to OFF. While it is
     * off the capability simply does not exist — a 404, not a 403, so the
     * disabled surface reveals nothing about itself.
     */
    private function assertFeatureEnabled(): void
    {
        abort_unless($this->feature->enabled(), 404);
    }
}
