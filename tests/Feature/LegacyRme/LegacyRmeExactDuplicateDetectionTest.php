<?php

/**
 * LEGACY-RME-PDF-1B — exact-file duplicate precheck.
 *
 * The decision is made on the SERVER-computed SHA-256 of the source PDF. The
 * filename is irrelevant in both directions: same bytes under a new name is
 * still a duplicate, and different bytes under the same name is not.
 */

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmeDuplicateDetectionService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;
use App\Modules\Patient\Models\Patient;
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

function lrmeDupPatient(): Patient
{
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    return $patient;
}

function lrmeDupUpload(Patient $patient, UploadedFile $document, string $date = '2020-05-01'): LegacyRmeImport
{
    return app(LegacyRmeImportService::class)
        ->createFromUpload($patient, $date, null, $document, superAdmin());
}

function lrmeDuplicates(): LegacyRmeDuplicateDetectionService
{
    return app(LegacyRmeDuplicateDetectionService::class);
}

it('blocks re-uploading the identical pdf for the same patient', function () {
    $patient = lrmeDupPatient();
    $bytes = legacyRmePdfBytes(2);

    lrmeDupUpload($patient, UploadedFile::fake()->createWithContent('a.pdf', $bytes));

    expect(fn () => lrmeDupUpload($patient, UploadedFile::fake()->createWithContent('a.pdf', $bytes)))
        ->toThrow(ValidationException::class);

    expect(LegacyRmeImport::count())->toBe(1);
});

it('blocks the identical pdf even under a different filename', function () {
    $patient = lrmeDupPatient();
    $bytes = legacyRmePdfBytes(2);

    lrmeDupUpload($patient, UploadedFile::fake()->createWithContent('asli.pdf', $bytes));

    expect(fn () => lrmeDupUpload($patient, UploadedFile::fake()->createWithContent('salinan-lain.pdf', $bytes)))
        ->toThrow(ValidationException::class);
});

it('allows the same filename when the bytes differ', function () {
    $patient = lrmeDupPatient();

    lrmeDupUpload($patient, UploadedFile::fake()->createWithContent('arsip.pdf', legacyRmePdfBytes(1)));
    $second = lrmeDupUpload($patient, UploadedFile::fake()->createWithContent('arsip.pdf', legacyRmePdfBytes(3)), '2020-06-01');

    expect(LegacyRmeImport::count())->toBe(2)
        ->and($second->source_pdf_sha256)->not->toBe(LegacyRmeImport::first()->source_pdf_sha256);
});

it('blocks the identical pdf when it is already staged for a different patient', function () {
    $first = lrmeDupPatient();
    $second = lrmeDupPatient();
    $bytes = legacyRmePdfBytes(2);

    lrmeDupUpload($first, UploadedFile::fake()->createWithContent('a.pdf', $bytes));

    expect(fn () => lrmeDupUpload($second, UploadedFile::fake()->createWithContent('a.pdf', $bytes)))
        ->toThrow(ValidationException::class);
});

it('blocks the identical pdf when a published record already holds it', function () {
    $patient = lrmeDupPatient();
    $sha = hash('sha256', 'apapun');

    LegacyRmeRecord::factory()->create([
        'patient_id' => $patient->id,
        'source_pdf_sha256' => $sha,
        'status' => LegacyRmeRecordStatus::PUBLISHED,
    ]);

    $decision = lrmeDuplicates()->evaluate($patient->id, $sha);

    expect($decision->blocked)->toBeTrue()
        ->and($decision->code)->toBe(LegacyRmePdfFailure::DUPLICATE_SAME_PATIENT);
});

it('blocks a published record belonging to another patient', function () {
    $owner = lrmeDupPatient();
    $other = lrmeDupPatient();
    $sha = hash('sha256', 'apapun');

    LegacyRmeRecord::factory()->create([
        'patient_id' => $owner->id,
        'source_pdf_sha256' => $sha,
        'status' => LegacyRmeRecordStatus::PUBLISHED,
    ]);

    $decision = lrmeDuplicates()->evaluate($other->id, $sha);

    expect($decision->blocked)->toBeTrue()
        ->and($decision->code)->toBe(LegacyRmePdfFailure::DUPLICATE_OTHER_PATIENT)
        ->and($decision->duplicatePatientId)->toBe($owner->id);
});

it('does not block when the colliding record has been voided', function () {
    // 1A defines the ONLY correction of a published record as "VOID plus a fresh
    // import" — so a VOID collision must never block that fresh import.
    $patient = lrmeDupPatient();
    $sha = hash('sha256', 'apapun');

    LegacyRmeRecord::factory()->create([
        'patient_id' => $patient->id,
        'source_pdf_sha256' => $sha,
        'status' => LegacyRmeRecordStatus::VOID,
    ]);

    expect(lrmeDuplicates()->evaluate($patient->id, $sha)->blocked)->toBeFalse();
});

it('points a failed same-patient duplicate at retry instead of a second staging row', function () {
    $patient = lrmeDupPatient();
    $sha = hash('sha256', 'apapun');

    $failed = LegacyRmeImport::factory()->create([
        'patient_id' => $patient->id,
        'source_pdf_sha256' => $sha,
        'status' => LegacyRmeImportStatus::FAILED,
    ]);

    $decision = lrmeDuplicates()->evaluate($patient->id, $sha);

    expect($decision->blocked)->toBeTrue()
        ->and($decision->duplicateImportId)->toBe($failed->getKey())
        ->and($decision->message)->toContain('Proses Ulang');
});

it('allows re-uploading a failed document against a different patient', function () {
    // "Wrong patient chosen, it failed, upload it against the right one" must
    // stay possible, otherwise the document is stranded.
    $wrong = lrmeDupPatient();
    $right = lrmeDupPatient();
    $sha = hash('sha256', 'apapun');

    LegacyRmeImport::factory()->create([
        'patient_id' => $wrong->id,
        'source_pdf_sha256' => $sha,
        'status' => LegacyRmeImportStatus::FAILED,
    ]);

    expect(lrmeDuplicates()->evaluate($right->id, $sha)->blocked)->toBeFalse();
});

it('allows re-uploading after the previous staging row was cancelled', function () {
    $patient = lrmeDupPatient();
    $sha = hash('sha256', 'apapun');

    LegacyRmeImport::factory()->create([
        'patient_id' => $patient->id,
        'source_pdf_sha256' => $sha,
        'status' => LegacyRmeImportStatus::CANCELLED,
    ]);

    expect(lrmeDuplicates()->evaluate($patient->id, $sha)->blocked)->toBeFalse();
});

it('blocks while an identical document is still being processed', function () {
    $patient = lrmeDupPatient();
    $sha = hash('sha256', 'apapun');

    LegacyRmeImport::factory()->create([
        'patient_id' => $patient->id,
        'source_pdf_sha256' => $sha,
        'status' => LegacyRmeImportStatus::PROCESSING,
    ]);

    $decision = lrmeDuplicates()->evaluate($patient->id, $sha);

    expect($decision->blocked)->toBeTrue()
        ->and($decision->message)->toContain('masih diproses');
});

it('computes the checksum server-side and ignores any supplied value', function () {
    $patient = lrmeDupPatient();
    $bytes = legacyRmePdfBytes(2);

    $import = lrmeDupUpload($patient, UploadedFile::fake()->createWithContent('a.pdf', $bytes));

    expect($import->source_pdf_sha256)->toBe(hash('sha256', $bytes));
});

it('records a PII-free audit entry when a duplicate is refused', function () {
    $patient = lrmeDupPatient();
    $bytes = legacyRmePdfBytes(2);

    lrmeDupUpload($patient, UploadedFile::fake()->createWithContent('a.pdf', $bytes));

    try {
        lrmeDupUpload($patient, UploadedFile::fake()->createWithContent('a.pdf', $bytes));
    } catch (ValidationException) {
        // expected
    }

    $log = AuditLog::query()
        ->where('action', LegacyRmeAuditEvent::DUPLICATE_DETECTED)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();

    $payload = json_encode($log->new_values ?? []);

    expect($payload)->not->toContain($patient->name)
        ->and($payload)->not->toContain('a.pdf');
});
