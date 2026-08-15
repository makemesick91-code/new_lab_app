<?php

/**
 * LEGACY-RME-PDF-1B — what may and may not become a staged archive document.
 *
 * The upload boundary decides on the file's OWN BYTES, never on the extension
 * or the client-declared Content-Type, and every date bound is recomputed
 * server-side through the 1A rule service.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyRme\Interfaces\LegacyRmeMalwareScannerInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Jobs\ProcessLegacyRmePdfImport;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Bus::fake();
});

function lrmeUploadPatient(string $nativeVisitDate = '2022-03-10'): Patient
{
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, $nativeVisitDate);

    return $patient;
}

function lrmeUpload(Patient $patient, ?UploadedFile $document = null, string $date = '2020-05-01'): LegacyRmeImport
{
    return app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        $date,
        null,
        $document ?? legacyRmePdfUpload(),
        superAdmin(),
    );
}

it('accepts a structurally valid pdf', function () {
    $patient = lrmeUploadPatient();

    $import = lrmeUpload($patient);

    expect($import->status)->toBe(LegacyRmeImportStatus::QUEUED)
        ->and($import->source_pdf_sha256)->toHaveLength(64)
        ->and($import->mime_type)->toBe('application/pdf')
        ->and($import->patient_id)->toBe($patient->id);
});

it('rejects a file that is not a pdf', function () {
    $patient = lrmeUploadPatient();
    $document = UploadedFile::fake()->createWithContent('catatan.txt', 'sekadar teks biasa');

    expect(fn () => lrmeUpload($patient, $document))->toThrow(ValidationException::class);
});

it('rejects a non-pdf that merely has a pdf extension', function () {
    $patient = lrmeUploadPatient();
    // Extension says PDF, bytes say otherwise — the bytes win.
    $document = UploadedFile::fake()->createWithContent('arsip.pdf', 'GIF89a bukan pdf sama sekali');

    expect(fn () => lrmeUpload($patient, $document))->toThrow(ValidationException::class);
});

it('rejects an empty file', function () {
    $patient = lrmeUploadPatient();
    $document = UploadedFile::fake()->createWithContent('arsip.pdf', '');

    expect(fn () => lrmeUpload($patient, $document))->toThrow(ValidationException::class);
});

it('rejects a file whose header is not the pdf magic', function () {
    $patient = lrmeUploadPatient();
    // Valid PDF body, but prefixed so it no longer STARTS with %PDF-.
    $document = UploadedFile::fake()->createWithContent('arsip.pdf', 'XX'.legacyRmePdfBytes());

    expect(fn () => lrmeUpload($patient, $document))->toThrow(ValidationException::class);
});

it('rejects a pdf larger than the configured limit', function () {
    config()->set('legacy_rme.upload.max_bytes', 512);

    $patient = lrmeUploadPatient();
    $document = legacyRmePdfUpload('besar.pdf', 40);

    expect(fn () => lrmeUpload($patient, $document))->toThrow(ValidationException::class);
});

it('stores nothing when the upload is rejected', function () {
    $patient = lrmeUploadPatient();
    $document = UploadedFile::fake()->createWithContent('arsip.pdf', 'bukan pdf');

    try {
        lrmeUpload($patient, $document);
    } catch (ValidationException) {
        // expected
    }

    expect(LegacyRmeImport::count())->toBe(0)
        ->and(Storage::disk('legacy_rme_private')->allFiles())->toBe([]);
});

it('refuses an upload while the feature flag is off', function () {
    legacyRmeArchiveFlag(false);
    $patient = lrmeUploadPatient();

    expect(fn () => lrmeUpload($patient))->toThrow(ValidationException::class);
    expect(LegacyRmeImport::count())->toBe(0);
});

it('refuses a legacy date that is not strictly before the earliest native RME date', function () {
    $patient = lrmeUploadPatient('2022-03-10');

    expect(fn () => lrmeUpload($patient, null, '2022-03-10'))->toThrow(ValidationException::class);
    expect(LegacyRmeImport::count())->toBe(0);
});

it('refuses a legacy date of today', function () {
    // LEGACY-RME-DATE-TZ-1 — "today" is the CLINICAL calendar day (Asia/Makassar),
    // not the process day. Deriving it from now() re-derived the boundary in the
    // technical frame, which the rule service is explicitly forbidden from doing,
    // and made this test fail for the ~8 hours a day when the two dates differ:
    // it then asserted against clinical YESTERDAY, which the strict `<` rule
    // correctly accepts, so the expected refusal never came.
    $clinicalToday = app(ClinicalClock::class)->todayString();
    $patient = lrmeUploadPatient(CarbonImmutable::parse($clinicalToday)->addDay()->toDateString());

    expect(fn () => lrmeUpload($patient, null, $clinicalToday))->toThrow(ValidationException::class);
});

it('refuses a legacy date before the patient birth date', function () {
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    expect(fn () => lrmeUpload($patient, null, '1989-12-31'))->toThrow(ValidationException::class);
});

// LEGACY-RME-PDF-FIX-ROLL2-1 — a patient with no native RME is the ordinary
// migration case and is accepted. No native encounter is manufactured for them.
it('accepts a patient with no native RME at all', function () {
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

    $import = lrmeUpload($patient, null, '2015-01-01');

    expect($import->status)->toBe(LegacyRmeImportStatus::QUEUED)
        ->and($import->selected_rme_date?->toDateString())->toBe('2015-01-01')
        // No native RME means no cutoff to snapshot — recorded as null, not faked.
        ->and($import->earliest_native_rme_date_snapshot)->toBeNull()
        // And the archive still creates no clinical encounter for the patient:
        // the patient has no native RME before the import and none after it.
        ->and(ClinicVisit::where('patient_id', $patient->getKey())->count())->toBe(0)
        ->and(MedicalRecord::where('patient_id', $patient->getKey())->count())->toBe(0);
});

it('stores the declared date range and collapses a single-date document', function () {
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

    $multi = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2024-01-28',
        null,
        legacyRmePdfUpload('multi.pdf'),
        superAdmin(),
        '2024-08-31',
    );

    $single = lrmeUpload($patient, legacyRmePdfUpload('single.pdf', 2), '2015-01-01');

    expect($multi->selected_rme_date?->toDateString())->toBe('2024-01-28')
        ->and($multi->latest_rme_date?->toDateString())->toBe('2024-08-31')
        ->and($single->latest_rme_date?->toDateString())->toBe('2015-01-01');
});

it('snapshots the server-computed cutoff rather than anything supplied', function () {
    $patient = lrmeUploadPatient('2022-03-10');

    $import = lrmeUpload($patient, null, '2020-05-01');

    expect($import->earliest_native_rme_date_snapshot?->toDateString())->toBe('2022-03-10');
});

it('refuses an origin branch that is not an RME branch', function () {
    $patient = lrmeUploadPatient();
    $branch = Branch::factory()->create([
        'is_rme_enabled' => false,
        'is_active' => true,
    ]);

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        $branch->id,
        legacyRmePdfUpload(),
        superAdmin(),
    ))->toThrow(ValidationException::class);
});

it('rejects an encrypted pdf during processing', function () {
    $this->app->instance(
        LegacyRmePdfInspectorInterface::class,
        (new FakeLegacyRmePdfInspector)->failWith(LegacyRmePdfFailure::PDF_ENCRYPTED),
    );

    $patient = lrmeUploadPatient();
    $import = lrmeUpload($patient);

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::FAILED)
        ->and($import->failure_code)->toBe(LegacyRmePdfFailure::PDF_ENCRYPTED);
});

it('rejects a password protected pdf during processing', function () {
    $this->app->instance(
        LegacyRmePdfInspectorInterface::class,
        (new FakeLegacyRmePdfInspector)->failWith(LegacyRmePdfFailure::PDF_PASSWORD_PROTECTED),
    );

    $patient = lrmeUploadPatient();
    $import = lrmeUpload($patient);

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    expect($import->refresh()->failure_code)->toBe(LegacyRmePdfFailure::PDF_PASSWORD_PROTECTED);
});

it('rejects a pdf whose page count exceeds the limit', function () {
    config()->set('legacy_rme.upload.max_pages', 3);

    $this->app->instance(
        LegacyRmePdfInspectorInterface::class,
        (new FakeLegacyRmePdfInspector)->withPages(10),
    );

    $patient = lrmeUploadPatient();
    $import = lrmeUpload($patient);

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    expect($import->refresh()->failure_code)->toBe(LegacyRmePdfFailure::PDF_PAGE_LIMIT_EXCEEDED);
});

it('rejects a pdf reporting zero pages', function () {
    $this->app->instance(
        LegacyRmePdfInspectorInterface::class,
        (new FakeLegacyRmePdfInspector)->withPages(0),
    );

    $patient = lrmeUploadPatient();
    $import = lrmeUpload($patient);

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    expect($import->refresh()->failure_code)->toBe(LegacyRmePdfFailure::PDF_PAGE_COUNT_INVALID);
});

it('rejects a pdf page larger than the dimension limit', function () {
    $this->app->instance(
        LegacyRmePdfInspectorInterface::class,
        (new FakeLegacyRmePdfInspector)->withPageSize(999999, 999999),
    );

    $patient = lrmeUploadPatient();
    $import = lrmeUpload($patient);

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    expect($import->refresh()->failure_code)->toBe(LegacyRmePdfFailure::PDF_DIMENSION_LIMIT_EXCEEDED);
});

it('records a process timeout as a stable failure code', function () {
    $this->app->instance(
        LegacyRmePdfInspectorInterface::class,
        (new FakeLegacyRmePdfInspector)->failWith(LegacyRmePdfFailure::PDF_PROCESS_TIMEOUT),
    );

    $patient = lrmeUploadPatient();
    $import = lrmeUpload($patient);

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    expect($import->refresh()->failure_code)->toBe(LegacyRmePdfFailure::PDF_PROCESS_TIMEOUT);
});

it('never claims a malware scan happened while the scanner is disabled', function () {
    config()->set('legacy_rme.processing.malware_scan.enabled', false);

    $result = app(LegacyRmeMalwareScannerInterface::class)
        ->scan(__FILE__);

    expect($result['scanned'])->toBeFalse()
        ->and($result['clean'])->toBeNull();
});

it('dispatches the processing job after a successful upload', function () {
    $patient = lrmeUploadPatient();

    $import = lrmeUpload($patient);

    Bus::assertDispatched(
        ProcessLegacyRmePdfImport::class,
        fn (ProcessLegacyRmePdfImport $job) => $job->importId === $import->getKey(),
    );
});

it('never dispatches a job when the upload is rejected', function () {
    $patient = lrmeUploadPatient();

    try {
        lrmeUpload($patient, UploadedFile::fake()->createWithContent('a.pdf', 'bukan pdf'));
    } catch (ValidationException) {
        // expected
    }

    Bus::assertNotDispatched(ProcessLegacyRmePdfImport::class);
});

it('sanitizes the stored original filename', function () {
    $patient = lrmeUploadPatient();

    $import = lrmeUpload(
        $patient,
        UploadedFile::fake()->createWithContent('../../etc/passwd.pdf', legacyRmePdfBytes()),
    );

    expect($import->original_filename)->not->toContain('/')
        ->and($import->original_filename)->not->toContain('..');
});
