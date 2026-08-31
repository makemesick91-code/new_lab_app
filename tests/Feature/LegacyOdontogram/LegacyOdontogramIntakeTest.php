<?php

/**
 * FIX-04b — intake: PDF-only validation and DERIVED branch binding.
 *
 * The two facts an operator must not be able to lie about are the file format
 * and the owning branch, because one determines whether the pipeline can read
 * the document at all and the other determines who can read it afterwards.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Requests\StoreLegacyOdontogramImportRequest;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramBranchBindingService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramImportService;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeBranchResolution;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
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

it('stages a valid PDF and derives every server-side field', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');
    $actor = lodoOperator();

    $import = lodoStageImport($patient, '2019-06-01', $actor);

    expect($import->status)->toBe(LegacyOdontogramImportStatus::QUEUED)
        ->and($import->origin_branch_id)->toBe((int) lodoBranch()->id)
        ->and($import->source_branch_code)->toBe('TLK1')
        ->and($import->source_medical_record_number)->toBe($patient->medical_record_number)
        ->and($import->earliest_native_odontogram_date_snapshot?->toDateString())->toBe('2022-03-10')
        ->and($import->mime_type)->toBe('application/pdf')
        ->and($import->uploaded_by)->toBe((int) $actor->id);

    Storage::disk('legacy_odontogram_private')->assertExists($import->source_pdf_path);
});

it('rejects a non-PDF disguised with a .pdf extension', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    // Real PNG bytes, named `.pdf`. The magic-header check reads the FILE, not
    // the name, so this must be refused rather than stored and later crash the
    // rasterizer.
    $disguised = UploadedFile::fake()->createWithContent(
        'odontogram.pdf',
        (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='),
    );

    expect(fn () => app(LegacyOdontogramImportService::class)->createFromUpload(
        $patient,
        '2019-06-01',
        $disguised,
        lodoOperator(),
    ))->toThrow(ValidationException::class);

    expect(LegacyOdontogramImport::count())->toBe(0);
});

it('leaves no orphaned file on disk when intake is refused', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    try {
        app(LegacyOdontogramImportService::class)->createFromUpload(
            $patient,
            // Refused by the date rules, before a single byte is written.
            '2023-01-01',
            lodoPdfUpload(),
            lodoOperator(),
        );
    } catch (ValidationException) {
        // expected
    }

    expect(Storage::disk('legacy_odontogram_private')->allFiles())->toBe([])
        ->and(LegacyOdontogramImport::count())->toBe(0);
});

it('derives the branch from the Nomor RM and ignores any submitted branch_id', function () {
    $tkm = lodoBranch('TLK1', 'Cabang Telkomas');
    $ldk = lodoBranch('LDK2', 'Cabang Landak');

    $patient = lodoPatient([], 'LDK2');
    lodoNativeOdontogram($patient, '2022-03-10');

    // A governance-tier operator can see BOTH branches, so nothing but the RM
    // itself decides where this lands.
    $import = lodoStageImport($patient, '2019-06-01', lodoOperator());

    expect($import->origin_branch_id)->toBe((int) $ldk->id)
        ->and($import->origin_branch_id)->not->toBe((int) $tkm->id)
        ->and($import->source_branch_code)->toBe('LDK2');
});

it('there is no branch field to submit — the FormRequest accepts none', function () {
    $rules = (new StoreLegacyOdontogramImportRequest)->rules();

    expect($rules)->not->toHaveKey('branch_id')
        ->and($rules)->not->toHaveKey('origin_branch_id');
});

it('fails closed on a missing Nomor RM', function () {
    $patient = lodoPatient(['medical_record_number' => null]);

    $resolution = app(LegacyOdontogramBranchBindingService::class)->resolveForPatient($patient);

    expect($resolution->failed())->toBeTrue()
        ->and($resolution->code)->toBe(LegacyRmeBranchResolution::CODE_RM_MISSING);
});

it('fails closed on a malformed Nomor RM', function () {
    $patient = lodoPatient(['medical_record_number' => 'RM-LAMA-123']);

    expect(app(LegacyOdontogramBranchBindingService::class)->resolveForPatient($patient)->code)
        ->toBe(LegacyRmeBranchResolution::CODE_INVALID_RM_BRANCH_CODE);
});

it('fails closed on an unknown branch code', function () {
    $patient = lodoPatient();
    $patient->forceFill(['medical_record_number' => 'DG-ZZZ9-2024-0001'])->save();

    expect(app(LegacyOdontogramBranchBindingService::class)->resolveForPatient($patient->refresh())->code)
        ->toBe(LegacyRmeBranchResolution::CODE_BRANCH_NOT_FOUND);
});

it('fails closed on an INACTIVE branch', function () {
    $branch = lodoBranch('ATG3', 'Cabang Antang');
    $patient = lodoPatient([], 'ATG3');

    $branch->forceFill(['is_active' => false])->save();

    expect(app(LegacyOdontogramBranchBindingService::class)->resolveForPatient($patient)->code)
        ->toBe(LegacyRmeBranchResolution::CODE_BRANCH_INACTIVE);
});

it('fails closed on a non-RME branch', function () {
    $branch = lodoBranch('SUN4', 'Cabang Sunu');
    $patient = lodoPatient([], 'SUN4');

    $branch->forceFill(['is_rme_enabled' => false])->save();

    expect(app(LegacyOdontogramBranchBindingService::class)->resolveForPatient($patient)->code)
        ->toBe(LegacyRmeBranchResolution::CODE_BRANCH_NOT_RME_ENABLED);
});

it('fails closed when the derived branch is outside the operators scope', function () {
    lodoBranch('TLK1');
    $ldk = lodoBranch('LDK2', 'Cabang Landak');
    $patient = lodoPatient([], 'LDK2');

    // A read-only holder is NOT governance tier, so they are pinned to their own
    // branch — which here is not LDK2.
    $reader = userWith(['view_legacy_odontogram_archive']);
    $reader->forceFill(['branch_id' => lodoBranch('TLK1')->id])->save();

    expect(app(LegacyOdontogramBranchBindingService::class)->resolveForPatient($patient, $reader->refresh())->code)
        ->toBe(LegacyRmeBranchResolution::CODE_BRANCH_OUT_OF_SCOPE);

    expect($ldk->code)->toBe('LDK2');
});

it('refuses every intake path while the migration capability is OFF', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    lodoFlag(false);

    expect(fn () => lodoStageImport($patient, '2019-06-01', lodoOperator()))
        ->toThrow(ValidationException::class);

    expect(LegacyOdontogramImport::count())->toBe(0);
});

it('does not admit a branch code that is ambiguous by construction', function () {
    // `mst_branches.code` is UNIQUE, which is exactly why RM-derived resolution
    // is unambiguous. This pins that guarantee: a second branch with the same
    // code cannot be created, so BRANCH_AMBIGUOUS stays unreachable rather than
    // silently picking one.
    lodoBranch('TLK1');

    expect(fn () => Branch::query()->create([
        'name' => 'Cabang Kembar',
        'code' => 'TLK1',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]))->toThrow(QueryException::class);
});
