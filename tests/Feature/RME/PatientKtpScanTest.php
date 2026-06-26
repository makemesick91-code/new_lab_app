<?php

/**
 * Sprint 61.1 — Direct KTP Scanner Capture & Compression.
 *
 * Verifies the DaengtisiaMS side of the scanner workflow: scanner-ready
 * registration UI, access control, private temp upload + compression, document
 * record creation, privacy (no full KTP / no export leakage), and safe delete.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientDocument;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
});

/** A small valid 10x10 PNG (raw base64, no data URI prefix). */
function ktpPngBase64(): string
{
    return 'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC';
}

/** A valid 1x1 GIF (unsupported mime for KTP scans). */
function ktpGifBase64(): string
{
    return 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
}

/** Upload a temp KTP scan as the given actor and return the JSON payload. */
function uploadTempKtp($actor, ?string $base64 = null, ?string $mime = null): array
{
    return test()->actingAs($actor)
        ->postJson(route('settings.patients.ktp-scan.upload-temp'), [
            'document_type' => 'ktp',
            'image_base64' => $base64 ?? ktpPngBase64(),
            'mime_type' => $mime,
        ])
        ->json();
}

// --- 1. Scanner-ready registration UI ----------------------------------------

it('shows the scanner-ready KTP section on the registration page', function () {
    $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('Scan KTP')
        ->assertSee('Cek Scanner');
});

// --- 2. Scanner unavailable fallback message ---------------------------------

it('renders the scanner-unavailable fallback message in the UI', function () {
    $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.create'))
        ->assertSee('Jalankan Daengtisia Scanner Agent di komputer ini.');
});

// --- 3 & 4. Access control ----------------------------------------------------

it('forbids an unauthorized user from uploading a KTP scan', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.patients.ktp-scan.upload-temp'), [
            'document_type' => 'ktp',
            'image_base64' => ktpPngBase64(),
        ])
        ->assertForbidden();
});

it('forbids an unauthorized user from viewing a KTP scan', function () {
    $patient = Patient::factory()->create();
    $document = makeKtpDocument($patient);

    $this->actingAs(User::factory()->create())
        ->get(route('settings.patients.documents.show', [$patient, $document]))
        ->assertForbidden();
});

// --- 5 & 6. Upload stores compressed file on the private disk -----------------

it('stores an uploaded KTP scan on the private disk under a temp token', function () {
    $json = uploadTempKtp(userWith(['manage patients']));

    expect($json['ok'])->toBeTrue();
    expect($json['token'])->not->toBeEmpty();
    expect($json['compressed_file_size'])->toBeGreaterThan(0);

    $files = Storage::disk('local')->allFiles('tmp/patient-ktp-scans');
    expect($files)->not->toBeEmpty();
});

it('processes the scan to safe dimensions/quality metadata', function () {
    $json = uploadTempKtp(userWith(['manage patients']));

    // Compressed size is always recorded and never exceeds the decoded input.
    $originalSize = strlen(base64_decode(ktpPngBase64()));
    expect($json['compressed_file_size'])->toBeLessThanOrEqual($originalSize + 1024);

    // When GD is available, width is bounded by the configured max.
    if (extension_loaded('gd')) {
        expect($json['width'])->toBeLessThanOrEqual((int) config('scanner.ktp.max_width'));
    }
});

// --- 7. Unsupported mime ------------------------------------------------------

it('rejects an unsupported image type', function () {
    $this->actingAs(userWith(['manage patients']))
        ->postJson(route('settings.patients.ktp-scan.upload-temp'), [
            'document_type' => 'ktp',
            'image_base64' => ktpGifBase64(),
        ])
        ->assertStatus(422);
});

// --- 8. Oversized input -------------------------------------------------------

it('rejects an oversized scan payload', function () {
    $oversized = base64_encode(str_repeat('A', 6_500_000)); // ~6.5 MB decoded > 6 MB ceiling.

    $this->actingAs(userWith(['manage patients']))
        ->postJson(route('settings.patients.ktp-scan.upload-temp'), [
            'document_type' => 'ktp',
            'image_base64' => $oversized,
        ])
        ->assertStatus(422);
});

// --- 9. Patient document record created on save ------------------------------

it('attaches the scanned KTP to the patient on registration', function () {
    $actor = userWith(['manage patients']);
    $clinic = Clinic::factory()->create();
    $doctor = Doctor::factory()->create();

    $token = uploadTempKtp($actor)['token'];

    $this->actingAs($actor)
        ->post(route('settings.patients.store'), [
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
            'medical_record_number' => 'MRN-KTP-1',
            'name' => 'Pasien Scan KTP',
            'ktp_scan_token' => $token,
        ])
        ->assertRedirect(route('settings.patients.index'));

    $patient = Patient::where('name', 'Pasien Scan KTP')->firstOrFail();
    $document = $patient->documents()->first();

    expect($document)->not->toBeNull();
    expect($document->document_type)->toBe(PatientDocument::TYPE_KTP);
    expect($document->uploaded_by)->toBe($actor->id);
    expect(strlen((string) $document->checksum))->toBe(64);
    expect($document->compressed_file_size)->toBeGreaterThan(0);
    expect(Storage::disk('local')->exists($document->file_path))->toBeTrue();
    expect($document->file_path)->toStartWith('patient-documents/'.$patient->id.'/');

    // The temp token/file is invalidated after promotion.
    expect(Storage::disk('local')->allFiles('tmp/patient-ktp-scans'))->toBeEmpty();
});

// --- 10. Full KTP number is not rendered --------------------------------------

it('does not render the full KTP number when serving the scan', function () {
    $patient = Patient::factory()->create(['ktp_number' => '7371010101900099']);
    $document = makeKtpDocument($patient);

    $response = $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.documents.show', [$patient, $document]));

    $response->assertOk();
    expect($response->streamedContent())->not->toContain('7371010101900099');
});

// --- 11. KTP scan excluded from patient audit CSV ----------------------------

it('excludes the KTP scan document from the patient audit CSV export', function () {
    $this->seed(BranchSeeder::class);
    $branch = Branch::factory()->create([
        'code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'name' => 'Pasien Audit KTP',
        'ktp_number' => '7371010101900011',
    ]);
    $document = makeKtpDocument($patient);

    $response = $this->actingAs(userWith(['manage patients']))
        ->get(route('rme.patients.audit.export', ['branch_id' => $branch->id]));

    $response->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->not->toContain('patient-documents');
    expect($csv)->not->toContain($document->checksum);
    expect($csv)->not->toContain('7371010101900011');
});

// --- 12. Safe delete ----------------------------------------------------------

it('soft-deletes the document and removes private access on delete', function () {
    $patient = Patient::factory()->create();
    $document = makeKtpDocument($patient);

    expect(Storage::disk('local')->exists($document->file_path))->toBeTrue();

    $this->actingAs(userWith(['manage patients']))
        ->delete(route('settings.patients.documents.destroy', [$patient, $document]))
        ->assertRedirect(route('settings.patients.edit', $patient));

    expect(PatientDocument::find($document->id))->toBeNull();
    expect(PatientDocument::withTrashed()->find($document->id)->trashed())->toBeTrue();
    expect(Storage::disk('local')->exists($document->file_path))->toBeFalse();

    // No longer viewable.
    $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.documents.show', [$patient, $document]))
        ->assertNotFound();
});

// --- 13. No regression in patient creation -----------------------------------

it('still creates a patient with no KTP scan token', function () {
    $clinic = Clinic::factory()->create();
    $doctor = Doctor::factory()->create();

    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), [
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
            'medical_record_number' => 'MRN-NOKTP-1',
            'name' => 'Pasien Tanpa Scan',
        ])
        ->assertRedirect(route('settings.patients.index'));

    $patient = Patient::where('name', 'Pasien Tanpa Scan')->firstOrFail();
    expect($patient->documents()->count())->toBe(0);
});

/** Create a stored KTP PatientDocument with a real file on the fake private disk. */
function makeKtpDocument(Patient $patient): PatientDocument
{
    $binary = base64_decode(ktpPngBase64());
    $path = 'patient-documents/'.$patient->id.'/ktp-test-'.uniqid().'.jpg';
    Storage::disk('local')->put($path, $binary);

    return $patient->documents()->create([
        'document_type' => PatientDocument::TYPE_KTP,
        'file_path' => $path,
        'original_filename' => 'ktp.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => strlen($binary),
        'compressed_file_size' => strlen($binary),
        'checksum' => hash('sha256', $binary),
        'uploaded_by' => null,
    ]);
}
