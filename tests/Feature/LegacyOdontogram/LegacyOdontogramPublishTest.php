<?php

/**
 * FIX-04b — publish, immutability, and the correction path.
 *
 * Publishing turns a reviewed staging batch into IMMUTABLE clinical evidence.
 * These tests pin the properties that make that safe: a human reviewed it, it is
 * atomic and idempotent, it creates NOTHING else anywhere in the system, it can
 * never be edited or deleted afterwards, and the only correction is VOID plus a
 * fresh import.
 */

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramRecordRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecordPage;
use App\Modules\LegacyOdontogram\Policies\LegacyOdontogramRecordPolicy;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramProcessingService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPublishService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramVoidService;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramRecordStatus;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    lodoFlag(true);
    Storage::fake('legacy_odontogram_private');
    Bus::fake();
});

/**
 * A fully rendered import sitting at READY_FOR_REVIEW, with its source PDF and
 * page images actually present on the fake private disk — the exact state a
 * publish is supposed to consume.
 */
function lodoReadyImport(int $pages = 2, string $legacyDate = '2019-06-01'): LegacyOdontogramImport
{
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages($pages));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages($pages));

    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);
    lodoNativeOdontogram($patient, '2022-03-10');

    $import = lodoStageImport($patient, $legacyDate, lodoOperator(), $pages);

    app(LegacyOdontogramProcessingService::class)->process((int) $import->getKey());

    return $import->refresh();
}

it('renders pages and lands on READY_FOR_REVIEW', function () {
    $import = lodoReadyImport(3);

    expect($import->status)->toBe(LegacyOdontogramImportStatus::READY_FOR_REVIEW)
        ->and($import->page_count)->toBe(3)
        ->and($import->pages()->count())->toBe(3);
});

it('cannot be published without a human review', function () {
    $import = lodoReadyImport();

    expect(fn () => app(LegacyOdontogramPublishService::class)->publish($import, [], lodoOperator()))
        ->toThrow(ValidationException::class);

    expect(LegacyOdontogramRecord::count())->toBe(0);
});

it('publishes a reviewed import into an immutable record with its pages', function () {
    $import = lodoReadyImport(2);
    $actor = lodoOperator();

    app(LegacyOdontogramPublishService::class)->review($import, $actor);
    $record = app(LegacyOdontogramPublishService::class)->publish($import->refresh(), ['title' => 'Kartu odontogram 2019'], $actor);

    expect($record->status)->toBe(LegacyOdontogramRecordStatus::PUBLISHED)
        ->and($record->patient_id)->toBe($import->patient_id)
        ->and($record->branch_id)->toBe($import->origin_branch_id)
        ->and($record->odontogram_date->toDateString())->toBe('2019-06-01')
        ->and($record->page_count)->toBe(2)
        ->and($record->title)->toBe('Kartu odontogram 2019')
        ->and($record->pages()->count())->toBe(2)
        ->and($import->refresh()->status)->toBe(LegacyOdontogramImportStatus::PUBLISHED);
});

it('is idempotent — publishing twice returns the SAME record', function () {
    $import = lodoReadyImport();
    $actor = lodoOperator();

    app(LegacyOdontogramPublishService::class)->review($import, $actor);

    $first = app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor);
    $second = app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor);

    expect($second->getKey())->toBe($first->getKey())
        ->and(LegacyOdontogramRecord::count())->toBe(1)
        ->and(LegacyOdontogramRecordPage::count())->toBe($first->page_count);
});

it('is idempotent at the DATABASE level too — one import can never own two records', function () {
    $import = lodoReadyImport();
    $actor = lodoOperator();

    app(LegacyOdontogramPublishService::class)->review($import, $actor);
    app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor);

    expect(fn () => LegacyOdontogramRecord::query()->create([
        'patient_id' => $import->patient_id,
        'branch_id' => $import->origin_branch_id,
        'odontogram_date' => '2019-06-01',
        'source_disk' => 'legacy_odontogram_private',
        'source_pdf_path' => 'x',
        'source_pdf_sha256' => str_repeat('b', 64),
        'status' => LegacyOdontogramRecordStatus::PUBLISHED,
        'source_import_id' => $import->getKey(),
    ]))->toThrow(QueryException::class);
});

it('re-validates the date at publish time rather than trusting the upload', function () {
    $import = lodoReadyImport(1, '2019-06-01');
    $actor = lodoOperator();

    app(LegacyOdontogramPublishService::class)->review($import, $actor);

    // The patient gains an EARLIER native odontogram while the batch sits in
    // staging, so the chosen date is no longer strictly before it.
    lodoNativeOdontogram($import->patient, '2018-01-01');

    expect(fn () => app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor))
        ->toThrow(ValidationException::class);

    expect(LegacyOdontogramRecord::count())->toBe(0);
});

it('creates NO clinical, financial or downstream record of any kind', function () {
    $visitsBefore = ClinicVisit::count();
    $odontogramsBefore = Odontogram::count();
    $recordsBefore = MedicalRecord::count();

    $import = lodoReadyImport();
    $actor = lodoOperator();

    // The fixture itself creates one native visit + one native odontogram, so
    // the baseline is taken AFTER staging and the assertion covers publishing.
    $visitsAfterStaging = ClinicVisit::count();
    $odontogramsAfterStaging = Odontogram::count();
    $recordsAfterStaging = MedicalRecord::count();

    app(LegacyOdontogramPublishService::class)->review($import, $actor);
    app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor);

    expect(ClinicVisit::count())->toBe($visitsAfterStaging)
        ->and(Odontogram::count())->toBe($odontogramsAfterStaging)
        ->and(MedicalRecord::count())->toBe($recordsAfterStaging)
        ->and(RmeInvoice::count())->toBe(0)
        ->and(RmePayment::count())->toBe(0)
        ->and(LabOrder::count())->toBe(0)
        ->and(SatusehatCandidate::count())->toBe(0);

    expect($visitsBefore)->toBe(0)
        ->and($odontogramsBefore)->toBe(0)
        ->and($recordsBefore)->toBe(0);
});

it('never touches legacy RME state', function () {
    $import = lodoReadyImport();
    $actor = lodoOperator();

    app(LegacyOdontogramPublishService::class)->review($import, $actor);
    app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor);

    expect(LegacyRmeImport::count())->toBe(0)
        ->and(LegacyRmeRecord::count())->toBe(0)
        ->and(LegacyRmeMigrationWave::count())->toBe(0);
});

it('exposes no update and no delete ability — the policy hard-denies both', function () {
    $import = lodoReadyImport();
    $actor = lodoOperator();

    app(LegacyOdontogramPublishService::class)->review($import, $actor);
    $record = app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor);

    $policy = app(LegacyOdontogramRecordPolicy::class);

    expect($policy->update($actor, $record))->toBeFalse()
        ->and($policy->delete($actor, $record))->toBeFalse()
        // Even Super Admin, who bypasses via Gate::before, has no ROUTE to reach.
        ->and(collect(app('router')->getRoutes())->contains(
            fn ($route) => str_contains((string) $route->getName(), 'legacy-odontograms')
                && in_array($route->methods()[0], ['PUT', 'PATCH', 'DELETE'], true),
        ))->toBeFalse();
});

it('exposes no generic update() on the record repository', function () {
    $methods = get_class_methods(LegacyOdontogramRecordRepositoryInterface::class);

    expect($methods)->not->toContain('update')
        ->and($methods)->not->toContain('delete')
        ->and($methods)->toContain('markVoided');
});

it('corrects a published record by VOID plus a fresh import, never by editing', function () {
    $import = lodoReadyImport();
    $actor = lodoOperator();

    app(LegacyOdontogramPublishService::class)->review($import, $actor);
    $record = app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor);

    $voided = app(LegacyOdontogramVoidService::class)->void(
        $record,
        'Dokumen ternyata milik pasien lain, ditarik dan akan diunggah ulang.',
        $actor,
    );

    expect($voided->status)->toBe(LegacyOdontogramRecordStatus::VOID)
        ->and($voided->voided_by)->toBe((int) $actor->id)
        ->and($voided->void_reason)->toContain('pasien lain')
        // The evidence is retracted, not erased.
        ->and(LegacyOdontogramRecord::count())->toBe(1)
        ->and(LegacyOdontogramRecordPage::count())->toBe($record->page_count);

    // VOID is terminal — there is no way back.
    expect(LegacyOdontogramRecordStatus::canTransition(
        LegacyOdontogramRecordStatus::VOID,
        LegacyOdontogramRecordStatus::PUBLISHED,
    ))->toBeFalse();
});

it('requires a real reason to void, and voiding twice does not overwrite the first one', function () {
    $import = lodoReadyImport();
    $actor = lodoOperator();

    app(LegacyOdontogramPublishService::class)->review($import, $actor);
    $record = app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor);

    expect(fn () => app(LegacyOdontogramVoidService::class)->void($record, 'salah', $actor))
        ->toThrow(ValidationException::class);

    $reason = 'Dokumen ternyata milik pasien lain, ditarik dan akan diunggah ulang.';
    app(LegacyOdontogramVoidService::class)->void($record, $reason, $actor);

    $second = app(LegacyOdontogramVoidService::class)->void(
        $record->refresh(),
        'Alasan kedua yang berbeda sama sekali dan cukup panjang.',
        $actor,
    );

    expect($second->void_reason)->toBe($reason);
});
