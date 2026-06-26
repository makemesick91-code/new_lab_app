<?php

/**
 * Sprint 61.3 — Patient Scan Document Storage Governance, Audit & Cleanup.
 *
 * Verifies the CLI governance layer over the private patient document store:
 * the read-only audit command and the safe temp-prune command. All file
 * operations run against a faked private disk; nothing here touches real
 * storage. Privacy invariants (no public URL, no full KTP number) are owned by
 * the Sprint 61.1 suite and are not weakened here.
 */

use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
});

/** A small valid 10x10 PNG (raw base64, no data URI prefix). */
function govPngBase64(): string
{
    return 'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC';
}

/** Create an active KTP PatientDocument with a real PNG file + correct metadata. */
function makeGovDocument(?Patient $patient = null): PatientDocument
{
    $patient ??= Patient::factory()->create();
    $binary = base64_decode(govPngBase64());
    $path = 'patient-documents/'.$patient->id.'/ktp-'.Str::random(8).'.png';
    Storage::disk('local')->put($path, $binary);

    return $patient->documents()->create([
        'document_type' => PatientDocument::TYPE_KTP,
        'file_path' => $path,
        'original_filename' => 'ktp.png',
        'mime_type' => 'image/png',
        'file_size' => strlen($binary),
        'compressed_file_size' => strlen($binary),
        'checksum' => hash('sha256', $binary),
        'uploaded_by' => null,
    ]);
}

/** Write a temp scan token-set (image + meta) with an explicit created_at. */
function makeTempScan(int $userId, Carbon $createdAt): string
{
    $token = (string) Str::uuid();
    $binary = base64_decode(govPngBase64());
    $imagePath = 'tmp/patient-ktp-scans/'.$userId.'/'.$token.'.jpg';
    $metaPath = 'tmp/patient-ktp-scans/'.$userId.'/'.$token.'.json';

    Storage::disk('local')->put($imagePath, $binary);
    Storage::disk('local')->put($metaPath, json_encode([
        'mime_type' => 'image/jpeg',
        'original_filename' => 'ktp.jpg',
        'original_size' => strlen($binary),
        'compressed_file_size' => strlen($binary),
        'file_path' => $imagePath,
        'created_at' => $createdAt->toIso8601String(),
    ]));

    return $imagePath;
}

/** Run the audit command with --json and decode the summary payload. */
function runAuditJson(): array
{
    Artisan::call('patient-documents:audit', ['--json' => true]);

    return json_decode(Artisan::output(), true);
}

// --- 1. Active document count -------------------------------------------------

it('reports the active patient document count', function () {
    makeGovDocument();
    makeGovDocument();

    $payload = runAuditJson();

    expect($payload['ok'])->toBeTrue();
    expect($payload['summary']['active_document_records'])->toBe(2);
    expect($payload['summary']['active_files_count'])->toBe(2);
});

// --- 2. Missing file ----------------------------------------------------------

it('detects a document record whose file is missing', function () {
    $document = makeGovDocument();
    Storage::disk('local')->delete($document->file_path);

    $payload = runAuditJson();

    expect($payload['summary']['missing_files_count'])->toBe(1);
});

// --- 3. Orphan final file -----------------------------------------------------

it('detects an orphan final file with no document record', function () {
    $patient = Patient::factory()->create();
    Storage::disk('local')->put(
        'patient-documents/'.$patient->id.'/ktp-orphan.png',
        base64_decode(govPngBase64()),
    );

    $payload = runAuditJson();

    expect($payload['summary']['orphan_files_count'])->toBe(1);
    expect($payload['summary']['orphan_files_bytes'])->toBeGreaterThan(0);
});

// --- 4. Stale temp file -------------------------------------------------------

it('detects a stale temp scan file beyond TTL', function () {
    makeTempScan(7, Carbon::now()->subHours(48));

    $payload = runAuditJson();

    expect($payload['summary']['stale_temp_files_count'])->toBe(1);
    expect($payload['summary']['stale_temp_files_bytes'])->toBeGreaterThan(0);
});

// --- 5. Checksum mismatch -----------------------------------------------------

it('detects a checksum mismatch', function () {
    $document = makeGovDocument();
    $document->forceFill(['checksum' => str_repeat('0', 64)])->save();

    $payload = runAuditJson();

    expect($payload['summary']['checksum_mismatch_count'])->toBe(1);
});

// --- 6. JSON output validity + keys ------------------------------------------

it('produces valid JSON output containing all expected summary keys', function () {
    makeGovDocument();

    $payload = runAuditJson();

    expect($payload)->toBeArray();
    expect($payload['ok'])->toBeTrue();

    foreach ([
        'total_document_records', 'active_document_records', 'soft_deleted_document_records',
        'active_files_count', 'active_files_bytes', 'orphan_files_count', 'orphan_files_bytes',
        'stale_temp_files_count', 'stale_temp_files_bytes', 'missing_files_count',
        'checksum_mismatch_count', 'mime_mismatch_count', 'size_mismatch_count',
        'suspicious_path_count', 'deleted_records_with_file_count', 'duplicate_checksum_count',
    ] as $key) {
        expect($payload['summary'])->toHaveKey($key);
    }
});

// --- 7. Prune-temp dry-run does not delete -----------------------------------

it('prune-temp dry-run reports stale files but deletes nothing', function () {
    $stale = makeTempScan(7, Carbon::now()->subHours(48));

    Artisan::call('patient-documents:prune-temp', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['dry_run'])->toBeTrue();
    expect($payload['would_delete_count'])->toBeGreaterThanOrEqual(1);
    expect($payload['deleted_count'])->toBe(0);
    expect(Storage::disk('local')->exists($stale))->toBeTrue();
});

// --- 8. Prune-temp --force deletes only stale temp files ----------------------

it('prune-temp with force deletes stale temp files only', function () {
    $stale = makeTempScan(7, Carbon::now()->subHours(48));
    $fresh = makeTempScan(7, Carbon::now());

    Artisan::call('patient-documents:prune-temp', ['--force' => true, '--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['dry_run'])->toBeFalse();
    expect($payload['deleted_count'])->toBeGreaterThanOrEqual(1);
    expect(Storage::disk('local')->exists($stale))->toBeFalse();
    expect(Storage::disk('local')->exists($fresh))->toBeTrue();
});

// --- 9. Prune-temp does not delete fresh temp files --------------------------

it('prune-temp leaves fresh temp scans untouched', function () {
    $fresh = makeTempScan(7, Carbon::now()->subMinutes(5));

    Artisan::call('patient-documents:prune-temp', ['--force' => true]);

    expect(Storage::disk('local')->exists($fresh))->toBeTrue();
});

// --- 10. Prune-temp never deletes final patient documents --------------------

it('prune-temp never deletes final patient document files', function () {
    $document = makeGovDocument();
    makeTempScan(7, Carbon::now()->subHours(48));

    Artisan::call('patient-documents:prune-temp', ['--force' => true]);

    expect(Storage::disk('local')->exists($document->file_path))->toBeTrue();
});

// --- 11. Suspicious path outside allowed root --------------------------------

it('flags a document file path outside the allowed private root', function () {
    $patient = Patient::factory()->create();
    Storage::disk('local')->put('evil/ktp-leak.png', base64_decode(govPngBase64()));
    $patient->documents()->create([
        'document_type' => PatientDocument::TYPE_KTP,
        'file_path' => 'evil/ktp-leak.png',
        'original_filename' => 'ktp.png',
        'mime_type' => 'image/png',
        'file_size' => 100,
        'compressed_file_size' => Storage::disk('local')->size('evil/ktp-leak.png'),
        'checksum' => hash('sha256', base64_decode(govPngBase64())),
        'uploaded_by' => null,
    ]);

    $payload = runAuditJson();

    expect($payload['summary']['suspicious_path_count'])->toBe(1);
});

// --- 12. Soft-deleted record with file still present -------------------------

it('detects a soft-deleted record whose file still lingers', function () {
    $document = makeGovDocument();
    // Soft-delete the row WITHOUT removing the file (simulates an unclean delete).
    $document->delete();

    $payload = runAuditJson();

    expect($payload['summary']['soft_deleted_document_records'])->toBe(1);
    expect($payload['summary']['deleted_records_with_file_count'])->toBe(1);
    // A trashed-record file is not an orphan.
    expect($payload['summary']['orphan_files_count'])->toBe(0);
});

// --- 13. Human (non-JSON) audit runs cleanly ---------------------------------

it('runs the human-readable audit without error', function () {
    makeGovDocument();

    $this->artisan('patient-documents:audit')->assertExitCode(0);
});
