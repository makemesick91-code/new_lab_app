<?php

/**
 * LEGACY-RME-PDF-1B — the queued rendering pipeline.
 *
 * Rendering only ever happens here, never in an HTTP request. The job must be
 * idempotent, retry-safe, leave no temporary file behind, and always land the
 * import in a truthful state.
 */

use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Jobs\ProcessLegacyRmePdfImport;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeImportPage;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\LegacyRme\Support\LegacyRmeImportPageStatus;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
});

function lrmeFakePipeline(int $pages = 3): void
{
    // app() and $this->app are the same container instance; the helper keeps
    // this usable from a plain function as well as from inside a test closure.
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages($pages));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages($pages));
}

function lrmeQueuedImport(int $pages = 3, string $date = '2020-05-01'): LegacyRmeImport
{
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    return app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        $date,
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload('arsip.pdf', $pages),
        superAdmin(),
    );
}

function lrmeProcess(int $importId): void
{
    app(LegacyRmeImportProcessingService::class)->process($importId);
}

it('renders every page and marks the import ready for review', function () {
    Bus::fake();
    lrmeFakePipeline(3);
    $import = lrmeQueuedImport(3);

    lrmeProcess($import->getKey());

    $import->refresh();

    expect($import->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW)
        ->and($import->page_count)->toBe(3)
        ->and($import->processing_started_at)->not->toBeNull()
        ->and($import->processing_completed_at)->not->toBeNull()
        ->and($import->failure_code)->toBeNull();
});

it('stores one ordered page row per page with a checksum', function () {
    Bus::fake();
    lrmeFakePipeline(3);
    $import = lrmeQueuedImport(3);

    lrmeProcess($import->getKey());

    $pages = LegacyRmeImportPage::where('legacy_import_id', $import->getKey())
        ->orderBy('page_number')->get();

    expect($pages)->toHaveCount(3)
        ->and($pages->pluck('page_number')->all())->toBe([1, 2, 3])
        ->and($pages->pluck('status')->unique()->all())->toBe([LegacyRmeImportPageStatus::READY])
        ->and($pages->every(fn ($page) => strlen((string) $page->background_sha256) === 64))->toBeTrue()
        ->and($pages->every(fn ($page) => ! empty($page->thumbnail_path)))->toBeTrue();
});

it('writes page images to the private disk only', function () {
    Bus::fake();
    lrmeFakePipeline(2);
    $import = lrmeQueuedImport(2);

    lrmeProcess($import->getKey());

    $files = Storage::disk('legacy_rme_private')->allFiles();

    expect(collect($files)->filter(fn ($f) => str_contains($f, '/pages/')))->toHaveCount(2)
        ->and(collect($files)->filter(fn ($f) => str_contains($f, '/thumbnails/')))->toHaveCount(2);
});

it('removes the temporary render directory when it finishes', function () {
    Bus::fake();
    lrmeFakePipeline(2);
    $import = lrmeQueuedImport(2);

    lrmeProcess($import->getKey());

    $leftovers = collect(Storage::disk('legacy_rme_private')->allFiles())
        ->filter(fn ($path) => str_contains($path, '/processing/'));

    expect($leftovers)->toBeEmpty();
});

it('removes the temporary render directory even when rendering fails', function () {
    Bus::fake();
    $this->app->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(2));
    $this->app->instance(
        LegacyRmePdfRasterizerInterface::class,
        (new FakeLegacyRmePdfRasterizer)->failWith(LegacyRmePdfFailure::PDF_RENDER_FAILED),
    );

    $import = lrmeQueuedImport(2);

    lrmeProcess($import->getKey());

    $leftovers = collect(Storage::disk('legacy_rme_private')->allFiles())
        ->filter(fn ($path) => str_contains($path, '/processing/'));

    expect($leftovers)->toBeEmpty()
        ->and($import->refresh()->status)->toBe(LegacyRmeImportStatus::FAILED);
});

it('fails when the renderer produces a different page count than the document', function () {
    Bus::fake();
    $this->app->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(5));
    $this->app->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(3));

    $import = lrmeQueuedImport(5);

    lrmeProcess($import->getKey());

    expect($import->refresh()->failure_code)->toBe(LegacyRmePdfFailure::PAGE_OUTPUT_COUNT_MISMATCH);
});

it('fails when the source pdf is missing from storage', function () {
    Bus::fake();
    lrmeFakePipeline(1);
    $import = lrmeQueuedImport(1);

    Storage::disk('legacy_rme_private')->delete($import->source_pdf_path);

    lrmeProcess($import->getKey());

    expect($import->refresh()->failure_code)->toBe(LegacyRmePdfFailure::SOURCE_FILE_MISSING);
});

it('is idempotent when the same job is delivered twice', function () {
    Bus::fake();
    lrmeFakePipeline(3);
    $import = lrmeQueuedImport(3);

    lrmeProcess($import->getKey());
    lrmeProcess($import->getKey());

    expect(LegacyRmeImportPage::where('legacy_import_id', $import->getKey())->count())->toBe(3)
        ->and($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);
});

it('does not process a cancelled import', function () {
    Bus::fake();
    lrmeFakePipeline(2);
    $import = lrmeQueuedImport(2);

    app(LegacyRmeImportProcessingService::class)->cancel($import, superAdmin());

    lrmeProcess($import->getKey());

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::CANCELLED)
        ->and(LegacyRmeImportPage::where('legacy_import_id', $import->getKey())->count())->toBe(0);
});

it('does not process an import that is already ready for review', function () {
    Bus::fake();
    lrmeFakePipeline(2);
    $import = lrmeQueuedImport(2);

    lrmeProcess($import->getKey());
    $completedAt = $import->refresh()->processing_completed_at;

    lrmeProcess($import->getKey());

    expect($import->refresh()->processing_completed_at->toIso8601String())
        ->toBe($completedAt->toIso8601String());
});

it('treats an unknown import id as a harmless no-op', function () {
    Bus::fake();
    lrmeFakePipeline(1);

    lrmeProcess(999999);
})->throwsNoExceptions();

it('clears pages left behind by a partially failed render before retrying', function () {
    Bus::fake();

    // A render that dies part-way leaves real page rows behind: the byte budget
    // is exhausted after the first page is already persisted.
    config()->set('legacy_rme.processing.max_render_bytes', 100);
    lrmeFakePipeline(4);
    $import = lrmeQueuedImport(4);

    lrmeProcess($import->getKey());

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::FAILED)
        ->and($import->failure_code)->toBe(LegacyRmePdfFailure::RENDER_SIZE_LIMIT_EXCEEDED)
        ->and(LegacyRmeImportPage::where('legacy_import_id', $import->getKey())->count())->toBeGreaterThan(0);

    // The retry renders a DIFFERENT page count, so a stale row would be visible
    // as an extra page rather than being silently overwritten.
    config()->set('legacy_rme.processing.max_render_bytes', 209715200);
    lrmeFakePipeline(2);
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(2));

    app(LegacyRmeImportProcessingService::class)
        ->retry($import->refresh(), app(LegacyRmeImportService::class), superAdmin());
    lrmeProcess($import->getKey());

    $pages = LegacyRmeImportPage::where('legacy_import_id', $import->getKey())->orderBy('page_number')->get();

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW)
        ->and($pages)->toHaveCount(2)
        ->and($pages->pluck('page_number')->all())->toBe([1, 2])
        ->and($import->page_count)->toBe(2);
});

it('refuses a retry on an import that already rendered successfully', function () {
    Bus::fake();
    lrmeFakePipeline(2);
    $import = lrmeQueuedImport(2);

    lrmeProcess($import->getKey());

    // READY_FOR_REVIEW cannot reach QUEUED in the 1A transition map, and this
    // sprint deliberately does not invent a transition to allow it: a rendered
    // document is corrected by cancelling and re-uploading.
    expect(fn () => app(LegacyRmeImportProcessingService::class)
        ->retry($import->refresh(), app(LegacyRmeImportService::class), superAdmin()))
        ->toThrow(ValidationException::class);
});

it('requeues a failed import on retry and then succeeds', function () {
    Bus::fake();
    $this->app->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(2));
    $this->app->instance(
        LegacyRmePdfRasterizerInterface::class,
        (new FakeLegacyRmePdfRasterizer)->failWith(LegacyRmePdfFailure::PDF_RENDER_FAILED),
    );

    $import = lrmeQueuedImport(2);
    lrmeProcess($import->getKey());

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::FAILED);

    lrmeFakePipeline(2);
    app(LegacyRmeImportProcessingService::class)->retry($import->refresh(), app(LegacyRmeImportService::class), superAdmin());

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::QUEUED)
        ->and($import->failure_code)->toBeNull();

    lrmeProcess($import->getKey());

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);
});

it('refuses a retry when the source pdf no longer exists', function () {
    Bus::fake();
    lrmeFakePipeline(1);
    $import = lrmeQueuedImport(1);

    // Drive the import to FAILED (the only status retry accepts) by removing
    // its source before the first pass runs.
    Storage::disk('legacy_rme_private')->delete($import->source_pdf_path);
    lrmeProcess($import->getKey());

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::FAILED);

    expect(fn () => app(LegacyRmeImportProcessingService::class)
        ->retry($import->refresh(), app(LegacyRmeImportService::class), superAdmin()))
        ->toThrow(ValidationException::class);
});

it('refuses a retry on a cancelled import', function () {
    Bus::fake();
    lrmeFakePipeline(1);
    $import = lrmeQueuedImport(1);

    app(LegacyRmeImportProcessingService::class)->cancel($import, superAdmin());

    expect(fn () => app(LegacyRmeImportProcessingService::class)
        ->retry($import->refresh(), app(LegacyRmeImportService::class), superAdmin()))
        ->toThrow(ValidationException::class);
});

it('marks the import failed when the queue exhausts its retries', function () {
    Bus::fake();
    lrmeFakePipeline(1);
    $import = lrmeQueuedImport(1);

    app(LegacyRmeImportProcessingService::class)->markFailedAfterExhaustedRetries($import->getKey());

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::FAILED)
        ->and($import->failure_code)->not->toBeNull();
});

it('never overwrites a terminal import when retries are exhausted', function () {
    Bus::fake();
    lrmeFakePipeline(1);
    $import = lrmeQueuedImport(1);

    app(LegacyRmeImportProcessingService::class)->cancel($import, superAdmin());
    app(LegacyRmeImportProcessingService::class)->markFailedAfterExhaustedRetries($import->getKey());

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::CANCELLED);
});

it('carries only the import id in the queued payload', function () {
    $job = new ProcessLegacyRmePdfImport(42);

    expect($job->importId)->toBe(42)
        ->and($job->queue)->toBe('legacy-rme-documents')
        ->and($job->uniqueId())->toBe('legacy-rme-import:42');

    // Nothing model- or byte-shaped may ride along in the payload.
    foreach (get_object_vars($job) as $value) {
        expect(is_object($value) && $value instanceof Model)->toBeFalse();
    }
});

it('runs the real job class end to end through the container', function () {
    Bus::fake();
    lrmeFakePipeline(2);
    $import = lrmeQueuedImport(2);

    app()->call([new ProcessLegacyRmePdfImport($import->getKey()), 'handle']);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);
});

it('never records a failure message containing a filesystem path', function () {
    Bus::fake();
    lrmeFakePipeline(1);
    $import = lrmeQueuedImport(1);

    Storage::disk('legacy_rme_private')->delete($import->source_pdf_path);
    lrmeProcess($import->getKey());

    $message = (string) $import->refresh()->failure_message;

    expect($message)->not->toContain('/')
        ->and($message)->not->toContain(storage_path());
});
