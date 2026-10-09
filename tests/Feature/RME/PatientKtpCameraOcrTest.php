<?php

/**
 * REVISION-REGISTRATION-KTP-CAMERA-OCR-1 — camera capture + KTP OCR suggestions.
 *
 * The OCR engine runs in the browser (self-hosted tesseract.js); these tests
 * cover the server side: the parser that turns OCR text into validated,
 * flagged suggestions, the parse endpoint's security boundary, the upload
 * hardening (decompression bomb, retake discard, per-user temp isolation) and
 * the registration integration (explicit operator confirmation; OCR values
 * validated exactly like manual entry). All identities below are FICTIONAL.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\KtpOcrParser;
use App\Modules\Treatment\Models\Treatment;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00'));
    ktpOcrFlag(true);
});

afterEach(fn () => Carbon::setTestNow());

/** The flag key contains a dot, so the whole registry array is rewritten. */
function ktpOcrFlag(bool $on): void
{
    $flags = config('feature_flags.flags');
    $flags['patient.ktp_camera_ocr']['default'] = $on;
    $flags['patient.ktp_camera_ocr']['env_value'] = null;
    config(['feature_flags.flags' => $flags]);
}

/** Lines exactly as tesseract.js read a synthetic (fictional) KTP photo. */
function ktpOcrLines(array $overrides = [], array $drop = []): array
{
    $lines = [
        'header1' => ['text' => 'PROVINSI SULAWESI SELATAN', 'confidence' => 94],
        'header2' => ['text' => 'KOTA MAKASSAR', 'confidence' => 96],
        'nik' => ['text' => 'NIK : 7371015708900003', 'confidence' => 92],
        'name' => ['text' => 'Nama : SITI CONTOH RAHMAWATI', 'confidence' => 92],
        'birth' => ['text' => 'Tempat/Tgl! Lahir : MAKASSAR, 17-08-1990', 'confidence' => 80],
        'gender' => ['text' => 'Jenis Kelamin : PEREMPUAN Gol. Darah: O', 'confidence' => 84],
        'address' => ['text' => 'Alamat : JL. CONTOH RAYA NO. 12', 'confidence' => 82],
        'rtrw' => ['text' => 'RT/RW : 003/005', 'confidence' => 88],
        'village' => ['text' => 'Kel/Desa : MARISO', 'confidence' => 91],
        'district' => ['text' => 'Kecamatan : MARISO', 'confidence' => 92],
        'religion' => ['text' => 'Agama : ISLAM', 'confidence' => 93],
        'marital' => ['text' => 'Status Perkawinan : KAWIN', 'confidence' => 93],
        'occupation' => ['text' => 'Pekerjaan : KARYAWAN SWASTA', 'confidence' => 94],
        'citizen' => ['text' => 'Kewarganegaraan : WNI', 'confidence' => 84],
    ];
    foreach ($drop as $key) {
        unset($lines[$key]);
    }
    foreach ($overrides as $key => $line) {
        $lines[$key] = $line;
    }

    return array_values($lines);
}

function ktpParse(array $lines, float $threshold = 75.0): array
{
    return app(KtpOcrParser::class)->parse($lines, $threshold);
}

function ktpOcrActor(): User
{
    return userWith(['manage patients']);
}

/** A PNG whose HEADER declares $w x $h (no pixel data needed to trip the guard). */
function ktpPngHeaderOnly(int $w, int $h): string
{
    $ihdr = pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
    $chunk = pack('N', strlen($ihdr)).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));

    return base64_encode("\x89PNG\r\n\x1a\n".$chunk);
}

function ktpSmallPng(): string
{
    return 'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC';
}

function ktpUpload(User $actor, array $extra = []): array
{
    return test()->actingAs($actor)
        ->postJson(route('settings.patients.ktp-scan.upload-temp'), [
            'document_type' => 'ktp',
            'image_base64' => ktpSmallPng(),
        ] + $extra)
        ->json();
}

// ---------------------------------------------------------------- parser --

it('extracts every supported field from a clean KTP read', function () {
    $r = ktpParse(ktpOcrLines());

    expect($r['outcome'])->toBe(KtpOcrParser::OUTCOME_SUCCESS)
        ->and($r['form']['ktp_number']['value'])->toBe('7371015708900003')
        ->and($r['form']['name']['value'])->toBe('SITI CONTOH RAHMAWATI')
        ->and($r['form']['date_of_birth']['value'])->toBe('1990-08-17')
        ->and($r['form']['gender']['value'])->toBe('Female')
        ->and($r['form']['address']['value'])->toBe('JL. CONTOH RAYA NO. 12, RT/RW 003/005, KEL. MARISO, KEC. MARISO')
        ->and($r['form']['occupation']['value'])->toBe('KARYAWAN SWASTA')
        ->and($r['fields']['birth_place']['value'])->toBe('MAKASSAR')
        ->and($r['fields']['religion']['value'])->toBe('ISLAM')
        ->and($r['fields']['marital_status']['value'])->toBe('KAWIN');

    foreach ($r['form'] as $suggestion) {
        expect($suggestion['status'])->toBe('ok')->and($suggestion['suggest'])->toBeTrue();
    }
});

it('only offers form fields that exist on mst_patients', function () {
    expect(array_keys(ktpParse(ktpOcrLines())['form']))
        ->toBe(['ktp_number', 'name', 'date_of_birth', 'gender', 'address', 'occupation']);

    // Every offered target is a real, fillable patient column.
    foreach (array_keys(KtpOcrParser::FORM_FIELDS) as $column) {
        expect((new Patient)->isFillable($column))->toBeTrue();
    }
});

it('reports a partial read with missing fields as null, never guessed', function () {
    $r = ktpParse(ktpOcrLines([], ['birth', 'gender', 'occupation']));

    expect($r['outcome'])->toBe(KtpOcrParser::OUTCOME_PARTIAL)
        ->and($r['form']['date_of_birth'])->toMatchArray(['value' => null, 'status' => 'missing', 'suggest' => false])
        ->and($r['form']['gender'])->toMatchArray(['value' => null, 'status' => 'missing'])
        ->and($r['form']['occupation'])->toMatchArray(['value' => null, 'status' => 'missing'])
        ->and($r['form']['ktp_number']['value'])->toBe('7371015708900003');
});

it('never derives the birth date or gender from the NIK', function () {
    $r = ktpParse(ktpOcrLines([], ['birth', 'gender']));

    // The NIK encodes 17-08-1990 / female, yet neither is filled in.
    expect($r['form']['date_of_birth']['value'])->toBeNull()
        ->and($r['form']['gender']['value'])->toBeNull();
});

it('fails cleanly on an image that is not a readable KTP', function () {
    $r = ktpParse([
        ['text' => 'lorem ipsum', 'confidence' => 40],
        ['text' => '@@@ ### ~~~', 'confidence' => 12],
    ]);

    expect($r['outcome'])->toBe(KtpOcrParser::OUTCOME_FAILED)
        ->and($r['recognized_form_fields'])->toBe(0);
    foreach ($r['form'] as $s) {
        expect($s['value'])->toBeNull();
    }
});

it('rejects an invalid NIK instead of offering it', function (string $nik, string $reason) {
    $r = ktpParse(ktpOcrLines(['nik' => ['text' => 'NIK : '.$nik, 'confidence' => 95]]));

    expect($r['form']['ktp_number'])->toMatchArray(['value' => null, 'status' => 'invalid', 'suggest' => false])
        ->and($r['fields']['nik']['reasons'])->toContain($reason);
})->with([
    'too short' => ['737101570890000', 'nik_not_16_digits'],
    'too long' => ['73710157089000031', 'nik_not_16_digits'],
    'letters' => ['7371ABCD08900003', 'nik_not_16_digits'],
    'province' => ['0971015708900003', 'nik_province_code_invalid'],
    'month 13' => ['7371015713900003', 'nik_birth_segment_invalid'],
    'day 35' => ['7371013508900003', 'nik_birth_segment_invalid'],
]);

it('flags a NIK that needed glyph correction as low confidence', function () {
    $r = ktpParse(ktpOcrLines(['nik' => ['text' => 'NIK : 737IO157O89OOOO3', 'confidence' => 95]]));

    expect($r['form']['ktp_number']['value'])->toBe('7371015708900003')
        ->and($r['form']['ktp_number']['status'])->toBe('low_confidence')
        ->and($r['form']['ktp_number']['suggest'])->toBeFalse()
        ->and($r['fields']['nik']['reasons'])->toContain('nik_glyph_corrected');
});

it('demotes NIK and birth date when they disagree', function () {
    $r = ktpParse(ktpOcrLines(['birth' => ['text' => 'Tempat/Tgl Lahir : MAKASSAR, 18-08-1990', 'confidence' => 95]]));

    expect($r['form']['ktp_number']['status'])->toBe('low_confidence')
        ->and($r['form']['date_of_birth']['status'])->toBe('low_confidence')
        ->and($r['fields']['date_of_birth']['reasons'])->toContain('nik_birth_date_mismatch')
        ->and($r['outcome'])->toBe(KtpOcrParser::OUTCOME_PARTIAL);
});

it('demotes NIK and gender when they disagree', function () {
    $r = ktpParse(ktpOcrLines(['gender' => ['text' => 'Jenis Kelamin : LAKI-LAKI', 'confidence' => 95]]));

    expect($r['form']['gender']['status'])->toBe('low_confidence')
        ->and($r['fields']['gender']['reasons'])->toContain('nik_gender_mismatch');
});

it('rejects impossible and future birth dates', function (string $date, string $reason) {
    $r = ktpParse(ktpOcrLines(['birth' => ['text' => 'Tempat/Tgl Lahir : MAKASSAR, '.$date, 'confidence' => 95]]));

    expect($r['form']['date_of_birth'])->toMatchArray(['value' => null, 'status' => 'invalid'])
        ->and($r['fields']['date_of_birth']['reasons'])->toContain($reason)
        ->and($r['fields']['birth_place']['value'])->toBe('MAKASSAR');
})->with([
    'feb 30' => ['30-02-1990', 'date_not_a_calendar_date'],
    'month 13' => ['17-13-1990', 'date_not_a_calendar_date'],
    'future' => ['17-08-2031', 'date_in_future'],
    'implausibly old' => ['17-08-1850', 'date_implausibly_old'],
]);

it('offers a below-threshold read but never pre-selects it', function () {
    $r = ktpParse(ktpOcrLines(['name' => ['text' => 'Nama : SITI CONTOH RAHMAWATI', 'confidence' => 51]]));

    expect($r['form']['name'])->toMatchArray([
        'value' => 'SITI CONTOH RAHMAWATI', 'status' => 'low_confidence', 'suggest' => false,
    ]);
});

it('treats a missing confidence score as low confidence', function () {
    $r = ktpParse(ktpOcrLines(['occupation' => ['text' => 'Pekerjaan : GURU', 'confidence' => null]]));

    expect($r['form']['occupation']['status'])->toBe('low_confidence');
});

it('marks a label read twice with different values as ambiguous', function () {
    $lines = ktpOcrLines();
    $lines[] = ['text' => 'NIK : 7371015708900011', 'confidence' => 95];

    $r = ktpParse($lines);

    expect($r['form']['ktp_number'])->toMatchArray(['value' => null, 'status' => 'ambiguous']);
});

it('flags a reconstructed RT/RW and the address that contains it', function () {
    $r = ktpParse(ktpOcrLines(['rtrw' => ['text' => 'RT/RW : 003005', 'confidence' => 90]]));

    expect($r['fields']['rt_rw'])->toMatchArray(['value' => '003/005', 'status' => 'low_confidence'])
        ->and($r['form']['address']['status'])->toBe('low_confidence')
        ->and($r['form']['address']['suggest'])->toBeFalse();
});

it('keeps markup in OCR text as inert data and flags the misread name', function () {
    $r = ktpParse(ktpOcrLines(['name' => ['text' => 'Nama : <script>alert(1)</script>', 'confidence' => 99]]));

    expect($r['form']['name']['status'])->toBe('low_confidence')
        ->and($r['form']['name']['suggest'])->toBeFalse()
        ->and($r['fields']['name']['reasons'])->toContain('name_contains_unexpected_characters');
});

// -------------------------------------------------------------- endpoint --

it('parses OCR text for an authorized operator and stores nothing', function () {
    $patients = Patient::count();
    $audits = AuditLog::count();

    $response = $this->actingAs(ktpOcrActor())
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), ['lines' => ktpOcrLines()])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('outcome', 'success')
        ->assertJsonPath('form.ktp_number.value', '7371015708900003');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Patient::count())->toBe($patients)
        ->and(AuditLog::count())->toBe($audits)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('denies the parse endpoint to users who cannot register patients', function () {
    $this->actingAs(userWith(['view_clinic_visits']))
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), ['lines' => ktpOcrLines()])
        ->assertForbidden();
});

it('requires authentication for the parse endpoint', function () {
    $this->postJson(route('settings.patients.ktp-scan.parse-ocr'), ['lines' => ktpOcrLines()])
        ->assertUnauthorized();
});

it('answers 404 when the capability is switched off', function () {
    ktpOcrFlag(false);

    $this->actingAs(ktpOcrActor())
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), ['lines' => ktpOcrLines()])
        ->assertNotFound();
});

it('bounds the untrusted OCR payload', function (array $payload) {
    $this->actingAs(ktpOcrActor())
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), $payload)
        ->assertStatus(422);
})->with([
    'no lines key' => [[]],
    'too many lines' => [['lines' => array_fill(0, 61, ['text' => 'a', 'confidence' => 90])]],
    'line too long' => [['lines' => [['text' => str_repeat('A', 201), 'confidence' => 90]]]],
    'bad confidence' => [['lines' => [['text' => 'NIK', 'confidence' => 101]]]],
    'extra keys' => [['lines' => [['text' => 'NIK', 'confidence' => 90, 'patient_id' => 1]]]],
]);

it('rate-limits the parse endpoint per user', function () {
    $actor = ktpOcrActor();
    RateLimiter::clear('ktp-scan');

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($actor)
            ->postJson(route('settings.patients.ktp-scan.parse-ocr'), ['lines' => ktpOcrLines()])
            ->assertOk();
    }

    $this->actingAs($actor)
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), ['lines' => ktpOcrLines()])
        ->assertStatus(429);

    // A different operator is unaffected.
    $this->actingAs(ktpOcrActor())
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), ['lines' => ktpOcrLines()])
        ->assertOk();
});

it('is not a duplicate-patient oracle, even across branches', function () {
    $this->seed(BranchSeeder::class);
    $otherBranch = Branch::factory()->create(['code' => 'ATG3', 'is_active' => true, 'is_rme_enabled' => true]);
    Patient::factory()->create([
        'branch_id' => $otherBranch->id,
        'name' => 'Pasien Cabang Lain Fiktif',
        'ktp_number' => '7371015708900003',
    ]);

    $withExisting = $this->actingAs(ktpOcrActor())
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), ['lines' => ktpOcrLines()])
        ->assertOk();

    expect($withExisting->getContent())->not->toContain('Pasien Cabang Lain Fiktif')
        ->not->toContain('ATG3')
        ->not->toContain('duplicate')
        ->and(array_keys($withExisting->json()))->toBe(['ok', 'outcome', 'fields', 'form', 'recognized_form_fields', 'threshold']);
});

// ---------------------------------------------------------------- upload --

it('rejects a decompression bomb from the header, before decoding', function (int $w, int $h) {
    $this->actingAs(ktpOcrActor())
        ->postJson(route('settings.patients.ktp-scan.upload-temp'), [
            'document_type' => 'ktp',
            'image_base64' => ktpPngHeaderOnly($w, $h),
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Dimensi gambar KTP tidak valid.');

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
})->with([
    'side over the limit' => [20000, 600],
    'area over the limit, sides legal' => [10000, 10000],
]);

it('discards the superseded temp image on a retake', function () {
    $actor = ktpOcrActor();
    $first = ktpUpload($actor)['token'];
    $second = ktpUpload($actor, ['replaces_token' => $first])['token'];

    $files = Storage::disk('local')->allFiles('tmp/patient-ktp-scans/'.$actor->id);
    expect($files)->toHaveCount(2) // image + sidecar of the retake only
        ->and(implode(',', $files))->toContain($second)
        ->not->toContain($first);
});

it('cannot discard or attach another operator\'s temp image', function () {
    $owner = ktpOcrActor();
    $intruder = ktpOcrActor();
    $token = ktpUpload($owner)['token'];

    // Naming someone else's token as "replaced" deletes nothing of theirs.
    ktpUpload($intruder, ['replaces_token' => $token]);
    expect(Storage::disk('local')->allFiles('tmp/patient-ktp-scans/'.$owner->id))->toHaveCount(2);

    // Nor can it be attached to a patient registered by somebody else.
    $this->actingAs($intruder)->post(route('settings.patients.store'), [
        'clinic_id' => Clinic::factory()->create()->id,
        'doctor_id' => Doctor::factory()->create()->id,
        'medical_record_number' => 'MRN-OCR-X',
        'name' => 'Pasien Fiktif Intrusi',
        'ktp_scan_token' => $token,
    ])->assertRedirect(route('settings.patients.index'));

    expect(Patient::firstWhere('name', 'Pasien Fiktif Intrusi')->documents()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('tmp/patient-ktp-scans/'.$owner->id))->toHaveCount(2);
});

it('rejects a malformed replaces_token', function () {
    $this->actingAs(ktpOcrActor())
        ->postJson(route('settings.patients.ktp-scan.upload-temp'), [
            'document_type' => 'ktp',
            'image_base64' => ktpSmallPng(),
            'replaces_token' => '../../patient-documents/1/x',
        ])
        ->assertStatus(422);
});

// ---------------------------------------------------- registration flows --

it('requires explicit confirmation once OCR values were applied', function () {
    $actor = ktpOcrActor();
    $token = ktpUpload($actor)['token'];
    $payload = [
        'clinic_id' => Clinic::factory()->create()->id,
        'doctor_id' => Doctor::factory()->create()->id,
        'medical_record_number' => 'MRN-OCR-1',
        'name' => 'SITI CONTOH RAHMAWATI',
        'ktp_number' => '7371015708900003',
        'ktp_scan_token' => $token,
        'ktp_ocr_applied' => '1',
    ];

    $this->actingAs($actor)
        ->from(route('settings.patients.create'))
        ->post(route('settings.patients.store'), $payload)
        ->assertSessionHasErrors('ktp_ocr_verified');
    expect(Patient::where('name', 'SITI CONTOH RAHMAWATI')->exists())->toBeFalse();

    // The failed attempt keeps the captured image: the token survives in old
    // input, so a corrected resubmit attaches it instead of orphaning it.
    $this->actingAs($actor)->get(route('settings.patients.create'))
        ->assertSee('value="'.$token.'"', false)
        ->assertSee('Saya sudah mencocokkan data hasil baca KTP');

    $this->actingAs($actor)
        ->post(route('settings.patients.store'), $payload + ['ktp_ocr_verified' => '1'])
        ->assertRedirect(route('settings.patients.index'));

    $patient = Patient::firstWhere('name', 'SITI CONTOH RAHMAWATI');
    expect($patient->ktp_number)->toBe('7371015708900003')
        ->and($patient->documents()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles('tmp/patient-ktp-scans'))->toBeEmpty();
});

it('saves the operator-corrected value, not the OCR suggestion', function () {
    $this->actingAs(ktpOcrActor())
        ->post(route('settings.patients.store'), [
            'clinic_id' => Clinic::factory()->create()->id,
            'doctor_id' => Doctor::factory()->create()->id,
            'medical_record_number' => 'MRN-OCR-2',
            'name' => 'Siti Contoh Rahmawati', // corrected from "SITICONTOH RAHMAWATI"
            'ktp_ocr_applied' => '1',
            'ktp_ocr_verified' => '1',
        ])
        ->assertRedirect(route('settings.patients.index'));

    expect(Patient::where('name', 'Siti Contoh Rahmawati')->exists())->toBeTrue();
});

it('still blocks a duplicate NIK even when it came from OCR', function () {
    Patient::factory()->create(['ktp_number' => '7371015708900003']);

    $this->actingAs(ktpOcrActor())
        ->post(route('settings.patients.store'), [
            'clinic_id' => Clinic::factory()->create()->id,
            'doctor_id' => Doctor::factory()->create()->id,
            'medical_record_number' => 'MRN-OCR-3',
            'name' => 'Pasien Fiktif Duplikat',
            'ktp_number' => '7371015708900003',
            'ktp_ocr_applied' => '1',
            'ktp_ocr_verified' => '1',
        ])
        ->assertSessionHasErrors('ktp_number');

    expect(Patient::where('name', 'Pasien Fiktif Duplikat')->exists())->toBeFalse();
});

it('does not require OCR confirmation when OCR was not used', function () {
    $this->actingAs(ktpOcrActor())
        ->post(route('settings.patients.store'), [
            'clinic_id' => Clinic::factory()->create()->id,
            'doctor_id' => Doctor::factory()->create()->id,
            'medical_record_number' => 'MRN-OCR-4',
            'name' => 'Pasien Manual Fiktif',
            'ktp_ocr_applied' => '0',
        ])
        ->assertRedirect(route('settings.patients.index'));
});

describe('RME visit "Pasien Baru" flow', function () {
    beforeEach(function () {
        $this->seed(BranchSeeder::class);
        $this->clinic = Clinic::factory()->create();
        $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
        $this->treatment = Treatment::factory()->create(['is_active' => true]);
        $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
        $this->branch->update(['is_rme_enabled' => true]);
        rmeMakeDoctorOnline($this->doctor, $this->branch);
        $this->actor = userWith(['manage_clinic_visits', 'view_clinic_visits', 'manage patients']);
    });

    $visit = fn (array $extra = []) => [
        'patient_mode' => 'new',
        'clinic_id' => test()->clinic->id,
        'doctor_id' => test()->doctor->id,
        'initial_treatment_id' => test()->treatment->id,
        'new_patient' => [
            'name' => 'Pasien Kunjungan OCR Fiktif',
            'branch_id' => test()->branch->id,
            'registered_at' => '2026-10-09',
            'manual_rm_number' => '0777',
            'ktp_number' => '7371015708900003',
        ],
    ] + $extra;

    it('requires confirmation for applied OCR values', function () use ($visit) {
        $this->actingAs($this->actor)
            ->post(route('rme.visits.store'), $visit(['ktp_ocr_applied' => '1']))
            ->assertSessionHasErrors('ktp_ocr_verified');

        $this->actingAs($this->actor)
            ->post(route('rme.visits.store'), $visit(['ktp_ocr_applied' => '1', 'ktp_ocr_verified' => '1']))
            ->assertRedirect();

        expect(Patient::firstWhere('name', 'Pasien Kunjungan OCR Fiktif')?->ktp_number)->toBe('7371015708900003');
    });

    it('ignores a stale applied flag on an existing-patient visit', function () {
        $patient = Patient::factory()->create();

        $this->actingAs($this->actor)
            ->post(route('rme.visits.store'), [
                'patient_mode' => 'existing',
                'patient_id' => $patient->id,
                'branch_id' => $this->branch->id,
                'clinic_id' => $this->clinic->id,
                'doctor_id' => $this->doctor->id,
                'initial_treatment_id' => $this->treatment->id,
                'ktp_ocr_applied' => '1',
            ])
            ->assertSessionDoesntHaveErrors('ktp_ocr_verified');
    });

    it('renders the camera + OCR controls with the new_patient field prefix', function () {
        $this->actingAs($this->actor)
            ->get(route('rme.visits.create'))
            ->assertOk()
            ->assertSee('Foto KTP dengan Kamera')
            ->assertSee('data-field-prefix="new_patient"', false)
            ->assertSee(route('settings.patients.ktp-scan.parse-ocr'), false);
    });
});

// ------------------------------------------------------------------ pages --

it('shows camera capture, OCR and manual fallback when enabled', function () {
    $this->actingAs(ktpOcrActor())
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('Foto KTP dengan Kamera')
        ->assertSee('Ambil Foto')
        ->assertSee('Gunakan Foto Ini')
        ->assertSee('Ulangi')
        ->assertSee('Terapkan ke Formulir')
        ->assertSee('Unggah foto KTP secara manual')
        ->assertSee('data-ocr-enabled="1"', false)
        ->assertSee('data-field-prefix=""', false)
        ->assertDontSee('cdn.jsdelivr', false);
});

it('keeps only the existing scanner + manual flow when switched off', function () {
    ktpOcrFlag(false);

    $this->actingAs(ktpOcrActor())
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('Cek Scanner')
        ->assertSee('Unggah foto KTP secara manual')
        ->assertSee('data-ocr-enabled="0"', false)
        ->assertDontSee('Foto KTP dengan Kamera')
        ->assertDontSee(route('settings.patients.ktp-scan.parse-ocr'), false);
});

it('ships a systemd timer that actually prunes stale temp KTP scans as the runtime user', function () {
    // Nothing on production invokes `schedule:run`, so an application schedule
    // would be a silent no-op; abandoned identity images are pruned by this unit.
    $service = file_get_contents(base_path('deploy/systemd/daengtisiams-ktp-temp-prune.service'));
    $timer = file_get_contents(base_path('deploy/systemd/daengtisiams-ktp-temp-prune.timer'));

    expect($service)
        ->toContain('User=daengtisiams')
        ->toContain('Group=daengtisiams')
        ->toContain('Type=oneshot')
        // Pinned to the FPM pool's PHP; /usr/bin/php is another tenant's build.
        ->toContain('ExecStart=/usr/bin/php8.3 /var/www/asia-dental-lab-v2/artisan patient-documents:prune-temp --force')
        ->not->toContain('User=root')
        ->not->toContain('Restart=');

    expect($timer)
        ->toContain('Unit=daengtisiams-ktp-temp-prune.service')
        ->toContain('Persistent=true')
        ->toMatch('/^OnCalendar=\*-\*-\* \d{2}:\d{2}:\d{2}$/m');
});

it('keeps a live registration scan when the scheduled prune runs', function () {
    // The retention must outlive the temp-token lifetime, or the prune could
    // remove an image a registration in progress still intends to attach.
    expect((int) config('patient_documents.temp_ttl_hours') * 60)
        ->toBeGreaterThan((int) config('scanner.ktp.temp_token_ttl_minutes'));

    Storage::fake('local');
    $root = config('patient_documents.temp_root');
    Storage::disk('local')->put("$root/9/fresh.jpg", 'x');
    Storage::disk('local')->put("$root/9/fresh.json", json_encode(['created_at' => now()->subMinutes(30)->toIso8601String()]));
    Storage::disk('local')->put("$root/9/stale.jpg", 'x');
    Storage::disk('local')->put("$root/9/stale.json", json_encode(['created_at' => now()->subHours(30)->toIso8601String()]));
    Storage::disk('local')->put('patient-documents/1/final.jpg', 'x');

    $this->artisan('patient-documents:prune-temp', ['--force' => true])->assertSuccessful();

    Storage::disk('local')->assertExists(["$root/9/fresh.jpg", "$root/9/fresh.json", 'patient-documents/1/final.jpg']);
    Storage::disk('local')->assertMissing(["$root/9/stale.jpg", "$root/9/stale.json"]);
});
