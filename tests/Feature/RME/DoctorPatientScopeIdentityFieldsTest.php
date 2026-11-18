<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.1 — PR C1 (rule 243 §3)
|--------------------------------------------------------------------------
| Doctor-visible patient fields. Owner decision 2026-11-17 ("memo list + RM
| number", plan default 5 "gender only"): a Doctor sees the patient's name, RM
| number, place and date of birth, age, occupation, planned treatment and
| gender; the phone, WhatsApp number, address, email and NIK are hidden from
| every doctor screen. The doctor's consent VIEW masks the NIK and hides both
| addresses; the printed consent document is unchanged.
|
| One decision: App\Modules\Patient\Services\PatientIdentityPresenter (its
| audience is rule 138's Doctor, DoctorPatientScopeService::
| shouldApplyDoctorScope(): a Doctor unless also Owner, Super Admin or
| Supervisor RME). Not behind `rme.doctor_encounter_scope`: live on merge.
| A Doctor who also holds Front Office or Admin Klinik gets the doctor view on
| the clinical screens and keeps the full patient master form (`manage
| patients`), which the presenter never touches.
|
| Selected by the existing `DoctorPatientScope` critical-gate token in both
| runner variants; declared in critical_gate_mandatory_suites. Helper prefix
| `dpif` is unique (the critical gate loads every suite into one process).
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientIdentityPresenter;
use App\Support\Storage\ClinicalEvidenceStorage;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

const DPIF_FLAG = 'rme.doctor_encounter_scope';

/** Values a Doctor must never see, keyed by what they are. */
const DPIF_HIDDEN = [
    'phone' => '081200007311',
    'whatsapp' => '081299007322',
    'email' => 'dpif.pasien@example.test',
    'address' => 'Jl. Alamat Rahasia Dpif No. 7',
    'nik' => '7371015502900042',
];

const DPIF_GUARDIAN_ADDRESS = 'Jl. Wali Rahasia Dpif No. 9';

const DPIF_GUARDIAN_NIK = '7371014403650099';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-18 02:00:00', 'UTC')); // 10:00 WITA

    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());
    Storage::fake('public');
    Storage::fake('local');

    test()->dpifBranch = Branch::factory()->create(['code' => 'IF01', 'is_active' => true, 'is_rme_enabled' => true]);
    test()->dpifRoom = ClinicRoom::factory()->create(['branch_id' => test()->dpifBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $clinic = Clinic::factory()->create();

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'branch_id' => test()->dpifBranch->id, 'name' => 'drg. Dokter Identitas Dpif']);
    $user = User::factory()->create()->assignRole('Doctor');
    $doctor->update(['user_id' => $user->id]);
    $doctor->branches()->sync([(int) test()->dpifBranch->id]);
    rmeMakeDoctorOnline($doctor, test()->dpifBranch, test()->dpifRoom, $user);
    test()->dpifDoctor = $doctor->refresh();
    test()->dpifDoctorUser = $user->refresh();

    test()->dpifFrontOffice = userInRole('Front Office');
    rmeMakeFrontOfficeActive(test()->dpifFrontOffice, test()->dpifBranch);

    test()->dpifPatient = Patient::factory()->create([
        'branch_id' => test()->dpifBranch->id,
        'name' => 'Pasien Identitas Dpif',
        'medical_record_number' => 'DG-IF01-2026-00042',
        'place_of_birth' => 'Parepare',
        'date_of_birth' => '1990-02-01',
        'occupation' => 'Guru Honorer Dpif',
        'gender' => 'Female',
        'phone' => DPIF_HIDDEN['phone'],
        'whatsapp_number' => DPIF_HIDDEN['whatsapp'],
        'email' => DPIF_HIDDEN['email'],
        'address' => DPIF_HIDDEN['address'],
        'ktp_number' => DPIF_HIDDEN['nik'],
    ]);

    test()->dpifVisit = ClinicVisit::factory()->create([
        'branch_id' => test()->dpifBranch->id,
        'patient_id' => test()->dpifPatient->id,
        'doctor_id' => test()->dpifDoctor->id,
        'clinic_room_id' => test()->dpifRoom->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
        'visit_date' => '2026-11-18',
    ])->refresh();
});

afterEach(function () {
    Carbon::setTestNow();
});

function dpifFlag(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DPIF_FLAG]['default'] = $on;
    $flags[DPIF_FLAG]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
}

function dpifSheet(): MedicalRecord
{
    $visit = test()->dpifVisit;

    return MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'canonical_visit_id' => $visit->id,
    ])->refresh();
}

/** The patient's PTM, signed by a guardian through the real service (front office). */
function dpifConsent(): RmeVisitConsent
{
    return app(RmeVisitConsentService::class)->sign(test()->dpifVisit, test()->dpifFrontOffice, [
        'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
        'consenter_relationship' => 'ibu',
        'consenter_name' => 'Ibu Wali Dpif',
        'consenter_age' => '61',
        'consenter_gender' => 'Female',
        'consenter_address' => DPIF_GUARDIAN_ADDRESS,
        'consenter_identity_number' => DPIF_GUARDIAN_NIK,
        'medical_action' => 'Penambalan gigi 46',
        'treatment_summary' => 'Penambalan',
        'documentation_consent' => '0',
        'consenter_signature' => validPodSignatureData(),
    ]);
}

/** The "Informasi Kunjungan" biodata table of the RM workspace. */
function dpifWorkspaceBiodata(string $html): string
{
    $card = strpos($html, 'Informasi Kunjungan');
    expect($card)->not->toBeFalse();
    $end = strpos($html, '</table>', (int) $card);
    expect($end)->not->toBeFalse();

    return substr($html, (int) $card, (int) $end - (int) $card);
}

/** Every hidden value that appears in $html, keyed by what it is. */
function dpifLeaks(string $html, array $hidden = DPIF_HIDDEN): array
{
    return array_keys(array_filter($hidden, fn (string $value): bool => str_contains($html, e($value)) || str_contains($html, $value)));
}

/*
|--------------------------------------------------------------------------
| 1 — a Doctor sees only the allowed fields, on every screen
|--------------------------------------------------------------------------
*/

it('DPIF-01 shows a Doctor the name and RM number on the visit page but never the phone or WhatsApp number, with the encounter-scope flag OFF or ON', function (bool $on) {
    dpifFlag($on);

    $html = $this->actingAs(test()->dpifDoctorUser)
        ->get(route('rme.visits.show', test()->dpifVisit))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Pasien Identitas Dpif')
        ->and($html)->toContain('DG-IF01-2026-00042')
        ->and($html)->not->toContain('>Ponsel<')
        ->and($html)->not->toContain('>Nomor WA<')
        ->and(dpifLeaks($html))->toBe([]);
})->with(['flag OFF' => [false], 'flag ON' => [true]]);

it('DPIF-02 shows a Doctor the RM workspace biodata with the allowed fields only (place and date of birth, age, occupation, gender, RM number)', function () {
    dpifSheet();

    $html = $this->actingAs(test()->dpifDoctorUser)
        ->get(route('rme.visits.medical-record.show', test()->dpifVisit))
        ->assertOk()
        ->getContent();
    $biodata = dpifWorkspaceBiodata($html);

    expect($biodata)->toContain('Pasien Identitas Dpif')
        ->and($biodata)->toContain('Parepare, 01-02-1990 / 36 tahun')
        ->and($biodata)->toContain('Guru Honorer Dpif')
        ->and($biodata)->toContain('Perempuan')
        ->and($biodata)->toContain('DG-IF01-2026-00042')
        ->and($biodata)->not->toContain('Alamat')
        ->and($biodata)->not->toContain('No. Tlp / Wa')
        ->and($biodata)->not->toContain('Email')
        ->and($biodata)->not->toContain('Status Pernikahan')
        ->and($biodata)->not->toContain('Agama')
        ->and(dpifLeaks($biodata))->toBe([]);
});

it('DPIF-03 shows a Doctor no contact detail and no NIK on the empty workspace, odontogram, prescription, patient-centric and unified RM pages', function () {
    // Before the first sheet the workspace is the empty page ("Informasi Pasien").
    $empty = $this->actingAs(test()->dpifDoctorUser)
        ->get(route('rme.visits.medical-record.show', test()->dpifVisit))
        ->assertOk()
        ->assertViewIs('rme.visits.medical-record.empty')
        ->getContent();

    expect($empty)->toContain('Pasien Identitas Dpif')
        ->and(dpifLeaks($empty))->toBe([]);

    dpifSheet();
    rmeSignedConsentFor(test()->dpifVisit);

    $pages = [
        'odontogram' => route('rme.visits.odontogram.show', test()->dpifVisit),
        'prescription' => route('rme.visits.prescription.show', test()->dpifVisit),
        'patient page' => route('rme.medical-records.patients.show', test()->dpifPatient->id),
        'unified index' => route('rme.medical-records.index'),
    ];

    foreach ($pages as $name => $url) {
        $html = $this->actingAs(test()->dpifDoctorUser)->get($url)->assertOk()->getContent();

        expect($html)->toContain('Pasien Identitas Dpif')
            ->and(dpifLeaks($html))->toBe([], $name);
    }
});

it('DPIF-04 masks the NIK and hides both addresses on the consent page and the countersignature page for the treating Doctor', function () {
    $consent = dpifConsent();

    foreach (['show' => route('rme.consents.show', $consent), 'countersign' => route('rme.consents.countersign.create', $consent)] as $name => $url) {
        $html = $this->actingAs(test()->dpifDoctorUser)->get($url)->assertOk()->getContent();

        expect($html)->toContain('<div class="consent-document">')
            ->and($html)->toContain('Pasien Identitas Dpif')
            ->and($html)->toContain('****0042')
            ->and($html)->toContain('****0099')
            ->and($html)->not->toContain(DPIF_GUARDIAN_NIK)
            ->and($html)->not->toContain(e(DPIF_GUARDIAN_ADDRESS))
            ->and($html)->not->toContain('<td class="consent-label">Alamat</td>')
            ->and(dpifLeaks($html))->toBe([], $name);
    }
});

/*
|--------------------------------------------------------------------------
| 2 — every other role is unchanged
|--------------------------------------------------------------------------
*/

it('DPIF-05 leaves Front Office exactly as before: phone and WhatsApp on the visit page, the full biodata in the workspace', function () {
    dpifSheet();

    $visit = $this->actingAs(test()->dpifFrontOffice)->get(route('rme.visits.show', test()->dpifVisit))->assertOk()->getContent();
    $workspace = dpifWorkspaceBiodata(
        $this->actingAs(test()->dpifFrontOffice)->get(route('rme.visits.medical-record.show', test()->dpifVisit))->assertOk()->getContent(),
    );

    expect($visit)->toContain('>Ponsel<')
        ->and($visit)->toContain(DPIF_HIDDEN['phone'])
        ->and($visit)->toContain('>Nomor WA<')
        ->and($visit)->toContain(DPIF_HIDDEN['whatsapp'])
        ->and($workspace)->toContain('Alamat')
        ->and($workspace)->toContain(e(DPIF_HIDDEN['address']))
        ->and($workspace)->toContain('No. Tlp / Wa')
        ->and($workspace)->toContain(DPIF_HIDDEN['whatsapp'])
        ->and($workspace)->toContain('Email')
        ->and($workspace)->toContain(DPIF_HIDDEN['email'])
        ->and($workspace)->toContain('Status Pernikahan')
        ->and($workspace)->toContain('Agama')
        ->and($workspace)->toContain('Parepare, 01-02-1990 / 36 tahun');
});

it('DPIF-06 leaves the consent page and the printed consent document whole for Front Office: full NIK and both addresses', function () {
    $consent = dpifConsent();

    foreach (['show' => route('rme.consents.show', $consent), 'print' => route('rme.consents.print', $consent)] as $name => $url) {
        $html = $this->actingAs(test()->dpifFrontOffice)->get($url)->assertOk()->getContent();

        expect($html)->toContain(DPIF_HIDDEN['nik'])
            ->and($html)->toContain(DPIF_GUARDIAN_NIK)
            ->and($html)->toContain(e(DPIF_HIDDEN['address']))
            ->and($html)->toContain(e(DPIF_GUARDIAN_ADDRESS))
            ->and(substr_count($html, '<td class="consent-label">Alamat</td>'))->toBe(2, $name);
    }
});

/*
|--------------------------------------------------------------------------
| 3 — the audience: dual roles, exempt roles, the one predicate
|--------------------------------------------------------------------------
*/

it('DPIF-07 gives a Doctor who also holds Front Office the doctor view on clinical screens and keeps the full patient master form', function () {
    test()->dpifDoctorUser->assignRole('Front Office');
    $dual = test()->dpifDoctorUser->fresh();

    $visit = $this->actingAs($dual)->get(route('rme.visits.show', test()->dpifVisit))->assertOk()->getContent();
    $master = $this->actingAs($dual)->get(route('settings.patients.edit', test()->dpifPatient))->assertOk()->getContent();

    expect(dpifLeaks($visit))->toBe([])
        ->and(app(PatientIdentityPresenter::class)->audienceFor($dual))->toBe(PatientIdentityPresenter::AUDIENCE_DOCTOR)
        // The master form is the permission-gated place for the full record
        // (CG-11): the non-doctor role keeps working there.
        ->and($master)->toContain(DPIF_HIDDEN['phone'])
        ->and($master)->toContain(DPIF_HIDDEN['nik'])
        ->and($master)->toContain(e(DPIF_HIDDEN['address']));
});

it('DPIF-08 decides the audience through the one Doctor predicate: exempt roles and non-doctors see everything, a Doctor or no viewer the doctor view', function () {
    $presenter = app(PatientIdentityPresenter::class);
    $patient = test()->dpifPatient;
    $doctorAdminKlinik = User::factory()->create()->assignRole(['Doctor', 'Admin Klinik']);

    $cases = [
        'Doctor' => [test()->dpifDoctorUser, PatientIdentityPresenter::AUDIENCE_DOCTOR],
        'Doctor + Admin Klinik' => [$doctorAdminKlinik, PatientIdentityPresenter::AUDIENCE_DOCTOR],
        'no viewer' => [null, PatientIdentityPresenter::AUDIENCE_DOCTOR],
        'Front Office' => [test()->dpifFrontOffice, PatientIdentityPresenter::AUDIENCE_FULL],
        'Doctor + Supervisor RME' => [User::factory()->create()->assignRole(['Doctor', 'Supervisor RME']), PatientIdentityPresenter::AUDIENCE_FULL],
        'Doctor + Super Admin' => [User::factory()->create()->assignRole(['Doctor', 'Super Admin']), PatientIdentityPresenter::AUDIENCE_FULL],
        'Doctor + Owner' => [User::factory()->create()->assignRole(['Doctor', 'Owner']), PatientIdentityPresenter::AUDIENCE_FULL],
        'Kasir' => [userInRole('Kasir'), PatientIdentityPresenter::AUDIENCE_FULL],
    ];

    foreach ($cases as $name => [$viewer, $audience]) {
        $view = $presenter->forViewer($patient, $viewer);
        $restricted = $audience === PatientIdentityPresenter::AUDIENCE_DOCTOR;

        expect($presenter->audienceFor($viewer))->toBe($audience, $name)
            ->and($view->showsContact())->toBe(! $restricted, $name)
            ->and($view->phone)->toBe($restricted ? null : DPIF_HIDDEN['phone'], $name)
            ->and($view->whatsappNumber)->toBe($restricted ? null : DPIF_HIDDEN['whatsapp'], $name)
            ->and($view->email)->toBe($restricted ? null : DPIF_HIDDEN['email'], $name)
            ->and($view->address)->toBe($restricted ? null : DPIF_HIDDEN['address'], $name)
            ->and($view->name)->toBe('Pasien Identitas Dpif', $name)
            ->and($view->medicalRecordNumber)->toBe('DG-IF01-2026-00042', $name)
            ->and($view->birthPlaceDateAge)->toBe('Parepare, 01-02-1990 / 36 tahun', $name)
            ->and($view->occupation)->toBe('Guru Honorer Dpif', $name)
            ->and($view->gender)->toBe('Perempuan', $name)
            // The workspace table: "No. Tlp / Wa" shows the WhatsApp number
            // before the phone, as it always did; never a NIK.
            ->and(dpifLeaks(json_encode($view->biodataRows, JSON_UNESCAPED_UNICODE)))
            ->toBe($restricted ? [] : ['whatsapp', 'email', 'address'], $name);
    }
});

it('DPIF-09 masks both identity numbers and hides both addresses in the consent identity of a Doctor, and keeps them whole for the printed document', function () {
    $consent = dpifConsent();
    $presenter = app(PatientIdentityPresenter::class);

    $doctor = $presenter->forConsentViewer($consent, test()->dpifDoctorUser);
    $frontOffice = $presenter->forConsentViewer($consent, test()->dpifFrontOffice);
    $document = $presenter->forConsentDocument($consent);

    expect($doctor)->toBe([
        'addresses_visible' => false,
        'consenter_address' => null,
        'consenter_identity_number' => '****0099',
        'patient_address' => null,
        'patient_identity_number' => '****0042',
    ])->and($frontOffice)->toBe([
        'addresses_visible' => true,
        'consenter_address' => DPIF_GUARDIAN_ADDRESS,
        'consenter_identity_number' => DPIF_GUARDIAN_NIK,
        'patient_address' => DPIF_HIDDEN['address'],
        'patient_identity_number' => DPIF_HIDDEN['nik'],
    ])->and($document)->toBe($frontOffice);
});

it('DPIF-10 builds the identity of many patients without a query per patient once the viewer\'s roles are loaded (no N+1)', function () {
    $presenter = app(PatientIdentityPresenter::class);
    $patients = Patient::factory()->count(6)->create(['branch_id' => test()->dpifBranch->id]);
    $viewers = [test()->dpifDoctorUser->fresh(), test()->dpifFrontOffice->fresh()];

    foreach ($viewers as $viewer) {
        $presenter->forViewer($patients->first(), $viewer); // loads the viewer's roles once

        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ($patients as $patient) {
            $presenter->forViewer($patient, $viewer);
        }
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($queries)->toBe(0);
    }
});
