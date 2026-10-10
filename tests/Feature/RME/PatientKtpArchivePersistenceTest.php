<?php

/**
 * AUDIT-PATIENT-KTP-ARCHIVE-PERSISTENCE-1 — a KTP photo taken during
 * registration must end up as the PATIENT'S permanent private document: the
 * same bytes the operator confirmed, filed under the patient that was saved,
 * readable after the registering session is gone — or, when that cannot
 * happen, the operator is told so instead of believing it was archived.
 *
 * Covers both registration surfaces (Master Data → Tambah Pasien and
 * Kunjungan → Pasien Baru) and the failure modes between the filesystem and the
 * database, which a transaction cannot roll back on its own.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientDocument;
use App\Modules\Patient\Services\KtpScanService;
use App\Modules\Treatment\Models\Treatment;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
});

/** A valid 10x10 PNG (raw base64). */
function kapPngA(): string
{
    return 'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC';
}

/** A different valid 1x1 PNG, so two captures have different bytes. */
function kapPngB(): string
{
    return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
}

/** Upload a temp KTP photo the way the camera does ('ktp-kamera.jpg'). */
function kapUpload($actor, string $base64, ?string $replaces = null): array
{
    return test()->actingAs($actor)
        ->postJson(route('settings.patients.ktp-scan.upload-temp'), [
            'document_type' => 'ktp',
            'image_base64' => $base64,
            'filename' => 'ktp-kamera.jpg',
            'replaces_token' => $replaces,
        ])
        ->assertOk()
        ->json();
}

/** The bytes parked for a temp token (whatever the compressor produced). */
function kapTempBytes(int $userId, string $token): string
{
    $disk = Storage::disk('local');
    $meta = json_decode((string) $disk->get("tmp/patient-ktp-scans/{$userId}/{$token}.json"), true);

    return (string) $disk->get($meta['file_path']);
}

function kapTempPath(int $userId, string $token): string
{
    $meta = json_decode((string) Storage::disk('local')->get("tmp/patient-ktp-scans/{$userId}/{$token}.json"), true);

    return $meta['file_path'];
}

/** Register through Master Data → Tambah Pasien. */
function kapRegisterMasterData($actor, string $name, ?string $token)
{
    return test()->actingAs($actor)->post(route('settings.patients.store'), [
        'clinic_id' => Clinic::factory()->create()->id,
        'doctor_id' => Doctor::factory()->create()->id,
        'medical_record_number' => 'MRN-'.Str::upper(Str::random(8)),
        'name' => $name,
        'ktp_scan_token' => $token,
    ]);
}

function kapPatientFiles(Patient $patient): array
{
    return Storage::disk('local')->allFiles('patient-documents/'.$patient->id);
}

// --- 1. Master Data: the confirmed photo becomes the patient's archive ------

it('archives the camera photo as the patient\'s private KTP document, readable after the session ends', function () {
    $actor = userWith(['manage patients']);
    $token = kapUpload($actor, kapPngA())['token'];
    $confirmed = kapTempBytes($actor->id, $token);

    kapRegisterMasterData($actor, 'Pasien Arsip Kamera', $token)
        ->assertRedirect(route('settings.patients.index'))
        ->assertSessionMissing('warning');

    $patient = Patient::where('name', 'Pasien Arsip Kamera')->sole();
    $document = $patient->documents()->sole();
    $stored = Storage::disk('local')->get($document->file_path);

    expect($document->document_type)->toBe(PatientDocument::TYPE_KTP)
        ->and($document->original_filename)->toBe('ktp-kamera.jpg')
        ->and($document->uploaded_by)->toBe($actor->id)
        ->and($document->file_path)->toStartWith('patient-documents/'.$patient->id.'/')
        // Identity: the archive holds exactly the bytes that were confirmed.
        ->and($stored)->toBe($confirmed)
        ->and($document->checksum)->toBe(hash('sha256', $confirmed))
        ->and($document->compressed_file_size)->toBe(strlen($confirmed))
        ->and(Storage::disk('local')->allFiles('tmp/patient-ktp-scans'))->toBeEmpty();

    // A different authorized user, in a fresh session, still reads it back.
    $reader = userWith(['manage patients']);
    $response = $this->actingAs($reader)
        ->get(route('settings.patients.documents.show', [$patient, $document]))
        ->assertOk();

    expect($response->streamedContent())->toBe($confirmed)
        ->and($response->headers->get('Content-Type'))->toBe($document->mime_type);
});

// --- 2. Honest outcome when a submitted photo cannot be attached ------------

it('tells the operator when a submitted KTP photo could not be archived', function () {
    $actor = userWith(['manage patients']);

    kapRegisterMasterData($actor, 'Pasien Token Kedaluwarsa', (string) Str::uuid())
        ->assertRedirect(route('settings.patients.index'))
        ->assertSessionHas('warning', KtpScanService::NOT_ATTACHED_WARNING);

    // Registration itself is never blocked by the photo.
    $patient = Patient::where('name', 'Pasien Token Kedaluwarsa')->sole();
    expect($patient->documents()->count())->toBe(0);
});

it('does not warn when no KTP photo was taken', function () {
    kapRegisterMasterData(userWith(['manage patients']), 'Pasien Tanpa Foto', null)
        ->assertSessionMissing('warning');
});

it('keeps a photo across a failed submit, withdrawable before saving', function () {
    $actor = userWith(['manage patients']);
    $token = kapUpload($actor, kapPngA())['token'];

    // A validation failure re-renders the form carrying the token, with the
    // notice the browser can withdraw ("Hapus Preview" detaches it).
    $this->actingAs($actor)
        ->from(route('settings.patients.create'))
        ->post(route('settings.patients.store'), ['ktp_scan_token' => $token])
        ->assertRedirect(route('settings.patients.create'))
        ->assertSessionHasErrors();

    $this->actingAs($actor)->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('value="'.$token.'"', false)
        ->assertSee('data-ktp-carried', false);

    expect(PatientDocument::count())->toBe(0);
});

// --- 3. Another operator's temp photo is never attached ---------------------

it('never attaches another operator\'s temp photo, and leaves it untouched', function () {
    $owner = userWith(['manage patients']);
    $other = userWith(['manage patients']);
    $token = kapUpload($owner, kapPngA())['token'];

    kapRegisterMasterData($other, 'Pasien Token Orang Lain', $token)
        ->assertSessionHas('warning', KtpScanService::NOT_ATTACHED_WARNING);

    expect(Patient::where('name', 'Pasien Token Orang Lain')->sole()->documents()->count())->toBe(0)
        ->and(Storage::disk('local')->exists(kapTempPath($owner->id, $token)))->toBeTrue();
});

// --- 4. Stored image identity -----------------------------------------------

it('refuses to archive a temp photo whose bytes changed after it was confirmed', function () {
    $actor = userWith(['manage patients']);
    $token = kapUpload($actor, kapPngA())['token'];
    $tempPath = kapTempPath($actor->id, $token);
    Storage::disk('local')->put($tempPath, 'not-the-confirmed-image');

    kapRegisterMasterData($actor, 'Pasien Foto Berubah', $token)
        ->assertSessionHas('warning', KtpScanService::NOT_ATTACHED_WARNING);

    $patient = Patient::where('name', 'Pasien Foto Berubah')->sole();
    expect($patient->documents()->count())->toBe(0)
        ->and(kapPatientFiles($patient))->toBeEmpty();
});

it('archives the retake, not the photo it replaced', function () {
    $actor = userWith(['manage patients']);
    $first = kapUpload($actor, kapPngA())['token'];
    $retake = kapUpload($actor, kapPngB(), $first)['token'];
    $retakeBytes = kapTempBytes($actor->id, $retake);

    kapRegisterMasterData($actor, 'Pasien Foto Ulang', $retake)->assertSessionMissing('warning');

    $document = Patient::where('name', 'Pasien Foto Ulang')->sole()->documents()->sole();
    expect(Storage::disk('local')->get($document->file_path))->toBe($retakeBytes)
        ->and(Storage::disk('local')->exists("tmp/patient-ktp-scans/{$actor->id}/{$first}.json"))->toBeFalse();
});

it('never archives an empty file as a KTP document', function () {
    $actor = userWith(['manage patients']);
    $token = (string) Str::uuid();
    $disk = Storage::disk('local');
    $disk->put("tmp/patient-ktp-scans/{$actor->id}/{$token}.jpg", '');
    $disk->put("tmp/patient-ktp-scans/{$actor->id}/{$token}.json", json_encode([
        'mime_type' => 'image/jpeg',
        'file_path' => "tmp/patient-ktp-scans/{$actor->id}/{$token}.jpg",
        'created_at' => now()->toIso8601String(),
    ]));

    kapRegisterMasterData($actor, 'Pasien Berkas Kosong', $token)
        ->assertSessionHas('warning', KtpScanService::NOT_ATTACHED_WARNING);

    $patient = Patient::where('name', 'Pasien Berkas Kosong')->sole();
    expect($patient->documents()->count())->toBe(0)
        ->and(kapPatientFiles($patient))->toBeEmpty();
});

// --- 5. Filesystem / database consistency ------------------------------------

it('removes the archived file again when its document record cannot be written', function () {
    $actor = userWith(['manage patients']);
    $token = kapUpload($actor, kapPngA())['token'];
    $tempPath = kapTempPath($actor->id, $token);
    PatientDocument::creating(fn () => throw new RuntimeException('simulated insert failure'));

    kapRegisterMasterData($actor, 'Pasien Gagal Rekam', $token)
        ->assertRedirect(route('settings.patients.index'))
        ->assertSessionHas('warning', KtpScanService::NOT_ATTACHED_WARNING);

    $patient = Patient::where('name', 'Pasien Gagal Rekam')->sole();
    expect($patient->documents()->count())->toBe(0)
        // No file without its record …
        ->and(kapPatientFiles($patient))->toBeEmpty()
        // … and the confirmed photo is not lost either: the claim is put back.
        ->and(Storage::disk('local')->exists($tempPath))->toBeTrue()
        ->and(Storage::disk('local')->exists("tmp/patient-ktp-scans/{$actor->id}/{$token}.json"))->toBeTrue();
});

it('records no document when the archive file cannot be written', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root ignores directory permissions');
    }

    $actor = userWith(['manage patients']);
    $token = kapUpload($actor, kapPngA())['token'];
    $tempPath = kapTempPath($actor->id, $token);
    $disk = Storage::disk('local');
    $disk->makeDirectory('patient-documents');
    $root = $disk->path('patient-documents');
    chmod($root, 0555);

    try {
        kapRegisterMasterData($actor, 'Pasien Gagal Tulis', $token)
            ->assertSessionHas('warning', KtpScanService::NOT_ATTACHED_WARNING);
    } finally {
        chmod($root, 0755);
    }

    expect(Patient::where('name', 'Pasien Gagal Tulis')->sole()->documents()->count())->toBe(0)
        ->and($disk->exists($tempPath))->toBeTrue();
});

it('archives a photo at most once, however often its token is submitted', function () {
    $actor = userWith(['manage patients']);
    $token = kapUpload($actor, kapPngA())['token'];

    kapRegisterMasterData($actor, 'Pasien Pertama', $token)->assertSessionMissing('warning');
    kapRegisterMasterData($actor, 'Pasien Kedua', $token)
        ->assertSessionHas('warning', KtpScanService::NOT_ATTACHED_WARNING);

    expect(PatientDocument::count())->toBe(1)
        ->and(Patient::where('name', 'Pasien Kedua')->sole()->documents()->count())->toBe(0);
});

it('archives a token once even when a second request arrives mid-archive', function () {
    $actor = userWith(['manage patients']);
    $token = kapUpload($actor, kapPngA())['token'];
    $other = Patient::factory()->create();

    // While the first request is inserting its document row, a concurrent
    // request (a double-clicked Save) tries to archive the same token.
    $armed = true;
    $concurrent = 'not-run';
    PatientDocument::creating(function () use (&$armed, &$concurrent, $other, $token, $actor) {
        if (! $armed) {
            return;
        }
        $armed = false;
        $concurrent = app(KtpScanService::class)->attachTempToPatient($other, $token, $actor->id);
    });

    kapRegisterMasterData($actor, 'Pasien Klik Ganda', $token)->assertSessionMissing('warning');

    expect($concurrent)->toBeNull()
        ->and(PatientDocument::count())->toBe(1)
        ->and($other->documents()->count())->toBe(0)
        ->and(kapPatientFiles($other))->toBeEmpty();
});

// --- 6. Retrieval security ------------------------------------------------------

it('serves the archive privately and only through its own patient', function () {
    $actor = userWith(['manage patients']);
    $token = kapUpload($actor, kapPngA())['token'];
    kapRegisterMasterData($actor, 'Pasien Privat', $token);
    $patient = Patient::where('name', 'Pasien Privat')->sole();
    $document = $patient->documents()->sole();

    $response = $this->actingAs($actor)->get(route('settings.patients.documents.show', [$patient, $document]))->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    $otherPatient = Patient::factory()->create();
    $this->actingAs($actor)->get(route('settings.patients.documents.show', [$otherPatient, $document]))->assertNotFound();
    $this->actingAs(userWith([]))->get(route('settings.patients.documents.show', [$patient, $document]))->assertForbidden();

    auth()->logout();
    $this->get(route('settings.patients.documents.show', [$patient, $document]))->assertRedirect(route('login'));

    // The framework's local-disk file route never serves it without a signature.
    $direct = $this->get('/storage/'.$document->file_path);
    expect($direct->getStatusCode())->toBeIn([403, 404]);
});

// --- 7. Kunjungan → Daftar Kunjungan Baru (Pasien Baru panel) ---------------

describe('visit registration', function () {
    beforeEach(function () {
        test()->seed(BranchSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-06-26 09:00:00'));
        $this->clinic = Clinic::factory()->create();
        $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
        $this->treatment = Treatment::factory()->create(['is_active' => true]);
        $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
        $this->branch->update(['is_rme_enabled' => true]);
        rmeMakeDoctorOnline($this->doctor, $this->branch);
        $this->actor = userWith(['manage_clinic_visits', 'view_clinic_visits', 'manage patients']);
    });

    $register = function (string $name, ?string $token) {
        return test()->actingAs(test()->actor)->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'clinic_id' => test()->clinic->id,
            'doctor_id' => test()->doctor->id,
            'initial_treatment_id' => test()->treatment->id,
            'new_patient' => [
                'name' => $name,
                'branch_id' => test()->branch->id,
                'registered_at' => '2026-06-26',
                'manual_rm_number' => (string) random_int(1000, 9999),
            ],
            'ktp_scan_token' => $token,
        ]);
    };

    it('archives the camera photo for a patient created in the visit flow', function () use ($register) {
        $token = kapUpload($this->actor, kapPngA())['token'];
        $confirmed = kapTempBytes($this->actor->id, $token);

        $register('Pasien Kunjungan Kamera', $token)->assertRedirect()->assertSessionMissing('warning');

        $patient = Patient::where('name', 'Pasien Kunjungan Kamera')->sole();
        $document = $patient->documents()->sole();
        expect(Storage::disk('local')->get($document->file_path))->toBe($confirmed);

        $this->actingAs(userWith(['manage patients']))
            ->get(route('settings.patients.documents.show', [$patient, $document]))
            ->assertOk();
    });

    it('tells the operator when the visit-flow KTP photo could not be archived', function () use ($register) {
        $register('Pasien Kunjungan Tanpa Arsip', (string) Str::uuid())
            ->assertRedirect()
            ->assertSessionHas('warning', KtpScanService::NOT_ATTACHED_WARNING);

        expect(Patient::where('name', 'Pasien Kunjungan Tanpa Arsip')->sole()->documents()->count())->toBe(0);
    });
});
