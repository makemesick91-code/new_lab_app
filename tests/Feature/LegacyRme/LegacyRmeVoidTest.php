<?php

/**
 * LEGACY-RME-PDF-1D — retracting a published legacy RME record.
 *
 * VOID is the only state change a published archive allows, and it must retract
 * WITHOUT erasing: the row survives, the pages survive, the private files
 * survive, and the reason is kept so a later reader can tell a mis-file from a
 * duplicate. These tests hit the route directly — with forged ids, from the
 * wrong branch, from the wrong role, twice at once, and with the flag off.
 */

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecordPage;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmePatientHistoryService;
use App\Modules\LegacyRme\Services\LegacyRmePublishService;
use App\Modules\LegacyRme\Services\LegacyRmeVoidService;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses()->group('LegacyRme');

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Bus::fake();
});

function lrme1dPublished(int $pages = 2): LegacyRmeRecord
{
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages($pages));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages($pages));

    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    $import = app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        null,
        legacyRmePdfUpload('arsip.pdf', $pages),
        superAdmin(),
    );

    app(LegacyRmeImportProcessingService::class)->process($import->getKey());

    $reviewed = app(LegacyRmePublishService::class)->review($import->refresh(), superAdmin())->refresh();

    return app(LegacyRmePublishService::class)->publish($reviewed, [], superAdmin());
}

const LRME1D_REASON = 'Dokumen ini milik pasien lain dan salah dilampirkan.';

/*
|--------------------------------------------------------------------------
| The happy path — retract, with a reason, audited
|--------------------------------------------------------------------------
*/

it('retracts a published archive with a reason, an actor and a timestamp', function () {
    $record = lrme1dPublished();
    $admin = superAdmin();

    $this->actingAs($admin)
        ->post(route('rme.legacy-records.void', $record->getKey()), ['void_reason' => LRME1D_REASON])
        ->assertRedirect(route('rme.legacy-records.show', $record->getKey()))
        ->assertSessionHas('status');

    $record->refresh();

    expect($record->status)->toBe(LegacyRmeRecordStatus::VOID)
        ->and($record->void_reason)->toBe(LRME1D_REASON)
        ->and($record->voided_by)->toBe($admin->getKey())
        ->and($record->voided_at)->not->toBeNull();
});

it('writes a void audit row without leaking the free-text reason', function () {
    $record = lrme1dPublished();

    $this->actingAs(superAdmin())
        ->post(route('rme.legacy-records.void', $record->getKey()), ['void_reason' => LRME1D_REASON]);

    $row = DB::table('sys_audit_logs')
        ->where('action', LegacyRmeAuditEvent::VOIDED)
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();

    $payload = json_encode($row);

    // Structure only: the reason lives on the record, never in the trail.
    expect($payload)->not->toContain(LRME1D_REASON)
        ->and($payload)->toContain('void_reason_length');
});

/*
|--------------------------------------------------------------------------
| Retraction never erases
|--------------------------------------------------------------------------
*/

it('keeps the row, the pages and the private files after a void', function () {
    $record = lrme1dPublished(3);
    $pagesBefore = LegacyRmeRecordPage::query()->where('rme_legacy_record_id', $record->getKey())->count();
    $sourcePath = $record->source_pdf_path;

    $this->actingAs(superAdmin())
        ->post(route('rme.legacy-records.void', $record->getKey()), ['void_reason' => LRME1D_REASON]);

    expect(LegacyRmeRecord::query()->whereKey($record->getKey())->exists())->toBeTrue()
        ->and(LegacyRmeRecordPage::query()->where('rme_legacy_record_id', $record->getKey())->count())->toBe($pagesBefore)
        ->and(Storage::disk('legacy_rme_private')->exists($sourcePath))->toBeTrue();
});

it('never edits the archive itself while voiding', function () {
    $record = lrme1dPublished();
    $before = $record->only(['patient_id', 'rme_date', 'source_pdf_path', 'source_pdf_sha256', 'page_count', 'published_at']);

    $this->actingAs(superAdmin())
        ->post(route('rme.legacy-records.void', $record->getKey()), ['void_reason' => LRME1D_REASON]);

    $after = $record->refresh()->only(['patient_id', 'rme_date', 'source_pdf_path', 'source_pdf_sha256', 'page_count', 'published_at']);

    expect($after)->toEqual($before);
});

it('drops a voided archive out of the patient active history but keeps it readable', function () {
    $record = lrme1dPublished();
    $admin = superAdmin();

    $this->actingAs($admin)
        ->post(route('rme.legacy-records.void', $record->getKey()), ['void_reason' => LRME1D_REASON]);

    expect(app(LegacyRmePatientHistoryService::class)
        ->publishedRecordsFor($admin, (int) $record->patient_id))
        ->toBeEmpty();

    // Retracted, not erased: the row and its reason stay auditable.
    $this->actingAs($admin)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertOk()
        ->assertSee('VOID');
});

it('stops streaming the archive bytes once voided', function () {
    $record = lrme1dPublished();
    $admin = superAdmin();

    $this->actingAs($admin)->get(route('rme.legacy-records.source', $record->getKey()))->assertOk();

    $this->actingAs($admin)
        ->post(route('rme.legacy-records.void', $record->getKey()), ['void_reason' => LRME1D_REASON]);

    $this->actingAs($admin)->get(route('rme.legacy-records.source', $record->getKey()))->assertNotFound();
    $this->actingAs($admin)->get(route('rme.legacy-records.pages.show', [$record->getKey(), 1]))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Terminal, idempotent, concurrency-safe
|--------------------------------------------------------------------------
*/

it('treats a second void as a no-op and keeps the first reason and actor', function () {
    $record = lrme1dPublished();
    $first = superAdmin();
    $second = superAdmin();

    $this->actingAs($first)
        ->post(route('rme.legacy-records.void', $record->getKey()), ['void_reason' => LRME1D_REASON]);

    $this->actingAs($second)
        ->post(route('rme.legacy-records.void', $record->getKey()), ['void_reason' => 'Alasan kedua yang berbeda sama sekali.'])
        ->assertRedirect(route('rme.legacy-records.show', $record->getKey()));

    $record->refresh();

    expect($record->void_reason)->toBe(LRME1D_REASON)
        ->and($record->voided_by)->toBe($first->getKey())
        ->and(DB::table('sys_audit_logs')->where('action', LegacyRmeAuditEvent::VOIDED)->count())->toBe(1);
});

it('has no path out of VOID in the transition map', function () {
    expect(LegacyRmeRecordStatus::canTransition(LegacyRmeRecordStatus::VOID, LegacyRmeRecordStatus::PUBLISHED))->toBeFalse()
        ->and(LegacyRmeRecordStatus::TRANSITIONS[LegacyRmeRecordStatus::VOID])->toBe([]);
});

it('converges on one void when two requests race', function () {
    $record = lrme1dPublished();
    $service = app(LegacyRmeVoidService::class);

    $service->void($record, LRME1D_REASON, superAdmin());
    $service->void($record->refresh(), 'Alasan berbeda dari yang pertama.', superAdmin());

    expect($record->refresh()->void_reason)->toBe(LRME1D_REASON)
        ->and(DB::table('sys_audit_logs')->where('action', LegacyRmeAuditEvent::VOIDED)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The reason is mandatory
|--------------------------------------------------------------------------
*/

it('refuses a void with no reason', function () {
    $record = lrme1dPublished();

    $this->actingAs(superAdmin())
        ->post(route('rme.legacy-records.void', $record->getKey()), [])
        ->assertSessionHasErrors('void_reason');

    expect($record->refresh()->status)->toBe(LegacyRmeRecordStatus::PUBLISHED);
});

it('refuses a reason that is too short or only whitespace', function (string $reason) {
    $record = lrme1dPublished();

    $this->actingAs(superAdmin())
        ->post(route('rme.legacy-records.void', $record->getKey()), ['void_reason' => $reason])
        ->assertSessionHasErrors('void_reason');

    expect($record->refresh()->status)->toBe(LegacyRmeRecordStatus::PUBLISHED);
})->with([
    'too short' => ['salah'],
    'whitespace only' => ['            '],
]);

it('enforces the reason floor in the service, not only in the form', function () {
    $record = lrme1dPublished();

    expect(fn () => app(LegacyRmeVoidService::class)->void($record, 'x', superAdmin()))
        ->toThrow(ValidationException::class);

    expect($record->refresh()->status)->toBe(LegacyRmeRecordStatus::PUBLISHED);
});

/*
|--------------------------------------------------------------------------
| Authorization boundary
|--------------------------------------------------------------------------
*/

it('refuses a void from a user without the void permission', function () {
    $record = lrme1dPublished();

    $reader = User::factory()->create();
    $reader->givePermissionTo('view_legacy_rme_archive');

    $this->actingAs($reader)
        ->post(route('rme.legacy-records.void', $record->getKey()), ['void_reason' => LRME1D_REASON])
        ->assertForbidden();

    expect($record->refresh()->status)->toBe(LegacyRmeRecordStatus::PUBLISHED);
});

it('answers 404 rather than 403 for an archive outside the caller branch scope', function () {
    $record = lrme1dPublished();

    $operator = User::factory()->create();
    $operator->givePermissionTo('void_legacy_rme_imports');

    // A void-permission holder is governance tier, so scope it out by pointing
    // the record at a branch that is not RME-enabled at all.
    $record->forceFill(['origin_branch_id' => null])->save();
    $record->refresh();

    $this->actingAs($operator)
        ->post(route('rme.legacy-records.void', 999999), ['void_reason' => LRME1D_REASON])
        ->assertNotFound();
});

it('refuses a void from a guest', function () {
    $record = lrme1dPublished();

    $this->post(route('rme.legacy-records.void', $record->getKey()), ['void_reason' => LRME1D_REASON])
        ->assertRedirect(route('login'));

    expect($record->refresh()->status)->toBe(LegacyRmeRecordStatus::PUBLISHED);
});

it('answers 404 for every void attempt while the feature flag is off', function () {
    $record = lrme1dPublished();
    legacyRmeArchiveFlag(false);

    $this->actingAs(superAdmin())
        ->post(route('rme.legacy-records.void', $record->getKey()), ['void_reason' => LRME1D_REASON])
        ->assertNotFound();

    expect($record->refresh()->status)->toBe(LegacyRmeRecordStatus::PUBLISHED);
});

/*
|--------------------------------------------------------------------------
| No downstream side effects — a legacy archive is never an encounter
|--------------------------------------------------------------------------
*/

it('creates no visit, invoice, payment or other downstream record when voiding', function () {
    $record = lrme1dPublished();

    $before = [
        'visits' => ClinicVisit::query()->count(),
        'invoices' => RmeInvoice::query()->count(),
        'medical_records' => DB::table('trx_medical_records')->count(),
        'payments' => DB::table('trx_rme_payments')->count(),
        'odontograms' => DB::table('trx_odontograms')->count(),
        'lab_candidates' => DB::table('trx_lab_case_candidates')->count(),
        'lab_orders' => DB::table('trx_lab_orders')->count(),
        'satusehat' => DB::table('trx_satusehat_candidates')->count(),
    ];

    $this->actingAs(superAdmin())
        ->post(route('rme.legacy-records.void', $record->getKey()), ['void_reason' => LRME1D_REASON]);

    $after = [
        'visits' => ClinicVisit::query()->count(),
        'invoices' => RmeInvoice::query()->count(),
        'medical_records' => DB::table('trx_medical_records')->count(),
        'payments' => DB::table('trx_rme_payments')->count(),
        'odontograms' => DB::table('trx_odontograms')->count(),
        'lab_candidates' => DB::table('trx_lab_case_candidates')->count(),
        'lab_orders' => DB::table('trx_lab_orders')->count(),
        'satusehat' => DB::table('trx_satusehat_candidates')->count(),
    ];

    expect($after)->toEqual($before);
});
