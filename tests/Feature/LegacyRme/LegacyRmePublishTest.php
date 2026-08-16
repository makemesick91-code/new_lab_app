<?php

/**
 * LEGACY-RME-PDF-1C — controlled publish.
 *
 * Publishing turns a reviewed staging import into an IMMUTABLE legacy RME
 * record. These tests pin the four properties that make that safe: it is only
 * reachable after a human review, it re-validates the historical date, it is
 * atomic and idempotent, and it creates no downstream clinical or financial
 * transaction whatsoever.
 */

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecordPage;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmePublishService;
use App\Modules\LegacyRme\Services\LegacyRmeStorageService;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeImportPageStatus;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Bus::fake();
});

/**
 * A real, fully rendered import sitting at READY_FOR_REVIEW with its source PDF
 * and page images actually present on the fake private disk — the exact state a
 * publish is supposed to consume.
 */
function lrme1cReadyImport(int $pages = 2, string $legacyDate = '2020-05-01'): LegacyRmeImport
{
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages($pages));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages($pages));

    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        $legacyDate,
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload('arsip.pdf', $pages),
        superAdmin(),
    );

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    return $import->refresh();
}

function lrme1cReviewed(int $pages = 2, string $legacyDate = '2020-05-01'): LegacyRmeImport
{
    $import = lrme1cReadyImport($pages, $legacyDate);

    return app(LegacyRmePublishService::class)->review($import, superAdmin())->refresh();
}

/*
|--------------------------------------------------------------------------
| Happy path
|--------------------------------------------------------------------------
*/

it('publishes a reviewed import into an immutable legacy record with its pages', function () {
    $import = lrme1cReviewed(3);
    $actor = superAdmin();

    $record = app(LegacyRmePublishService::class)->publish($import, ['title' => 'RM Lama 2020'], $actor);

    expect($record->status)->toBe(LegacyRmeRecord::STATUS_PUBLISHED)
        ->and($record->source_import_id)->toBe($import->getKey())
        ->and($record->patient_id)->toBe($import->patient_id)
        ->and($record->page_count)->toBe(3)
        ->and($record->title)->toBe('RM Lama 2020')
        ->and($record->published_by)->toBe($actor->getKey())
        ->and($record->published_at)->not->toBeNull()
        // The historical date carries over verbatim — never the upload time.
        ->and($record->rme_date->toDateString())->toBe('2020-05-01');

    expect(LegacyRmeRecordPage::where('rme_legacy_record_id', $record->getKey())->count())->toBe(3);

    // The staging row is terminal and the pages are archived, not pending.
    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
    expect($import->pages()->pluck('status')->unique()->all())->toBe([LegacyRmeImportPageStatus::PUBLISHED]);
});

it('points the published record at the same private paths, moving no bytes', function () {
    $import = lrme1cReviewed(2);
    $storage = app(LegacyRmeStorageService::class);

    $record = app(LegacyRmePublishService::class)->publish($import, [], superAdmin());

    expect($record->source_pdf_path)->toBe($import->refresh()->source_pdf_path)
        ->and($storage->exists($record->source_pdf_path))->toBeTrue();

    foreach ($record->pages as $page) {
        expect($storage->exists($page->background_path))->toBeTrue();
        // Never a public disk, never a public URL.
        expect($page->background_disk)->not->toBe('public');
    }
});

it('defaults the archive title when the operator supplies none', function () {
    $record = app(LegacyRmePublishService::class)->publish(lrme1cReviewed(1), [], superAdmin());

    expect($record->title)->toBe('Arsip RME Lama')
        ->and($record->description)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Invalid state — publish is only reachable through REVIEWED
|--------------------------------------------------------------------------
*/

it('refuses to publish an import that has not been reviewed', function () {
    $import = lrme1cReadyImport(1);

    expect($import->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);

    expect(fn () => app(LegacyRmePublishService::class)->publish($import, [], superAdmin()))
        ->toThrow(ValidationException::class);

    expect(LegacyRmeRecord::count())->toBe(0)
        ->and($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);
});

it('refuses to publish from every non-reviewed status', function (string $status) {
    $import = lrme1cReadyImport(1);
    $import->forceFill(['status' => $status])->save();

    expect(fn () => app(LegacyRmePublishService::class)->publish($import->refresh(), [], superAdmin()))
        ->toThrow(ValidationException::class);

    expect(LegacyRmeRecord::count())->toBe(0);
})->with([
    LegacyRmeImportStatus::DRAFT,
    LegacyRmeImportStatus::UPLOADED,
    LegacyRmeImportStatus::QUEUED,
    LegacyRmeImportStatus::PROCESSING,
    LegacyRmeImportStatus::FAILED,
    LegacyRmeImportStatus::CANCELLED,
]);

it('refuses to review an import that is not ready for review', function () {
    $import = lrme1cReadyImport(1);
    $import->forceFill(['status' => LegacyRmeImportStatus::PROCESSING])->save();

    expect(fn () => app(LegacyRmePublishService::class)->review($import->refresh(), superAdmin()))
        ->toThrow(ValidationException::class);
});

it('treats a repeated review as a harmless no-op', function () {
    $import = lrme1cReviewed(1);
    $reviewedAt = $import->reviewed_at;

    $again = app(LegacyRmePublishService::class)->review($import, superAdmin());

    expect($again->status)->toBe(LegacyRmeImportStatus::REVIEWED)
        ->and($again->reviewed_at->toDateTimeString())->toBe($reviewedAt->toDateTimeString());
});

/*
|--------------------------------------------------------------------------
| Idempotency and duplicate protection
|--------------------------------------------------------------------------
*/

it('produces exactly one record when publish is called twice', function () {
    $import = lrme1cReviewed(2);
    $service = app(LegacyRmePublishService::class);

    $first = $service->publish($import, [], superAdmin());
    $second = $service->publish($import->refresh(), [], superAdmin());

    expect($second->getKey())->toBe($first->getKey())
        ->and(LegacyRmeRecord::count())->toBe(1)
        ->and(LegacyRmeRecordPage::count())->toBe(2);
});

it('lets the database refuse a second record for the same import', function () {
    $import = lrme1cReviewed(1);

    app(LegacyRmePublishService::class)->publish($import, [], superAdmin());

    // Bypass every application check and go straight at the table: the
    // UNIQUE(source_import_id) constraint is the last line of defence.
    //
    // The insert runs inside its own nested transaction (a SAVEPOINT) so that
    // PostgreSQL, which aborts the whole transaction on a constraint violation,
    // only unwinds to that savepoint — leaving the surrounding test transaction
    // usable for the assertion that follows. On SQLite it behaves identically.
    expect(fn () => DB::transaction(fn () => LegacyRmeRecord::factory()->create([
        'source_import_id' => $import->getKey(),
        'patient_id' => $import->patient_id,
    ])))->toThrow(QueryException::class);

    expect(LegacyRmeRecord::count())->toBe(1);
});

it('rolls back completely when the transaction fails midway', function () {
    $import = lrme1cReviewed(2);

    // Force a failure after the record and its first page were written.
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'trx_rme_legacy_record_pages') && str_starts_with(strtolower($query->sql), 'insert')) {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => app(LegacyRmePublishService::class)->publish($import, [], superAdmin()))
        ->toThrow(RuntimeException::class);

    expect(LegacyRmeRecord::count())->toBe(0)
        ->and(LegacyRmeRecordPage::count())->toBe(0)
        ->and($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
});

/*
|--------------------------------------------------------------------------
| Revalidation at publish time
|--------------------------------------------------------------------------
*/

it('re-validates the legacy date against a freshly resolved cutoff', function () {
    $import = lrme1cReviewed(1, '2020-05-01');

    // The patient gains an EARLIER native encounter between upload and publish,
    // so the once-valid legacy date now overlaps real native history.
    legacyRmeNativeVisit($import->patient, '2019-01-01');

    expect(fn () => app(LegacyRmePublishService::class)->publish($import, [], superAdmin()))
        ->toThrow(ValidationException::class);

    expect(LegacyRmeRecord::count())->toBe(0);
});

it('records why a publish was rejected without leaking patient content', function () {
    $import = lrme1cReviewed(1, '2020-05-01');
    legacyRmeNativeVisit($import->patient, '2019-01-01');

    try {
        app(LegacyRmePublishService::class)->publish($import, [], superAdmin());
    } catch (ValidationException) {
        // expected
    }

    $log = DB::table('sys_audit_logs')
        ->where('action', LegacyRmeAuditEvent::PUBLISH_REJECTED)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();

    $payload = (string) ($log->new_values ?? '');

    expect($payload)->toContain('rule_code')
        ->and($payload)->not->toContain($import->patient->name)
        ->and($payload)->not->toContain('rme-legacy/');
});

/*
|--------------------------------------------------------------------------
| Rendered output must be complete and still on disk
|--------------------------------------------------------------------------
*/

it('refuses to publish when a rendered page file has gone missing', function () {
    $import = lrme1cReviewed(2);
    $page = $import->pages()->orderBy('page_number')->first();

    Storage::disk('legacy_rme_private')->delete($page->background_path);

    expect(fn () => app(LegacyRmePublishService::class)->publish($import, [], superAdmin()))
        ->toThrow(ValidationException::class);

    expect(LegacyRmeRecord::count())->toBe(0);
});

it('refuses to publish when the source pdf has gone missing', function () {
    $import = lrme1cReviewed(1);

    Storage::disk('legacy_rme_private')->delete($import->source_pdf_path);

    expect(fn () => app(LegacyRmePublishService::class)->publish($import, [], superAdmin()))
        ->toThrow(ValidationException::class);

    expect(LegacyRmeRecord::count())->toBe(0);
});

it('refuses to publish when a page is not READY', function () {
    $import = lrme1cReviewed(2);
    $import->pages()->orderBy('page_number')->first()
        ->forceFill(['status' => LegacyRmeImportPageStatus::FAILED])->save();

    expect(fn () => app(LegacyRmePublishService::class)->publish($import->refresh(), [], superAdmin()))
        ->toThrow(ValidationException::class);

    expect(LegacyRmeRecord::count())->toBe(0);
});

it('refuses to publish when fewer pages were rendered than the document declares', function () {
    $import = lrme1cReviewed(2);
    // Truncating the archive would silently drop clinical content.
    $import->pages()->orderByDesc('page_number')->first()->delete();

    expect(fn () => app(LegacyRmePublishService::class)->publish($import->refresh(), [], superAdmin()))
        ->toThrow(ValidationException::class);

    expect(LegacyRmeRecord::count())->toBe(0);
});

it('refuses to publish an import with no rendered pages at all', function () {
    $import = lrme1cReviewed(1);
    $import->pages()->delete();

    expect(fn () => app(LegacyRmePublishService::class)->publish($import->refresh(), [], superAdmin()))
        ->toThrow(ValidationException::class);

    expect(LegacyRmeRecord::count())->toBe(0);
});

it('exposes a stable, path-free refusal message', function () {
    expect(LegacyRmePdfFailure::message(LegacyRmePdfFailure::IMPORT_NOT_PUBLISHABLE))
        ->not->toContain('/')
        ->and(LegacyRmePdfFailure::isValid(LegacyRmePdfFailure::PAGE_FILE_MISSING))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| No downstream side effects — a legacy record is an archive, not an encounter
|--------------------------------------------------------------------------
*/

it('creates no visit, invoice, payment or lab order when publishing', function () {
    $import = lrme1cReviewed(2);

    $before = [
        'visits' => ClinicVisit::count(),
        'invoices' => RmeInvoice::count(),
        'payments' => RmePayment::count(),
        'lab_orders' => LabOrder::count(),
    ];

    app(LegacyRmePublishService::class)->publish($import, [], superAdmin());

    expect(ClinicVisit::count())->toBe($before['visits'])
        ->and(RmeInvoice::count())->toBe($before['invoices'])
        ->and(RmePayment::count())->toBe($before['payments'])
        ->and(LabOrder::count())->toBe($before['lab_orders']);
});

it('does not touch the status of the patient native visits', function () {
    $import = lrme1cReviewed(1);
    $statuses = ClinicVisit::orderBy('id')->pluck('status', 'id')->all();

    app(LegacyRmePublishService::class)->publish($import, [], superAdmin());

    expect(ClinicVisit::orderBy('id')->pluck('status', 'id')->all())->toBe($statuses);
});

it('writes an audit entry for the publish', function () {
    $import = lrme1cReviewed(1);

    $record = app(LegacyRmePublishService::class)->publish($import, [], superAdmin());

    expect(DB::table('sys_audit_logs')
        ->where('action', LegacyRmeAuditEvent::PUBLISHED)
        ->where('entity_type', LegacyRmeAuditEvent::ENTITY_RECORD)
        ->where('entity_id', $record->getKey())
        ->exists())->toBeTrue();
});
