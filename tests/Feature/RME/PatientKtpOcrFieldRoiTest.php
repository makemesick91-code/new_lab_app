<?php

/**
 * REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — server side of the hybrid read.
 *
 * The browser now sends TWO reads of the same KTP: the preserved whole-card
 * read (`lines`) and one read per field region (`fields`). The server parses
 * both with the same validators and reconciles them. These tests pin the
 * reconciliation rules (rule 179) and the endpoint boundary for the new
 * payload. Every identity below is FICTIONAL.
 */

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\KtpOcrParser;
use App\Modules\Patient\Services\KtpOcrSuggestionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00'));
    ktpPilotFlag(true);
});

afterEach(fn () => Carbon::setTestNow());

/** The whole-card read of a fictional KTP. */
function roiDocLines(array $overrides = [], array $drop = []): array
{
    $lines = [
        'header' => ['text' => 'PROVINSI SULAWESI SELATAN', 'confidence' => 94],
        'nik' => ['text' => 'NIK : 7371015708900003', 'confidence' => 92],
        'name' => ['text' => 'Nama : SITI CONTOH RAHMAWATI', 'confidence' => 92],
        'birth' => ['text' => 'Tempat/Tgl Lahir : MAKASSAR, 17-08-1990', 'confidence' => 88],
        'gender' => ['text' => 'Jenis Kelamin : PEREMPUAN Gol. Darah: O', 'confidence' => 88],
        'address' => ['text' => 'Alamat : JL. CONTOH RAYA NO. 12', 'confidence' => 88],
        'rtrw' => ['text' => 'RT/RW : 003/005', 'confidence' => 88],
        'village' => ['text' => 'Kel/Desa : MARISO', 'confidence' => 91],
        'district' => ['text' => 'Kecamatan : MARISO', 'confidence' => 92],
        'religion' => ['text' => 'Agama : ISLAM', 'confidence' => 93],
        'marital' => ['text' => 'Status Perkawinan : KAWIN', 'confidence' => 93],
        'occupation' => ['text' => 'Pekerjaan : KARYAWAN SWASTA', 'confidence' => 94],
    ];
    foreach ($drop as $key) {
        unset($lines[$key]);
    }
    foreach ($overrides as $key => $line) {
        $lines[$key] = $line;
    }

    return array_values($lines);
}

/** The per-field read of the same card: value text only, no labels. */
function roiFields(array $overrides = [], array $drop = []): array
{
    $fields = [
        'nik' => ['text' => '7371015708900003', 'confidence' => 95],
        'name' => ['text' => 'SITI CONTOH RAHMAWATI', 'confidence' => 93],
        'birth_place_date' => ['text' => 'MAKASSAR, 17-08-1990', 'confidence' => 90],
        'gender' => ['text' => 'PEREMPUAN', 'confidence' => 95],
        'address' => ['text' => 'JL. CONTOH RAYA NO. 12', 'confidence' => 90],
        'rt_rw' => ['text' => '003/005', 'confidence' => 93],
        'village' => ['text' => 'MARISO', 'confidence' => 94],
        'district' => ['text' => 'MARISO', 'confidence' => 94],
        'religion' => ['text' => 'ISLAM', 'confidence' => 95],
        'marital_status' => ['text' => 'KAWIN', 'confidence' => 95],
        'occupation' => ['text' => 'KARYAWAN SWASTA', 'confidence' => 95],
    ];
    foreach ($drop as $key) {
        unset($fields[$key]);
    }

    return array_replace($fields, $overrides);
}

function roiSuggest(array $lines, ?array $fields): array
{
    return app(KtpOcrSuggestionService::class)->suggest($lines, $fields, 75.0);
}

// ------------------------------------------------------- compatibility --

it('returns exactly the existing parse result when no field read is sent', function () {
    $legacy = app(KtpOcrParser::class)->parse(roiDocLines(), 75.0);

    $result = roiSuggest(roiDocLines(), null);

    expect($result)->toBe($legacy)
        ->and($result)->not->toHaveKey('method');
});

it('adds an RT/RW part the old reader dropped, and changes nothing else, without a field read', function () {
    $lines = roiDocLines(['rtrw' => ['text' => 'RTIRW : 003/005', 'confidence' => 88]]);

    $result = roiSuggest($lines, null);

    expect($result)->not->toHaveKey('method')
        ->and($result['form']['address']['value'])->toBe('JL. CONTOH RAYA NO. 12, RT/RW 003/005, KEL. MARISO, KEC. MARISO');
});

// ------------------------------------------------------- reconciliation --

it('marks a field both reads agree on as agreed and pre-selects it', function () {
    $r = roiSuggest(roiDocLines(), roiFields());

    expect($r['method'])->toBe('hybrid')
        ->and($r['outcome'])->toBe(KtpOcrParser::OUTCOME_SUCCESS)
        ->and($r['form']['name'])->toMatchArray(['value' => 'SITI CONTOH RAHMAWATI', 'status' => 'ok', 'suggest' => true, 'agreement' => 'agree'])
        ->and($r['fields']['nik']['agreement'])->toBe('agree');
});

it('shows both values and selects neither when the reads disagree', function () {
    // The measured failure: the whole-card read appends the portrait caption
    // column to the value; the field read, bounded left of the portrait, does not.
    $r = roiSuggest(
        roiDocLines(['occupation' => ['text' => 'Pekerjaan : KARYAWAN SWASTA 03-05-2019', 'confidence' => 95]]),
        roiFields(),
    );

    $occupation = $r['form']['occupation'];
    expect($occupation['value'])->toBeNull()
        ->and($occupation['status'])->toBe(KtpOcrParser::STATUS_CONFLICT)
        ->and($occupation['suggest'])->toBeFalse()
        ->and($occupation['agreement'])->toBe('conflict')
        ->and(collect($occupation['alternatives'])->pluck('value', 'source')->all())->toBe([
            'field' => 'KARYAWAN SWASTA',
            'document' => 'KARYAWAN SWASTA 03-05-2019',
        ])
        ->and($r['outcome'])->toBe(KtpOcrParser::OUTCOME_PARTIAL);
});

it('never resolves a disagreement by confidence', function () {
    $r = roiSuggest(
        roiDocLines(['name' => ['text' => 'Nama : SITI CONTOHRAHMAWATI', 'confidence' => 99]]),
        roiFields(['name' => ['text' => 'SITI CONTOH RAHMAWATI', 'confidence' => 40]]),
    );

    expect($r['form']['name']['value'])->toBeNull()
        ->and($r['form']['name']['status'])->toBe(KtpOcrParser::STATUS_CONFLICT)
        ->and($r['form']['name']['suggest'])->toBeFalse();
});

it('takes a value only the field read found, under its own status', function () {
    // The whole-card read mangled the RT/RW label, so it found nothing.
    $r = roiSuggest(
        roiDocLines(['rtrw' => ['text' => 'R7/RVV : 003/005', 'confidence' => 70]]),
        roiFields(),
    );

    expect($r['fields']['rt_rw'])->toMatchArray(['value' => '003/005', 'status' => 'ok', 'agreement' => 'field_only'])
        ->and($r['form']['address']['value'])->toContain('RT/RW 003/005');
});

it('reads an RT/RW label whose slash OCR turned into a letter', function (string $label) {
    $r = app(KtpOcrParser::class)->parse(roiDocLines(['rtrw' => ['text' => $label.' : 003/005', 'confidence' => 90]]), 75.0);

    expect($r['fields']['rt_rw']['value'])->toBe('003/005');
})->with(['RTIRW', 'RT1RW', 'RT|RW', 'RTRW', 'RT/RW', 'RT.RW']);

it('keeps the whole-card value when the field read found nothing', function () {
    $r = roiSuggest(roiDocLines(), roiFields(['district' => ['text' => null, 'confidence' => null]]));

    expect($r['fields']['district'])->toMatchArray(['value' => 'MARISO', 'agreement' => 'document_only']);
});

it('never offers an invalid field read', function () {
    $r = roiSuggest(
        roiDocLines(drop: ['nik']),
        roiFields(['nik' => ['text' => '737101570890000', 'confidence' => 99]]), // 15 digits
    );

    expect($r['form']['ktp_number']['value'])->toBeNull()
        ->and($r['form']['ktp_number']['status'])->toBe(KtpOcrParser::STATUS_INVALID)
        ->and($r['form']['ktp_number']['suggest'])->toBeFalse();
});

it('lets agreement confirm a value one read had to correct', function () {
    // The whole-card read needed glyph correction (O -> 0): low confidence on
    // its own. The independent field read produced the same digits cleanly.
    $r = roiSuggest(
        roiDocLines(['nik' => ['text' => 'NIK : 737101570890OOO3', 'confidence' => 92]]),
        roiFields(),
    );

    expect($r['fields']['nik'])->toMatchArray(['value' => '7371015708900003', 'status' => 'ok', 'agreement' => 'agree']);
});

it('still cross-checks the NIK against the reconciled birth date', function () {
    $r = roiSuggest(
        roiDocLines(['birth' => ['text' => 'Tempat/Tgl Lahir : MAKASSAR, 18-08-1990', 'confidence' => 90]]),
        roiFields(['birth_place_date' => ['text' => 'MAKASSAR, 18-08-1990', 'confidence' => 95]]),
    );

    expect($r['fields']['nik']['status'])->toBe(KtpOcrParser::STATUS_LOW_CONFIDENCE)
        ->and($r['fields']['nik']['reasons'])->toContain('nik_birth_date_mismatch')
        ->and($r['fields']['date_of_birth']['status'])->toBe(KtpOcrParser::STATUS_LOW_CONFIDENCE);
});

it('never fills the birth date from the NIK when no field read has one', function () {
    $r = roiSuggest(
        roiDocLines(drop: ['birth']),
        // The date part of the region was unreadable; only the place survived.
        roiFields(['birth_place_date' => ['text' => 'MAKASSAR,', 'confidence' => 95]]),
    );

    expect($r['form']['date_of_birth']['value'])->toBeNull()
        ->and($r['form']['date_of_birth']['suggest'])->toBeFalse()
        ->and($r['fields']['birth_place']['value'])->toBe('MAKASSAR');
});

it('composes two address alternatives when one component disagrees', function () {
    $r = roiSuggest(
        roiDocLines(['district' => ['text' => 'Kecamatan : TAMALATE ni', 'confidence' => 70]]),
        roiFields(['district' => ['text' => 'TAMALATE', 'confidence' => 95]]),
    );

    $address = $r['form']['address'];
    expect($address['value'])->toBeNull()
        ->and($address['status'])->toBe(KtpOcrParser::STATUS_CONFLICT)
        ->and(collect($address['alternatives'])->pluck('value', 'source')->all())->toBe([
            'field' => 'JL. CONTOH RAYA NO. 12, RT/RW 003/005, KEL. MARISO, KEC. TAMALATE',
            'document' => 'JL. CONTOH RAYA NO. 12, RT/RW 003/005, KEL. MARISO, KEC. TAMALATE NI',
        ]);
});

it('never pre-selects a value the other read could not settle', function () {
    // The whole-card read found the NIK label twice with different numbers.
    $r = roiSuggest(
        roiDocLines(['nik2' => ['text' => 'NIK : 7371015708900004', 'confidence' => 92]]),
        roiFields(['nik' => ['text' => '7371015708900005', 'confidence' => 98]]),
    );

    expect($r['form']['ktp_number']['value'])->toBe('7371015708900005')
        ->and($r['form']['ktp_number']['status'])->toBe(KtpOcrParser::STATUS_LOW_CONFIDENCE)
        ->and($r['form']['ktp_number']['suggest'])->toBeFalse()
        ->and($r['fields']['nik']['reasons'])->toContain('other_read_ambiguous');
});

it('never pre-selects a gender the whole-card read saw both ways', function () {
    $r = roiSuggest(
        roiDocLines(['gender' => ['text' => 'Jenis Kelamin : PEREMPUAN LAKI-LAKI', 'confidence' => 90]]),
        roiFields(),
    );

    expect($r['form']['gender']['value'])->toBe('Female')
        ->and($r['form']['gender']['suggest'])->toBeFalse();
});

it('cross-checks every NIK candidate when the two NIK reads disagree', function () {
    // Both NIK readings encode 17-08-1990 and female; the card says otherwise.
    $r = roiSuggest(
        roiDocLines([
            'nik' => ['text' => 'NIK : 7371015708900008', 'confidence' => 92],
            'birth' => ['text' => 'Tempat/Tgl Lahir : MAKASSAR, 06-01-1991', 'confidence' => 92],
            'gender' => ['text' => 'Jenis Kelamin : LAKI-LAKI', 'confidence' => 92],
        ]),
        roiFields([
            'birth_place_date' => ['text' => 'MAKASSAR, 06-01-1991', 'confidence' => 95],
            'gender' => ['text' => 'LAKI-LAKI', 'confidence' => 95],
        ]),
    );

    expect($r['form']['ktp_number']['status'])->toBe(KtpOcrParser::STATUS_CONFLICT)
        ->and($r['form']['date_of_birth']['suggest'])->toBeFalse()
        ->and($r['fields']['date_of_birth']['reasons'])->toContain('nik_birth_date_mismatch')
        ->and($r['form']['gender']['suggest'])->toBeFalse()
        ->and($r['fields']['gender']['reasons'])->toContain('nik_gender_mismatch');
});

it('keeps a birth date and gender that match one of two disagreeing NIK reads', function () {
    $r = roiSuggest(
        roiDocLines(['nik' => ['text' => 'NIK : 7371015708900008', 'confidence' => 92]]),
        roiFields(),
    );

    expect($r['form']['ktp_number']['status'])->toBe(KtpOcrParser::STATUS_CONFLICT)
        ->and($r['form']['date_of_birth']['suggest'])->toBeTrue()
        ->and($r['form']['gender']['suggest'])->toBeTrue();
});

it('demotes the NIK when it matches neither of two disagreeing birth dates', function () {
    $r = roiSuggest(
        roiDocLines(['birth' => ['text' => 'Tempat/Tgl Lahir : MAKASSAR, 07-01-1991', 'confidence' => 92]]),
        roiFields(['birth_place_date' => ['text' => 'MAKASSAR, 06-01-1991', 'confidence' => 95]]),
    );

    expect($r['form']['date_of_birth']['status'])->toBe(KtpOcrParser::STATUS_CONFLICT)
        ->and($r['form']['ktp_number']['suggest'])->toBeFalse()
        ->and($r['fields']['nik']['reasons'])->toContain('nik_birth_date_mismatch');
});

// --------------------------------------------------- untrusted field text --

it('cannot use a field read to inject another field', function () {
    $r = roiSuggest(
        roiDocLines(drop: ['nik', 'name']),
        roiFields(['nik' => ['text' => 'NAMA : PALSU ORANG', 'confidence' => 99]], drop: ['name']),
    );

    expect($r['fields']['name']['value'])->toBeNull()
        ->and($r['form']['ktp_number']['value'])->toBeNull()
        ->and($r['form']['ktp_number']['status'])->toBe(KtpOcrParser::STATUS_INVALID);
});

it('files a box read only under its own field, even when its text looks like another label', function () {
    // "KELAMIN DESA" matches the village pattern, but the parser's first match
    // is gender: passed through unlabelled it would have set the gender.
    $r = roiSuggest(
        roiDocLines(drop: ['gender']),
        roiFields(['village' => ['text' => 'KELAMIN DESA : LAKI-LAKI', 'confidence' => 99]], drop: ['gender']),
    );

    expect($r['form']['gender']['value'])->toBeNull()
        ->and($r['form']['gender']['suggest'])->toBeFalse()
        ->and($r['fields']['gender']['status'])->toBe(KtpOcrParser::STATUS_MISSING);
});

it('does not double the label when a moved box captured it', function () {
    $r = roiSuggest(
        roiDocLines(drop: ['name']),
        roiFields(['name' => ['text' => 'Nama : SITI CONTOH RAHMAWATI', 'confidence' => 93]]),
    );

    expect($r['form']['name']['value'])->toBe('SITI CONTOH RAHMAWATI');
});

// ------------------------------------------------------------- endpoint --

it('accepts the hybrid payload, stores nothing and caches nothing', function () {
    $patients = Patient::count();
    $audits = AuditLog::count();

    $response = $this->actingAs(ktpPilotOperator())
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), [
            'lines' => roiDocLines(['occupation' => ['text' => 'Pekerjaan : KARYAWAN SWASTA 03-05-2019', 'confidence' => 95]]),
            'fields' => roiFields(),
        ])
        ->assertOk()
        ->assertJsonPath('method', 'hybrid')
        ->assertJsonPath('form.ktp_number.agreement', 'agree')
        ->assertJsonPath('form.occupation.status', 'conflict');

    // Still no patient lookup in the answer: only the parse result + method.
    expect(array_keys($response->json()))->toBe(['ok', 'outcome', 'fields', 'form', 'recognized_form_fields', 'threshold', 'method'])
        ->and($response->getContent())->not->toContain('duplicate')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Patient::count())->toBe($patients)
        ->and(AuditLog::count())->toBe($audits)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('bounds the untrusted field payload', function (array $fields) {
    $this->actingAs(ktpPilotOperator())
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), ['lines' => roiDocLines(), 'fields' => $fields])
        ->assertStatus(422);
})->with([
    'unknown field' => [['patient_id' => ['text' => '1', 'confidence' => 90]]],
    'text too long' => [['name' => ['text' => str_repeat('A', 201), 'confidence' => 90]]],
    'bad confidence' => [['name' => ['text' => 'A', 'confidence' => 101]]],
    'extra key' => [['name' => ['text' => 'A', 'confidence' => 90, 'box' => [0, 0, 1, 1]]]],
    'text not a string' => [['name' => ['text' => ['A'], 'confidence' => 90]]],
    'not an object' => [['name' => 'SITI']],
]);

it('keeps the field read behind the pilot gate', function () {
    $operator = ktpPilotOperator();
    ktpPilotFlag(false);

    $this->actingAs($operator)
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), ['lines' => roiDocLines(), 'fields' => roiFields()])
        ->assertNotFound();
});

it('refuses the field read to an operator outside the pilot', function () {
    ktpPilotOperator();
    $outsider = User::factory()->create();
    rmeMakeAdminClinicActive($outsider, ktpPilotBranch());

    $this->actingAs($outsider)
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), ['lines' => roiDocLines(), 'fields' => roiFields()])
        ->assertNotFound();
});

it('refuses the field read to a cohort operator working at another branch', function () {
    $pilotBranch = ktpPilotBranch('KTPP');
    $operator = User::factory()->create();
    rmeMakeAdminClinicActive($operator, ktpPilotBranch('KTPX'));
    ktpPilotScope(['operator_user_ids' => (string) $operator->id, 'branch_codes' => $pilotBranch->code]);

    $this->actingAs($operator)
        ->postJson(route('settings.patients.ktp-scan.parse-ocr'), [
            'branch_id' => $pilotBranch->id, // never trusted
            'lines' => roiDocLines(),
            'fields' => roiFields(),
        ])
        ->assertNotFound();
});
