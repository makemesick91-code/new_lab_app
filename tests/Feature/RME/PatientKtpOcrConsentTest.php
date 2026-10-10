<?php

/**
 * PHASE-3-PATIENT-KTP-ROI-OCR-CLINICAL-PILOT-1 — consent before KTP OCR
 * (pilot decision D7, approved by the owner 2026-10-10).
 *
 * The approved wording is canonical and versioned; the camera and OCR on any
 * image source wait for an explicit answer from the KTP holder; the parse
 * endpoint refuses OCR text sent without the attestation for the CURRENT
 * wording; declining leaves manual registration unchanged; and no consent
 * record is invented — the KTP holder's written consent stays on the clinic's
 * paper form. All identities are FICTIONAL.
 */

use App\Models\User;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Support\KtpOcrConsent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/** The wording the owner approved, transcribed once here as the test oracle. */
const D7_TITLE = 'PERSETUJUAN PEMINDAIAN KTP';
const D7_PARAGRAPHS = [
    'Saya memberikan persetujuan kepada Klinik Gigi Daengtisia untuk mengambil foto KTP dan memproses informasi identitas saya menggunakan sistem DaengtisiaMS.',
    'Pemrosesan dilakukan untuk membantu pengisian dan verifikasi data pendaftaran pasien.',
    'Saya memahami bahwa hasil pembacaan otomatis akan diperiksa kembali oleh petugas klinik sebelum disimpan.',
    'Foto KTP dan informasi identitas saya akan dikelola sesuai kebijakan privasi dan perlindungan data pribadi yang berlaku di klinik.',
    'Persetujuan ini diberikan secara sukarela setelah saya menerima penjelasan mengenai tujuan penggunaan data.',
];

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00'));
    ktpPilotScope();
    ktpPilotFlag(false);
});

afterEach(fn () => Carbon::setTestNow());

/** A synthetic OCR line (fictional NIK used across the KTP suites). */
function consentOcrPayload(array $extra = []): array
{
    return ['lines' => [['text' => 'NIK : 7371015708900003', 'confidence' => 92]]] + $extra;
}

function consentParse(User $user, array $payload)
{
    return test()->actingAs($user)->postJson(route('settings.patients.ktp-scan.parse-ocr'), $payload);
}

/** Break the wording on purpose — the panel and the endpoint must fail closed. */
function breakConsentWording(array $overrides): void
{
    config(['patient_ktp_ocr_consent' => array_merge(config('patient_ktp_ocr_consent'), $overrides)]);
}

// ------------------------------------------------------------ wording --

it('holds the D7 wording verbatim, versioned, and never from the environment', function () {
    expect(KtpOcrConsent::version())->toBe('D7-2026-10-10')
        ->and(KtpOcrConsent::title())->toBe(D7_TITLE)
        ->and(KtpOcrConsent::paragraphs())->toBe(D7_PARAGRAPHS)
        ->and(KtpOcrConsent::isUsable())->toBeTrue()
        ->and(KtpOcrConsent::acceptableVersions())->toBe(['D7-2026-10-10'])
        ->and(config('patient_ktp_ocr_consent.decision'))->toBe('D7')
        ->and(config('patient_ktp_ocr_consent.approved_on'))->toBe('2026-10-10');

    // A wording an operator could change from the host would make the D7
    // approval meaningless.
    $source = file_get_contents(config_path('patient_ktp_ocr_consent.php'));
    expect($source)->not->toContain('env(');
});

it('fails closed on broken wording instead of showing a partial text', function (array $override) {
    breakConsentWording($override);

    expect(KtpOcrConsent::isUsable())->toBeFalse()
        ->and(KtpOcrConsent::acceptableVersions())->toBe([]);
})->with([
    'no version' => [['version' => '']],
    'no title' => [['title' => '   ']],
    'no paragraphs' => [['paragraphs' => []]],
    'one blank paragraph' => [['paragraphs' => [D7_PARAGRAPHS[0], ' ', D7_PARAGRAPHS[2]]]],
    'not a list' => [['paragraphs' => 'Saya setuju.']],
]);

it('never returns part of the wording when one paragraph is broken', function () {
    breakConsentWording(['paragraphs' => [D7_PARAGRAPHS[0], null]]);

    expect(KtpOcrConsent::paragraphs())->toBe([]);
});

// ------------------------------------------------------ server boundary --

it('parses OCR text only with the attestation for the current wording', function () {
    $operator = ktpPilotOperator();
    $patients = Patient::count();
    $audits = AuditLog::count();
    $visitConsents = RmeVisitConsent::count();

    consentParse($operator, consentOcrPayload(ktpConsent()))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('form.ktp_number.value', '7371015708900003');

    // The attestation creates nothing: no patient, no audit row, and the RME
    // treatment-consent table is not borrowed to fake a KTP consent record.
    expect(Patient::count())->toBe($patients)
        ->and(AuditLog::count())->toBe($audits)
        ->and(RmeVisitConsent::count())->toBe($visitConsents);
});

it('refuses OCR text without the KTP holder consent', function (array $consent, array $errors) {
    consentParse(ktpPilotOperator(), consentOcrPayload($consent))
        ->assertStatus(422)
        ->assertJsonValidationErrors($errors)
        ->assertJsonMissing(['ok' => true]);
})->with([
    'no consent at all' => [[], ['consent', 'consent_version']],
    'consent declined' => [['consent' => false, 'consent_version' => 'D7-2026-10-10'], ['consent']],
    'consent as zero' => [['consent' => 0, 'consent_version' => 'D7-2026-10-10'], ['consent']],
    'version missing' => [['consent' => true], ['consent_version']],
    'unknown version' => [['consent' => true, 'consent_version' => 'D6-2026-01-01'], ['consent_version']],
    'version not a string' => [['consent' => true, 'consent_version' => ['D7-2026-10-10']], ['consent_version']],
]);

it('tells the operator in plain language why OCR was refused', function () {
    consentParse(ktpPilotOperator(), consentOcrPayload())
        ->assertStatus(422)
        ->assertJsonPath('errors.consent.0', 'Pembacaan otomatis memerlukan persetujuan pemilik KTP.');

    consentParse(ktpPilotOperator(), consentOcrPayload(['consent' => true, 'consent_version' => 'D6-old']))
        ->assertStatus(422)
        ->assertJsonPath('errors.consent_version.0', 'Teks persetujuan sudah berubah. Muat ulang halaman lalu minta persetujuan kembali.');
});

it('refuses every request while the wording is unusable, even one naming the approved version', function () {
    $operator = ktpPilotOperator();
    breakConsentWording(['paragraphs' => []]);

    consentParse($operator, consentOcrPayload(['consent' => true, 'consent_version' => 'D7-2026-10-10']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['consent_version']);
});

it('keeps the pilot gate first, so consent is never an oracle for non-pilot operators', function () {
    $outsider = User::factory()->create();
    rmeMakeAdminClinicActive($outsider, ktpPilotBranch());
    ktpPilotOperator(); // pilot armed for somebody else at the same branch

    // Same 404 with and without a valid attestation.
    consentParse($outsider, consentOcrPayload(ktpConsent()))->assertNotFound();
    consentParse($outsider, consentOcrPayload())->assertNotFound();

    ktpPilotFlag(false);
    $operator = ktpPilotOperator();
    ktpPilotFlag(false);
    consentParse($operator, consentOcrPayload(ktpConsent()))->assertNotFound();
});

// ------------------------------------------------------------- screen --

it('shows the approved wording verbatim before the camera, on both registration screens', function (string $routeName) {
    $operator = ktpPilotOperator();

    $response = $this->actingAs($operator)->get(route($routeName))->assertOk();

    $response->assertSee('data-ktp-consent', false)
        ->assertSee('data-ocr-consent-version="D7-2026-10-10"', false)
        ->assertSee(D7_TITLE)
        ->assertSee('Pemilik KTP Setuju')
        ->assertSee('Tidak Setuju — Isi Manual')
        ->assertSee('(Versi teks: D7-2026-10-10)', false)
        ->assertSee('Unggah foto KTP secara manual');
    foreach (D7_PARAGRAPHS as $paragraph) {
        $response->assertSee($paragraph);
    }

    // The panel comes before the camera block, so it is answered first.
    $html = $response->getContent();
    expect(strpos($html, 'data-ktp-consent'))->toBeLessThan(strpos($html, 'data-ktp-camera>'));
})->with(['settings.patients.create', 'rme.visits.create']);

it('shows no consent panel and no wording to an operator outside the pilot', function () {
    $outsider = User::factory()->create();
    rmeMakeAdminClinicActive($outsider, ktpPilotBranch());
    ktpPilotOperator();

    $this->actingAs($outsider)->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('data-ocr-consent-version=""', false)
        ->assertDontSee('data-ktp-consent-accept', false)
        ->assertDontSee(D7_PARAGRAPHS[0])
        ->assertSee('Unggah foto KTP secara manual');
});

it('offers no "agree" button when the wording is unusable', function () {
    $operator = ktpPilotOperator();
    breakConsentWording(['version' => '']);

    $this->actingAs($operator)->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('data-ocr-consent-version=""', false)
        ->assertSee('data-ktp-consent-unavailable', false)
        ->assertDontSee('data-ktp-consent-accept', false)
        ->assertSee('Unggah foto KTP secara manual');
});

// ------------------------------------------------- decline = manual --

it('keeps manual registration complete for a KTP holder who declines OCR', function () {
    $operator = ktpPilotOperator();

    // The plain document upload never asks for consent: it is the registration
    // flow every operator already had.
    $upload = $this->actingAs($operator)->postJson(route('settings.patients.ktp-scan.upload-temp'), [
        'document_type' => 'ktp',
        // The same synthetic 10x10 PNG the sibling KTP suites upload.
        'image_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC',
    ])->assertOk()->json();

    $this->actingAs($operator)->post(route('settings.patients.store'), [
        'clinic_id' => Clinic::factory()->create()->id,
        'doctor_id' => Doctor::factory()->create()->id,
        'medical_record_number' => 'MRN-D7-MANUAL',
        'name' => 'Pasien Fiktif Tanpa OCR',
        'ktp_scan_token' => $upload['token'],
    ])->assertRedirect(route('settings.patients.index'));

    $patient = Patient::firstWhere('name', 'Pasien Fiktif Tanpa OCR');
    expect($patient)->not->toBeNull()
        ->and($patient->documents()->count())->toBe(1);
});

// ---------------------------------------------------- status command --

it('reports the deployed wording version, never the wording itself', function () {
    ktpPilotOperator();

    expect(Artisan::call('patient:ktp-ocr-pilot-status', ['--json' => true, '--strict' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true);

    expect($report['consent'])->toBe(['version' => 'D7-2026-10-10', 'usable' => true, 'paragraphs' => 5])
        ->and(Artisan::output())->not->toContain('Klinik Gigi Daengtisia');
});

it('treats unusable wording as an unusable pilot under --strict while the flag is on', function () {
    ktpPilotOperator();
    breakConsentWording(['paragraphs' => []]);

    expect(Artisan::call('patient:ktp-ocr-pilot-status', ['--json' => true, '--strict' => true]))->toBe(2);
    expect(json_decode(Artisan::output(), true)['consent']['usable'])->toBeFalse();

    // With the flag off nothing can run, so it is not an armed fault.
    ktpPilotFlag(false);
    ktpPilotScope();
    expect(Artisan::call('patient:ktp-ocr-pilot-status', ['--strict' => true]))->toBe(0);
});
