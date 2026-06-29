<?php

/**
 * Sprint 61.1.1 — Show KTP Scanner Section in RME Visit New Patient Registration.
 *
 * The real FO workflow registers new patients from the RME visit creation page
 * ("Daftar Kunjungan Baru" → "Pasien Baru"), not from settings/patients/create.
 * These tests verify the Sprint 61.1 scanner section is visible there and that a
 * captured KTP scan is attached to the patient created in the visit flow — while
 * the existing-patient flow never attaches and missing tokens never fail
 * registration. Reuses the Sprint 61.1 backend/routes/services unchanged.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientDocument;
use App\Modules\Treatment\Models\Treatment;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');
    Carbon::setTestNow(Carbon::parse('2026-06-26 09:00:00'));

    $this->clinic = Clinic::factory()->create();
    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->treatment = Treatment::factory()->create(['is_active' => true]);
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->branch->update(['is_rme_enabled' => true]);
    rmeMakeDoctorOnline($this->doctor, $this->branch);

    // Visit creation + new-patient creation require both rights (see controller).
    $this->actor = userWith(['manage_clinic_visits', 'view_clinic_visits', 'manage patients']);
});

afterEach(fn () => Carbon::setTestNow());

/** A small valid 10x10 PNG (raw base64, no data URI prefix). */
function rmeKtpPngBase64(): string
{
    return 'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC';
}

/** Upload a temp KTP scan as the given actor and return the JSON payload. */
function rmeUploadTempKtp($actor): array
{
    return test()->actingAs($actor)
        ->postJson(route('settings.patients.ktp-scan.upload-temp'), [
            'document_type' => 'ktp',
            'image_base64' => rmeKtpPngBase64(),
        ])
        ->json();
}

// --- 1. Scanner-ready KTP section visible on the RME visit create page --------

it('shows the KTP scan section on the RME visit create page', function () {
    $this->actingAs($this->actor)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('Scan KTP')
        ->assertSee('Cek Scanner')
        ->assertSee('Hapus Preview')
        ->assertSee('ktp_scan_token', false);
});

// --- 2. Scanner-unavailable fallback message rendered ------------------------

it('renders the scanner-unavailable fallback message on RME visit create', function () {
    $this->actingAs($this->actor)
        ->get(route('rme.visits.create'))
        ->assertSee('Jalankan Daengtisia Scanner Agent di komputer ini.');
});

// --- 3. New patient + token attaches the scanned KTP -------------------------

it('attaches the scanned KTP to a new patient created in the visit flow', function () {
    $token = rmeUploadTempKtp($this->actor)['token'];

    $this->actingAs($this->actor)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'new_patient' => [
                'name' => 'Pasien Baru Scan KTP',
                'branch_id' => $this->branch->id,
                'registered_at' => '2026-06-26',
                'manual_rm_number' => '0123',
            ],
            'ktp_scan_token' => $token,
        ])
        ->assertRedirect();

    $patient = Patient::firstWhere('name', 'Pasien Baru Scan KTP');
    expect($patient)->not->toBeNull();

    $document = $patient->documents()->first();
    expect($document)->not->toBeNull();
    expect($document->document_type)->toBe(PatientDocument::TYPE_KTP);
    expect($document->uploaded_by)->toBe($this->actor->id);
    expect($document->file_path)->toStartWith('patient-documents/'.$patient->id.'/');
    expect(Storage::disk('local')->exists($document->file_path))->toBeTrue();

    // The temp token/file is invalidated after promotion.
    expect(Storage::disk('local')->allFiles('tmp/patient-ktp-scans'))->toBeEmpty();
});

// --- 4. New patient without a token still works -------------------------------

it('creates a new patient with no KTP scan token', function () {
    $this->actingAs($this->actor)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'new_patient' => [
                'name' => 'Pasien Baru Tanpa Scan',
                'branch_id' => $this->branch->id,
                'registered_at' => '2026-06-26',
                'manual_rm_number' => '0124',
            ],
        ])
        ->assertRedirect();

    $patient = Patient::firstWhere('name', 'Pasien Baru Tanpa Scan');
    expect($patient)->not->toBeNull();
    expect($patient->documents()->count())->toBe(0);
});

// --- 5. Existing-patient flow never attaches a KTP scan -----------------------

it('does not attach a KTP scan in existing-patient mode even with a token', function () {
    $patient = Patient::factory()->create(['medical_record_number' => 'DG-MAIN-2026-0009']);
    $branch = Branch::factory()->create(['code' => 'RME1', 'is_rme_enabled' => true]);
    rmeMakeDoctorOnline($this->doctor, $branch);
    $token = rmeUploadTempKtp($this->actor)['token'];

    $this->actingAs($this->actor)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'existing',
            'branch_id' => $branch->id,
            'clinic_id' => $this->clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'ktp_scan_token' => $token,
        ])
        ->assertRedirect();

    expect(ClinicVisit::where('patient_id', $patient->id)->exists())->toBeTrue();
    expect($patient->documents()->count())->toBe(0);
    // The unused temp token is left untouched (not promoted) for existing mode.
    expect(Storage::disk('local')->allFiles('tmp/patient-ktp-scans'))->not->toBeEmpty();
});

// --- 6. Access control on the temp upload route is unchanged ------------------

it('forbids an unauthorized user from uploading a KTP scan from the visit flow', function () {
    $this->actingAs(userWith(['view_clinic_visits']))
        ->postJson(route('settings.patients.ktp-scan.upload-temp'), [
            'document_type' => 'ktp',
            'image_base64' => rmeKtpPngBase64(),
        ])
        ->assertForbidden();
});
