<?php

/**
 * LEGACY-RME-PDF-1A — schema contract for the legacy RME archive.
 *
 * Guards the additive-only migration set: the four tables exist with the
 * columns, nullability and constraints the domain depends on, and no native RME
 * table was touched.
 */

use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeImportPage;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecordPage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('creates the legacy RME staging and archive tables', function () {
    expect(Schema::hasTable('stg_rme_legacy_imports'))->toBeTrue()
        ->and(Schema::hasTable('stg_rme_legacy_import_pages'))->toBeTrue()
        ->and(Schema::hasTable('trx_rme_legacy_records'))->toBeTrue()
        ->and(Schema::hasTable('trx_rme_legacy_record_pages'))->toBeTrue();
});

it('declares every staging import column the domain depends on', function () {
    $columns = Schema::getColumnListing('stg_rme_legacy_imports');

    expect($columns)->toContain(
        'id', 'uuid', 'patient_id', 'origin_branch_id',
        'selected_rme_date', 'earliest_native_rme_date_snapshot',
        'original_filename', 'source_disk', 'source_pdf_path', 'source_pdf_sha256',
        'normalized_content_hash', 'mime_type', 'size_bytes', 'page_count', 'dpi',
        'status', 'failure_code', 'failure_message',
        'uploaded_by', 'reviewed_by', 'published_by', 'cancelled_by',
        'uploaded_at', 'processing_started_at', 'processing_completed_at',
        'reviewed_at', 'published_at', 'cancelled_at',
        'created_at', 'updated_at', 'deleted_at',
    );

    // The staging row must never carry visit/billing state.
    expect($columns)->not->toContain('clinic_visit_id')
        ->and($columns)->not->toContain('invoice_id');
});

it('declares every staging page column the domain depends on', function () {
    $columns = Schema::getColumnListing('stg_rme_legacy_import_pages');

    expect($columns)->toContain(
        'id', 'legacy_import_id', 'page_number',
        'width', 'height', 'dpi', 'rotation',
        'background_disk', 'background_path', 'background_sha256',
        'thumbnail_path', 'normalized_page_hash', 'status',
        'created_at', 'updated_at',
    );
});

it('declares every published legacy record column the domain depends on', function () {
    $columns = Schema::getColumnListing('trx_rme_legacy_records');

    expect($columns)->toContain(
        'id', 'uuid', 'patient_id', 'origin_branch_id',
        'rme_date', 'title', 'description',
        'source_disk', 'source_pdf_path', 'source_pdf_sha256', 'normalized_content_hash',
        'page_count', 'status', 'source_import_id',
        'imported_by', 'published_by', 'published_at',
        'voided_by', 'voided_at', 'void_reason',
        'created_at', 'updated_at',
    );

    // A legacy record is never a visit and is never soft-deleted: the only
    // correction path is VOID plus a fresh import.
    expect($columns)->not->toContain('clinic_visit_id')
        ->and($columns)->not->toContain('deleted_at');
});

it('declares every published legacy page column and keeps pages non-deletable', function () {
    $columns = Schema::getColumnListing('trx_rme_legacy_record_pages');

    expect($columns)->toContain(
        'id', 'rme_legacy_record_id', 'page_number',
        'width', 'height', 'dpi', 'rotation',
        'background_disk', 'background_path', 'background_sha256',
        'thumbnail_path', 'normalized_page_hash',
        'created_at', 'updated_at',
    )->and($columns)->not->toContain('deleted_at');
});

it('enforces one page number per staged import', function () {
    $import = LegacyRmeImport::factory()->create();

    LegacyRmeImportPage::factory()->create([
        'legacy_import_id' => $import->id,
        'page_number' => 1,
    ]);

    expect(fn () => LegacyRmeImportPage::factory()->create([
        'legacy_import_id' => $import->id,
        'page_number' => 1,
    ]))->toThrow(QueryException::class);
});

it('enforces one page number per published legacy record', function () {
    $record = LegacyRmeRecord::factory()->create();

    LegacyRmeRecordPage::factory()->create([
        'rme_legacy_record_id' => $record->id,
        'page_number' => 1,
    ]);

    expect(fn () => LegacyRmeRecordPage::factory()->create([
        'rme_legacy_record_id' => $record->id,
        'page_number' => 1,
    ]))->toThrow(QueryException::class);
});

it('allows at most one published record per staging import', function () {
    $import = LegacyRmeImport::factory()->published()->create();

    LegacyRmeRecord::factory()->create([
        'patient_id' => $import->patient_id,
        'source_import_id' => $import->id,
    ]);

    expect(fn () => LegacyRmeRecord::factory()->create([
        'patient_id' => $import->patient_id,
        'source_import_id' => $import->id,
    ]))->toThrow(QueryException::class);
});

it('keeps origin branch optional on both staging and published rows', function () {
    $patient = legacyRmeArchivablePatient();

    $import = LegacyRmeImport::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => null,
    ]);

    $record = LegacyRmeRecord::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => null,
    ]);

    expect($import->origin_branch_id)->toBeNull()
        ->and($record->origin_branch_id)->toBeNull();
});

it('does not alter the native medical record or clinic visit schema', function () {
    // The legacy archive is additive: a native medical record still hangs off a
    // clinic visit and gained no legacy pointer.
    $medicalRecordColumns = Schema::getColumnListing('trx_medical_records');

    expect($medicalRecordColumns)->toContain('clinic_visit_id')
        ->and($medicalRecordColumns)->not->toContain('legacy_import_id')
        ->and($medicalRecordColumns)->not->toContain('rme_legacy_record_id');

    expect(Schema::getColumnListing('trx_clinic_visits'))->toContain('visit_date');
});
