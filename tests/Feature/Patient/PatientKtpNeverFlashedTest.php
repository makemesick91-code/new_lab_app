<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-SPN4-READY-PILOT-1 G6 / REM-2a — a KTP/NIK never reaches the session
|--------------------------------------------------------------------------
|
| On a validation failure the framework flashes the whole request input into the
| session (only passwords are excluded by default), and the session driver is the
| database. A refused registration therefore left the full NIK in the sessions
| table (CG-11). bootstrap/app.php now lists the inputs the audit traced that
| hold an identity number or can hold one by mistake:
|
|   ktp_number                 Master Data → Tambah / Ubah Pasien
|   new_patient.ktp_number     Kunjungan → Pasien Baru
|   image_base64               the KTP scan upload (a picture of the card)
|   owner_approval_reference   refused exactly when it looks like a NIK
|   patient_request_reference  refused exactly when it looks like a NIK
|   consenter_identity_number  a guardian's KTP/SIM on the consent form (R2-6,
|                              found after the first pass; its real-route test is
|                              RmeVisitConsentGuardianIdentityNeverFlashedTest)
|
| The refund-request controller re-flashes input explicitly (withInput()), which
| bypasses dontFlash, so it excludes both references itself. Every other field is
| still flashed: the form keeps what was typed, an uploaded scan stays attached.
|
| The NIK below is synthetic (region code 99 does not exist). Selected by the
| `PatientKtp` critical-gate token in both runner variants and declared in
| critical_gate_mandatory_suites (CG-19). Helper prefix `pkfl` is unique because
| the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

const PKFL_NIK = '9971010101900001';
const PKFL_OTHER_NIK = '9971010101900002';
const PKFL_NEVER_FLASHED = [
    'ktp_number',
    'new_patient.ktp_number',
    'image_base64',
    'owner_approval_reference',
    'patient_request_reference',
    'consenter_identity_number',
];

beforeEach(function (): void {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    pinTestClock('2026-06-13 01:00:00'); // 09:00 WITA

    Branch::query()->where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    $this->clinic = Clinic::factory()->create();
    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->branch = Branch::factory()->create(['code' => 'PKF1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->treatment = Treatment::factory()->create(['is_active' => true]);
});

afterEach(fn () => freeTestClock());

function pkflFrontOffice(): User
{
    $user = User::factory()->create();
    rmeMakeFrontOfficeActive($user, test()->branch);

    return $user->fresh();
}

function pkflMasterPayload(array $overrides = []): array
{
    return array_merge([
        'clinic_id' => test()->clinic->id,
        'doctor_id' => test()->doctor->id,
        'branch_id' => test()->branch->id,
        'name' => 'Pasien Sintetis '.Str::random(6),
        'gender' => 'Female',
        'place_of_birth' => 'Makassar',
        'date_of_birth' => '1990-05-17',
        'phone' => '081234500001',
        'address' => 'Jl. Uji Coba No. 1',
        'occupation' => 'Guru',
        'ktp_number' => PKFL_NIK,
        'is_active' => 1,
    ], $overrides);
}

function pkflVisitPayload(array $patientOverrides = []): array
{
    return [
        'patient_mode' => 'new',
        'branch_id' => test()->branch->id,
        'clinic_id' => test()->clinic->id,
        'doctor_id' => test()->doctor->id,
        'initial_treatment_id' => test()->treatment->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'new_patient' => array_merge([
            'name' => 'Pasien Kunjungan Sintetis',
            'branch_id' => test()->branch->id,
            'gender' => 'Male',
            'place_of_birth' => 'Gowa',
            'date_of_birth' => '1985-02-03',
            'phone' => '081234500002',
            'address' => 'Jl. Kunjungan No. 2',
            'occupation' => 'Wiraswasta',
            'ktp_number' => PKFL_NIK,
        ], $patientOverrides),
    ];
}

/** Nothing of the NIK survives anywhere in the session payload. */
function pkflSessionHoldsNik(string $nik = PKFL_NIK): bool
{
    return str_contains((string) json_encode(session()->all()), $nik);
}

it('KF-01 registers every NIK-bearing input with the exception handler and keeps the earlier ones', function (): void {
    $dontFlash = (fn () => $this->dontFlash)->call(app(ExceptionHandler::class));

    expect($dontFlash)->toContain(...PKFL_NEVER_FLASHED)
        ->and($dontFlash)->toContain('consenter_signature', 'handwriting_data', 'no_phone_reason_note', 'new_patient.no_phone_reason_note')
        ->and($dontFlash)->toContain('password', 'password_confirmation', 'current_password')
        // Generic names other forms refill with old() must stay flashable.
        ->and($dontFlash)->not->toContain('lines', 'fields', 'ktp_scan_token', 'ktp_ocr_applied', 'ktp_ocr_verified');
});

it('KF-02 never flashes the NIK when Master Data → Tambah Pasien is refused, and keeps the other fields', function (): void {
    $this->actingAs(pkflFrontOffice())
        ->post(route('settings.patients.store'), pkflMasterPayload(['name' => 'Pasien Sesi', 'occupation' => '']))
        ->assertStatus(302)
        ->assertSessionHasErrors('occupation');

    expect(session()->getOldInput())->not->toHaveKey('ktp_number')
        ->and(session()->getOldInput('name'))->toBe('Pasien Sesi')
        ->and(session()->getOldInput('address'))->toBe('Jl. Uji Coba No. 1')
        ->and(pkflSessionHoldsNik())->toBeFalse()
        ->and(Patient::query()->count())->toBe(0);
});

it('KF-03 never flashes the NIK when Kunjungan → Pasien Baru is refused, and keeps the other fields', function (): void {
    rmeMakeDoctorOnline($this->doctor, $this->branch);

    $this->actingAs(pkflFrontOffice())
        ->post(route('rme.visits.store'), pkflVisitPayload(['occupation' => '']))
        ->assertStatus(302)
        ->assertSessionHasErrors('new_patient.occupation');

    expect(session()->getOldInput('new_patient'))->not->toHaveKey('ktp_number')
        ->and(session()->getOldInput('new_patient.name'))->toBe('Pasien Kunjungan Sintetis')
        ->and(session()->getOldInput('patient_mode'))->toBe('new')
        ->and(pkflSessionHoldsNik())->toBeFalse();
});

it('KF-04 never flashes the NIK when an edit is refused, and shows the stored value again, not the typed one', function (): void {
    $frontOffice = pkflFrontOffice();
    $this->actingAs($frontOffice)
        ->post(route('settings.patients.store'), pkflMasterPayload(['name' => 'Pasien Ubah']))
        ->assertSessionHasNoErrors();
    $patient = Patient::query()->where('name', 'Pasien Ubah')->sole();

    $this->actingAs($frontOffice)
        ->from(route('settings.patients.edit', $patient))
        ->put(route('settings.patients.update', $patient), [
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'name' => '',
            'ktp_number' => PKFL_OTHER_NIK,
            'is_active' => 1,
        ])
        ->assertStatus(302)
        ->assertSessionHasErrors('name');

    expect(session()->getOldInput())->not->toHaveKey('ktp_number')
        ->and(pkflSessionHoldsNik(PKFL_OTHER_NIK))->toBeFalse()
        ->and($patient->fresh()->ktp_number)->toBe(PKFL_NIK);
});

it('KF-05 never flashes the NIK when the registration is refused because another patient holds it', function (): void {
    $frontOffice = pkflFrontOffice();
    $this->actingAs($frontOffice)
        ->post(route('settings.patients.store'), pkflMasterPayload(['name' => 'Pasien Pertama']))
        ->assertSessionHasNoErrors();

    $this->actingAs($frontOffice)
        ->post(route('settings.patients.store'), pkflMasterPayload(['name' => 'Pasien Kedua']))
        ->assertStatus(302)
        ->assertSessionHasErrors('ktp_number');

    expect(session()->getOldInput())->not->toHaveKey('ktp_number')
        ->and(session()->getOldInput('name'))->toBe('Pasien Kedua')
        ->and(pkflSessionHoldsNik())->toBeFalse()
        ->and(Patient::query()->where('ktp_number', PKFL_NIK)->count())->toBe(1);
});

it('KF-06 keeps the KTP scan image and both NIK-refusing references out of a refused form, and keeps everything else', function (): void {
    Route::middleware('web')->post('/_pkfl/refuse', function () {
        throw ValidationException::withMessages(['owner_approval_reference' => 'refused for the test']);
    });
    $picture = base64_encode(str_repeat("\xFF\xD8KTP-".PKFL_NIK, 32));

    $this->from('/_pkfl/form')
        ->post('/_pkfl/refuse', [
            'image_base64' => $picture,
            'owner_approval_reference' => PKFL_NIK,
            'patient_request_reference' => PKFL_NIK,
            'ktp_scan_token' => '5d6c1b8e-3f0a-4a52-9a43-7b1f2a9e0c11',
            'ktp_ocr_applied' => '1',
            'reason' => 'kept for the form',
            'lines' => [['qty' => 1]],
        ])
        ->assertRedirect('/_pkfl/form')
        ->assertSessionHasErrors('owner_approval_reference');

    $old = session()->getOldInput();

    expect($old)->not->toHaveKey('image_base64')
        ->and($old)->not->toHaveKey('owner_approval_reference')
        ->and($old)->not->toHaveKey('patient_request_reference')
        ->and($old)->toHaveKey('ktp_scan_token', '5d6c1b8e-3f0a-4a52-9a43-7b1f2a9e0c11')
        ->and($old)->toHaveKey('ktp_ocr_applied', '1')
        ->and($old)->toHaveKey('reason', 'kept for the form')
        ->and($old)->toHaveKey('lines')
        ->and(pkflSessionHoldsNik())->toBeFalse()
        ->and(str_contains((string) json_encode(session()->all()), $picture))->toBeFalse();
});

it('KF-07 still flashes every field of a form that has no NIK-bearing input (no over-reach)', function (): void {
    Route::middleware('web')->post('/_pkfl/plain', function () {
        throw ValidationException::withMessages(['quantity' => 'refused for the test']);
    });

    $this->from('/_pkfl/form')
        ->post('/_pkfl/plain', ['quantity' => '0', 'notes' => 'Catatan stok', 'fields' => ['name' => ['source' => 'a']]])
        ->assertSessionHasErrors('quantity');

    expect(session()->getOldInput())->toBe(['quantity' => '0', 'notes' => 'Catatan stok', 'fields' => ['name' => ['source' => 'a']]]);
});
