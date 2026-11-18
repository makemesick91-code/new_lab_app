<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.2 (rule 244 §5, RM PR-4) — the
| required core fields of a NEW patient and the no-phone exception
|--------------------------------------------------------------------------
|
| Owner decision 2026-11-17: "M4.2 Core fields, NIK optional. A controlled
| exception for patients without a phone. Existing patients are never blocked."
| Plan default 2: the no-phone exception is Front Office's, with a reason code,
| audited and reportable.
|
| Both registration forms (Master Data → Tambah Pasien and Kunjungan → Pasien
| Baru) take their rules from ONE builder (PatientCoreFields): gender, place of
| birth, date of birth (1900..clinical today), phone OR WhatsApp, address and
| occupation are required; NIK stays optional (16 digits when given). A patient
| without any number is registered only under `register_patient_without_phone`
| with a reason code (a note for LAINNYA), re-asserted in PatientService,
| audited with ids and the code only, and closed (audited) once an edit records
| a number. An edit keeps every field optional.
|
| Owner decisions 2026-11-17 (rule 244 §5.5, §5.3): a legitimate registration
| of a historical paper RM card is exempt from the core fields — only on the
| paper-card path itself (permission pair, the full card set, its validation
| and claim), never on an ordinary registration (CF-24..CF-27); and the
| no-phone note is restricted like the paper-card reason note: readable only by
| Supervisor RME and Owner on the restricted page, never flashed, refilled,
| logged or exported (CF-28..CF-32).
|
| RM PR-4b (re-review of the note's log safety): text PostgreSQL would refuse
| (invalid UTF-8, NUL) is a 422 on both forms, and a database failure on the
| patient insert or update is value-free in the log (CF-33..CF-36); the readers
| reach open exceptions from the restricted index (CF-37); the page shows and
| audits a note only for an open exception (CF-38); an edit refuses the same
| text with a 422 (CF-39); an edit that collides on a NIK in the database is
| the registration's 422, not a 500 (CF-40); every configured reason code fits
| its column (CF-41).
|
| Selected by the existing `FrontOffice` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `fncf` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitRegistrationGuard;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientRmPaperCardRegistration;
use App\Modules\Patient\Repositories\PatientRepository;
use App\Modules\Patient\Requests\StorePatientRequest;
use App\Modules\Patient\Services\PatientService;
use App\Modules\Patient\Support\PatientCoreFields;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

const FNCF_PERMISSION = 'register_patient_without_phone';
const FNCF_NIK = '7371011705900001';
const FNCF_NOTE = 'Pasien datang bersama pendamping panti.';

/** Bytes PostgreSQL refuses in a UTF-8 text column (SQLSTATE 22021): an invalid sequence. */
const FNCF_BAD_UTF8 = "\xC3\x28";

/** Enough of the NIK to find it in a log line even where PHP truncates string arguments at 15 bytes. */
const FNCF_NIK_HEAD = '73710117059';

beforeEach(function (): void {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    pinTestClock('2026-06-13 01:00:00'); // 09:00 WITA, clinical day 2026-06-13

    Branch::query()->where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    $this->clinic = Clinic::factory()->create();
    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->branch = Branch::factory()->create(['code' => 'FCF1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->treatment = Treatment::factory()->create(['is_active' => true]);
});

afterEach(fn () => freeTestClock());

/** The master-form payload: every core field present. */
function fncfMasterPayload(array $overrides = []): array
{
    return array_merge([
        'clinic_id' => test()->clinic->id,
        'doctor_id' => test()->doctor->id,
        'branch_id' => test()->branch->id,
        'name' => 'Pasien Inti '.Str::random(6),
        'gender' => 'Female',
        'place_of_birth' => 'Makassar',
        'date_of_birth' => '1990-05-17',
        'phone' => '081234500001',
        'address' => 'Jl. Uji Coba No. 1',
        'occupation' => 'Guru',
        'is_active' => 1,
    ], $overrides);
}

/** The Kunjungan → Pasien Baru payload: every core field present. */
function fncfVisitPayload(array $patientOverrides = [], array $overrides = []): array
{
    return array_merge([
        'patient_mode' => 'new',
        'branch_id' => test()->branch->id,
        'clinic_id' => test()->clinic->id,
        'doctor_id' => test()->doctor->id,
        'initial_treatment_id' => test()->treatment->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'new_patient' => array_merge([
            'name' => 'Pasien Kunjungan '.Str::random(6),
            'branch_id' => test()->branch->id,
            'gender' => 'Male',
            'place_of_birth' => 'Gowa',
            'date_of_birth' => '1985-02-03',
            'phone' => '081234500002',
            'address' => 'Jl. Kunjungan No. 2',
            'occupation' => 'Wiraswasta',
        ], $patientOverrides),
    ], $overrides);
}

/** A registrar of the master form without the exception. */
function fncfMasterRegistrar(): User
{
    return userWith(['manage patients']);
}

/** A registrar of Kunjungan → Pasien Baru without the exception. */
function fncfVisitRegistrar(): User
{
    rmeMakeDoctorOnline(test()->doctor, test()->branch);

    return userWith(['manage_clinic_visits', 'view_clinic_visits', 'manage patients']);
}

/** A Front Office account working the test branch (holds the exception by seed). */
function fncfFrontOffice(): User
{
    $user = User::factory()->create();
    rmeMakeFrontOfficeActive($user, test()->branch);

    return $user->fresh();
}

function fncfAuditPayloads(): string
{
    return AuditLog::query()->get()
        ->map(fn (AuditLog $a): string => (string) json_encode([$a->old_values, $a->new_values]))
        ->implode(' ');
}

/** The core fields of a new patient (none of them sent by fncfPaperCardOnly()). */
const FNCF_CORE_FIELDS = ['gender', 'place_of_birth', 'date_of_birth', 'phone', 'address', 'occupation'];

/** A historical paper-card registration carrying NO core field (rule 244 §5.5 exemption). */
function fncfPaperCardOnly(array $overrides = []): array
{
    return array_merge([
        'clinic_id' => test()->clinic->id,
        'doctor_id' => test()->doctor->id,
        'branch_id' => test()->branch->id,
        'name' => 'Pasien Kartu Bebas',
        'is_active' => 1,
        'paper_card_year' => '2025',
        'paper_card_sequence' => '0012',
        'paper_card_reason_code' => 'KARTU_LAMA_PASIEN_LAMA',
    ], $overrides);
}

/** A patient Front Office registered without a number, with a LAINNYA note. */
function fncfPatientWithNote(User $frontOffice, string $name = 'Pasien Catatan Terbatas'): Patient
{
    test()->actingAs($frontOffice)
        ->post(route('settings.patients.store'), fncfMasterPayload([
            'name' => $name,
            'phone' => '',
            'no_phone_reason_code' => 'LAINNYA',
            'no_phone_reason_note' => FNCF_NOTE,
        ]))
        ->assertSessionHasNoErrors();

    return Patient::query()->where('name', $name)->sole();
}

/** The restricted per-patient reasons page (rule 244 §4.3, §5.3). */
function fncfReasonsUrl(Patient $patient): string
{
    return route('settings.patients.paper-card-registrations.show', $patient);
}

/** An account in `$role`; the context-bound desk roles work at `$branch` (default FCF1). */
function fncfUser(string $role, ?Branch $branch = null): User
{
    $user = User::factory()->create();
    $branch ??= test()->branch;

    match ($role) {
        'Front Office' => rmeMakeFrontOfficeActive($user, $branch),
        'Admin Klinik' => rmeMakeAdminClinicActive($user, $branch),
        'Kasir' => rmeMakeKasirActive($user, $branch),
        'Super Admin' => $user->assignRole('Super Admin'),
        default => $user->assignRole($role),
    };

    return $user->fresh();
}

/** Every log line `$action` writes, with its context and any reported exception. */
function fncfLogLines(callable $action): string
{
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$lines): void {
        $exception = $event->context['exception'] ?? null;
        $lines[] = $event->message.' '.json_encode(array_diff_key($event->context, ['exception' => true]))
            .($exception instanceof Throwable ? ' '.$exception : '');
    });

    $action();

    return implode("\n", $lines);
}

/** Whether `$needle` appears in any string inside a stack trace's call arguments. */
function fncfTraceArgsContain(Throwable $e, string $needle): bool
{
    $search = function (mixed $value) use (&$search, $needle): bool {
        if (is_string($value)) {
            return str_contains($value, $needle);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($search($item)) {
                    return true;
                }
            }
        }

        return false;
    };

    foreach ($e->getTrace() as $frame) {
        if ($search($frame['args'] ?? [])) {
            return true;
        }
    }

    return false;
}

// =============================================================================
// Core fields on both forms (new patients only)
// =============================================================================

dataset('fncf core fields', [
    'gender' => ['gender', 'gender'],
    'place of birth' => ['place_of_birth', 'place_of_birth'],
    'date of birth' => ['date_of_birth', 'date_of_birth'],
    'address' => ['address', 'address'],
    'occupation' => ['occupation', 'occupation'],
    'phone and WhatsApp both missing' => ['phone', 'phone'],
]);

it('CF-01 refuses a Master Data registration missing a core field (422) and writes nothing', function (string $field, string $errorKey): void {
    $payload = fncfMasterPayload(['name' => 'Pasien Kurang Data', $field => '']);

    $this->actingAs(fncfMasterRegistrar())
        ->post(route('settings.patients.store'), $payload)
        ->assertSessionHasErrors($errorKey);

    expect(Patient::query()->where('name', 'Pasien Kurang Data')->exists())->toBeFalse();
})->with('fncf core fields');

it('CF-02 refuses a Kunjungan → Pasien Baru registration missing a core field (422): no patient, no visit', function (string $field, string $errorKey): void {
    $registrar = fncfVisitRegistrar();

    $this->actingAs($registrar)
        ->post(route('rme.visits.store'), fncfVisitPayload(['name' => 'Pasien Kunjungan Kurang', $field => '']))
        ->assertSessionHasErrors('new_patient.'.$errorKey);

    expect(Patient::query()->where('name', 'Pasien Kunjungan Kurang')->exists())->toBeFalse()
        ->and(ClinicVisit::query()->count())->toBe(0);
})->with('fncf core fields');

it('CF-03 answers 422 as JSON on both forms for a missing core field', function (): void {
    $this->actingAs(fncfMasterRegistrar())
        ->postJson(route('settings.patients.store'), fncfMasterPayload(['occupation' => null]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['occupation']);

    $this->actingAs(fncfVisitRegistrar())
        ->postJson(route('rme.visits.store'), fncfVisitPayload(['place_of_birth' => null]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['new_patient.place_of_birth']);
});

it('CF-04 accepts phone OR WhatsApp on both forms and stores the place of birth', function (): void {
    $this->actingAs(fncfMasterRegistrar())
        ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Hanya WA', 'phone' => '', 'whatsapp_number' => '081234500010']))
        ->assertSessionHasNoErrors();

    $this->actingAs(fncfVisitRegistrar())
        ->post(route('rme.visits.store'), fncfVisitPayload(['name' => 'Pasien Kunjungan Ponsel', 'place_of_birth' => 'Pare-Pare']))
        ->assertSessionHasNoErrors();

    $waOnly = Patient::query()->where('name', 'Pasien Hanya WA')->sole();
    $visitPatient = Patient::query()->where('name', 'Pasien Kunjungan Ponsel')->sole();

    expect($waOnly->place_of_birth)->toBe('Makassar')
        ->and($waOnly->phone)->toBeNull()
        ->and($waOnly->whatsapp_number)->toBe('081234500010')
        ->and($waOnly->no_phone_reason_code)->toBeNull()
        ->and($visitPatient->place_of_birth)->toBe('Pare-Pare')
        ->and(ClinicVisit::query()->where('patient_id', $visitPatient->id)->exists())->toBeTrue();
});

it('CF-05 keeps NIK optional and, when given, exactly 16 digits on both forms', function (): void {
    $master = fncfMasterRegistrar();

    $this->actingAs($master)
        ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Tanpa NIK']))
        ->assertSessionHasNoErrors();

    foreach (['737101170590000', '73710117059000012', '73710117059A0001'] as $bad) {
        $this->actingAs($master)
            ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien NIK Salah', 'ktp_number' => $bad]))
            ->assertSessionHasErrors('ktp_number');

        $this->actingAs(fncfVisitRegistrar())
            ->post(route('rme.visits.store'), fncfVisitPayload(['name' => 'Pasien NIK Salah', 'ktp_number' => $bad]))
            ->assertSessionHasErrors('new_patient.ktp_number');
    }

    $this->actingAs($master)
        ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien NIK Benar', 'ktp_number' => FNCF_NIK]))
        ->assertSessionHasNoErrors();

    expect(Patient::query()->where('name', 'Pasien Tanpa NIK')->value('ktp_number'))->toBeNull()
        ->and(Patient::query()->where('name', 'Pasien NIK Salah')->exists())->toBeFalse()
        ->and(Patient::query()->where('name', 'Pasien NIK Benar')->value('ktp_number'))->toBe(FNCF_NIK);
});

it('CF-06 bounds the date of birth to 1900..the clinical day (ClinicalClock), on both forms', function (): void {
    // 00:30 WITA on 13 June while UTC is still 12 June: the clinical day is the 13th.
    pinTestClock('2026-06-12 16:30:00');
    $master = fncfMasterRegistrar();

    foreach (['2026-06-14', '1899-12-31'] as $bad) {
        $this->actingAs($master)
            ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Tanggal Salah', 'date_of_birth' => $bad]))
            ->assertSessionHasErrors('date_of_birth');

        $this->actingAs(fncfVisitRegistrar())
            ->post(route('rme.visits.store'), fncfVisitPayload(['name' => 'Pasien Tanggal Salah', 'date_of_birth' => $bad]))
            ->assertSessionHasErrors('new_patient.date_of_birth');
    }

    $this->actingAs($master)
        ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Lahir Hari Ini', 'date_of_birth' => '2026-06-13']))
        ->assertSessionHasNoErrors();
    $this->actingAs($master)
        ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Lahir 1900', 'date_of_birth' => '1900-01-01', 'phone' => '081234500099']))
        ->assertSessionHasNoErrors();

    expect(Patient::query()->where('name', 'Pasien Tanggal Salah')->exists())->toBeFalse()
        ->and(Patient::query()->where('name', 'Pasien Lahir Hari Ini')->exists())->toBeTrue()
        ->and(Patient::query()->where('name', 'Pasien Lahir 1900')->exists())->toBeTrue();
});

it('CF-07 bounds the place of birth: at most 100 characters, no control characters', function (): void {
    $master = fncfMasterRegistrar();

    $this->actingAs($master)
        ->post(route('settings.patients.store'), fncfMasterPayload(['place_of_birth' => str_repeat('a', 101)]))
        ->assertSessionHasErrors('place_of_birth');
    $this->actingAs($master)
        ->post(route('settings.patients.store'), fncfMasterPayload(['place_of_birth' => "Makassar\x07"]))
        ->assertSessionHasErrors('place_of_birth');
    $this->actingAs($master)
        ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Tempat Panjang', 'place_of_birth' => str_repeat('b', 100)]))
        ->assertSessionHasNoErrors();

    expect(Patient::query()->where('name', 'Pasien Tempat Panjang')->value('place_of_birth'))->toBe(str_repeat('b', 100));
});

it('CF-08 shows the messages in Indonesian', function (): void {
    $response = $this->actingAs(fncfMasterRegistrar())
        ->post(route('settings.patients.store'), fncfMasterPayload([
            'gender' => '', 'place_of_birth' => '', 'date_of_birth' => '', 'phone' => '', 'address' => '', 'occupation' => '',
        ]));

    $errors = session('errors')->getBag('default');

    expect($errors->first('gender'))->toBe('Jenis kelamin wajib dipilih.')
        ->and($errors->first('place_of_birth'))->toBe('Tempat lahir wajib diisi.')
        ->and($errors->first('date_of_birth'))->toBe('Tanggal lahir wajib diisi.')
        ->and($errors->first('phone'))->toBe('Nomor ponsel atau nomor WhatsApp wajib diisi.')
        ->and($errors->first('address'))->toBe('Alamat wajib diisi.')
        ->and($errors->first('occupation'))->toBe('Pekerjaan wajib diisi.');

    $response->assertSessionHasErrors();
});

// =============================================================================
// The no-phone exception (register_patient_without_phone)
// =============================================================================

it('CF-09 lets Front Office register a patient without a number on the master form, with a reason, audited without values', function (): void {
    $frontOffice = fncfFrontOffice();

    $this->actingAs($frontOffice)
        ->post(route('settings.patients.store'), fncfMasterPayload([
            'name' => 'Pasien Tanpa Ponsel',
            'phone' => '',
            'ktp_number' => FNCF_NIK,
            'no_phone_reason_code' => 'TIDAK_PUNYA_TELEPON',
        ]))
        ->assertSessionHasNoErrors();

    $patient = Patient::query()->where('name', 'Pasien Tanpa Ponsel')->sole();
    $log = AuditLog::query()->where('action', PatientService::AUDIT_NO_PHONE_RECORDED)->sole();

    expect($patient->phone)->toBeNull()
        ->and($patient->whatsapp_number)->toBeNull()
        ->and($patient->no_phone_reason_code)->toBe('TIDAK_PUNYA_TELEPON')
        ->and($patient->no_phone_reason_note)->toBeNull()
        ->and($patient->no_phone_recorded_at)->not->toBeNull()
        ->and($log->entity_type)->toBe('mst_patients')
        ->and((int) $log->entity_id)->toBe($patient->id)
        ->and((int) $log->performed_by)->toBe($frontOffice->id)
        ->and($log->old_values)->toBeNull()
        // jsonb does not keep key order: compared by key.
        ->and($log->new_values)->toEqual([
            'patient_id' => $patient->id,
            'branch_id' => $this->branch->id,
            'actor_user_id' => $frontOffice->id,
            'reason_code' => 'TIDAK_PUNYA_TELEPON',
        ]);

    // No value of the patient in any audit payload (CG-11).
    $payloads = fncfAuditPayloads();
    foreach (['Pasien Tanpa Ponsel', FNCF_NIK, 'Makassar', 'Jl. Uji Coba', 'Guru', '1990-05-17'] as $value) {
        expect($payloads)->not->toContain($value);
    }
});

it('CF-10 lets Front Office use the exception on Kunjungan → Pasien Baru, with a note for LAINNYA kept out of the audit', function (): void {
    $frontOffice = fncfFrontOffice();
    rmeMakeDoctorOnline($this->doctor, $this->branch);

    $this->actingAs($frontOffice)
        ->post(route('rme.visits.store'), fncfVisitPayload([
            'name' => 'Pasien Kunjungan Tanpa Ponsel',
            'phone' => '',
            'no_phone_reason_code' => 'LAINNYA',
            'no_phone_reason_note' => FNCF_NOTE,
        ]))
        ->assertSessionHasNoErrors();

    $patient = Patient::query()->where('name', 'Pasien Kunjungan Tanpa Ponsel')->sole();
    $log = AuditLog::query()->where('action', PatientService::AUDIT_NO_PHONE_RECORDED)->sole();

    expect($patient->no_phone_reason_code)->toBe('LAINNYA')
        ->and($patient->no_phone_reason_note)->toBe(FNCF_NOTE)
        ->and(ClinicVisit::query()->where('patient_id', $patient->id)->exists())->toBeTrue()
        ->and(collect($log->new_values)->keys()->sort()->values()->all())->toBe(['actor_user_id', 'branch_id', 'patient_id', 'reason_code'])
        ->and(fncfAuditPayloads())->not->toContain('pendamping panti')
        // The note never leaves through the model's array/JSON form.
        ->and($patient->toArray())->not->toHaveKey('no_phone_reason_note');
});

it('CF-11 refuses the exception (403) to an account without the permission, on both forms, writing nothing', function (): void {
    $this->actingAs(fncfMasterRegistrar())
        ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Tolak', 'phone' => '', 'no_phone_reason_code' => 'TIDAK_PUNYA_TELEPON']))
        ->assertForbidden();

    $this->actingAs(fncfVisitRegistrar())
        ->post(route('rme.visits.store'), fncfVisitPayload(['name' => 'Pasien Tolak', 'phone' => '', 'no_phone_reason_code' => 'TIDAK_PUNYA_TELEPON']))
        ->assertForbidden();

    // A Kasir-only account (Front Office's other half) does not hold it either.
    $kasir = User::factory()->create();
    rmeMakeKasirActive($kasir, $this->branch);
    $kasir->givePermissionTo('manage patients');

    $this->actingAs($kasir->fresh())
        ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Tolak', 'phone' => '', 'no_phone_reason_code' => 'TIDAK_PUNYA_TELEPON']))
        ->assertForbidden();

    expect(Patient::query()->where('name', 'Pasien Tolak')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', PatientService::AUDIT_NO_PHONE_RECORDED)->exists())->toBeFalse();
});

it('CF-12 validates the exception: a listed code, a 10–500 character note for LAINNYA, and no number beside it', function (): void {
    $frontOffice = fncfFrontOffice();

    $cases = [
        // [overrides, error key]
        [['phone' => ''], 'phone'],
        [['phone' => '', 'no_phone_reason_code' => 'BUKAN_KODE'], 'no_phone_reason_code'],
        [['phone' => '', 'no_phone_reason_code' => 'LAINNYA'], 'no_phone_reason_note'],
        [['phone' => '', 'no_phone_reason_code' => 'LAINNYA', 'no_phone_reason_note' => 'pendek'], 'no_phone_reason_note'],
        [['phone' => '', 'no_phone_reason_code' => 'LAINNYA', 'no_phone_reason_note' => str_repeat('x', 501)], 'no_phone_reason_note'],
        [['phone' => '', 'no_phone_reason_code' => 'LAINNYA', 'no_phone_reason_note' => "Catatan dengan\x07kontrol"], 'no_phone_reason_note'],
        [['phone' => '', 'no_phone_reason_note' => FNCF_NOTE], 'no_phone_reason_code'],
        [['phone' => '081234500003', 'no_phone_reason_code' => 'TIDAK_PUNYA_TELEPON'], 'no_phone_reason_code'],
        [['phone' => '', 'whatsapp_number' => '081234500004', 'no_phone_reason_code' => 'TIDAK_PUNYA_TELEPON'], 'no_phone_reason_code'],
    ];

    foreach ($cases as [$overrides, $errorKey]) {
        $this->actingAs($frontOffice)
            ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Pengecualian Salah'] + $overrides))
            ->assertSessionHasErrors($errorKey);
    }

    expect(Patient::query()->where('name', 'Pasien Pengecualian Salah')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', PatientService::AUDIT_NO_PHONE_RECORDED)->exists())->toBeFalse();
});

it('CF-13 re-asserts the exception in PatientService for every caller, binding Super Admin to its bounds', function (): void {
    $data = fncfMasterPayload(['phone' => null, 'no_phone_reason_code' => 'TIDAK_PUNYA_TELEPON']);

    expect(fn () => app(PatientService::class)->create($data, fncfMasterRegistrar()))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(PatientService::class)->create($data))
        ->toThrow(AuthorizationException::class);

    $super = superAdmin();

    expect(fn () => app(PatientService::class)->create(array_merge($data, ['no_phone_reason_code' => 'BUKAN_KODE']), $super))
        ->toThrow(ValidationException::class);
    expect(fn () => app(PatientService::class)->create(array_merge($data, ['no_phone_reason_code' => 'LAINNYA']), $super))
        ->toThrow(ValidationException::class);
    expect(fn () => app(PatientService::class)->create(array_merge($data, ['phone' => '081234500005']), $super))
        ->toThrow(ValidationException::class);

    // A caller cannot stamp the exception columns directly.
    $patient = app(PatientService::class)->create(array_merge(fncfMasterPayload(['name' => 'Pasien Kolom Langsung']), [
        'no_phone_reason_code' => null,
        'no_phone_recorded_at' => '2020-01-01 00:00:00',
        'no_phone_reason_note' => 'disisipkan langsung',
    ]), $super);

    expect($patient->fresh()->no_phone_recorded_at)->toBeNull()
        ->and($patient->fresh()->no_phone_reason_note)->toBeNull();

    $ok = app(PatientService::class)->create(array_merge($data, ['name' => 'Pasien Super Admin']), $super);

    expect($ok->no_phone_reason_code)->toBe('TIDAK_PUNYA_TELEPON')
        ->and(Patient::query()->count())->toBe(2);
});

it('CF-14 closes the exception, audited without values, once an edit records a number — and only once', function (): void {
    $frontOffice = fncfFrontOffice();
    $this->actingAs($frontOffice)
        ->post(route('settings.patients.store'), fncfMasterPayload([
            'name' => 'Pasien Nanti Punya Ponsel', 'phone' => '', 'no_phone_reason_code' => 'TANGGUNGAN_TANPA_TELEPON',
        ]))
        ->assertSessionHasNoErrors();
    $patient = Patient::query()->where('name', 'Pasien Nanti Punya Ponsel')->sole();

    $edit = fn (array $overrides) => [
        'clinic_id' => $this->clinic->id,
        'doctor_id' => $this->doctor->id,
        'name' => $patient->name,
        'is_active' => 1,
    ] + $overrides;

    // An edit without a number keeps it open.
    $this->actingAs($frontOffice)
        ->put(route('settings.patients.update', $patient), $edit(['address' => 'Alamat baru']))
        ->assertSessionHasNoErrors();
    expect($patient->fresh()->no_phone_reason_code)->toBe('TANGGUNGAN_TANPA_TELEPON')
        ->and(AuditLog::query()->where('action', PatientService::AUDIT_NO_PHONE_CLEARED)->exists())->toBeFalse();

    // Recording a WhatsApp number closes it.
    $this->actingAs($frontOffice)
        ->put(route('settings.patients.update', $patient), $edit(['whatsapp_number' => '081234500006']))
        ->assertSessionHasNoErrors();

    $fresh = $patient->fresh();
    $log = AuditLog::query()->where('action', PatientService::AUDIT_NO_PHONE_CLEARED)->sole();

    expect($fresh->no_phone_reason_code)->toBeNull()
        ->and($fresh->no_phone_reason_note)->toBeNull()
        ->and($fresh->no_phone_recorded_at)->toBeNull()
        ->and($fresh->whatsapp_number)->toBe('081234500006')
        // jsonb does not keep key order: compared by key.
        ->and($log->new_values)->toEqual([
            'patient_id' => $patient->id,
            'branch_id' => $this->branch->id,
            'actor_user_id' => $frontOffice->id,
            'reason_code' => 'TANGGUNGAN_TANPA_TELEPON',
        ])
        ->and(fncfAuditPayloads())->not->toContain('081234500006');

    // A later edit with a number does not close (or audit) it again.
    $this->actingAs($frontOffice)
        ->put(route('settings.patients.update', $patient), $edit(['phone' => '081234500007', 'whatsapp_number' => '081234500006']))
        ->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('action', PatientService::AUDIT_NO_PHONE_CLEARED)->count())->toBe(1);
});

it('CF-15 never lets an edit write the exception columns', function (): void {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id, 'phone' => null, 'whatsapp_number' => null]);

    app(PatientService::class)->update($patient, [
        'clinic_id' => $this->clinic->id,
        'doctor_id' => $this->doctor->id,
        'name' => $patient->name,
        'no_phone_reason_code' => 'TIDAK_PUNYA_TELEPON',
        'no_phone_reason_note' => FNCF_NOTE,
        'no_phone_recorded_at' => '2026-01-01 00:00:00',
    ]);

    $fresh = $patient->fresh();

    expect($fresh->no_phone_reason_code)->toBeNull()
        ->and($fresh->no_phone_reason_note)->toBeNull()
        ->and($fresh->no_phone_recorded_at)->toBeNull();
});

// =============================================================================
// Existing patients are never blocked
// =============================================================================

it('CF-16 still saves an edit of a legacy, incomplete patient (every field optional; old DOB and NIK kept as they are)', function (): void {
    $legacy = Patient::factory()->create([
        'branch_id' => $this->branch->id,
        'medical_record_number' => 'DG-FCF1-2026-0042',
        'name' => 'Pasien Lama Tidak Lengkap',
        'gender' => null,
        'date_of_birth' => '1899-05-01',
        'phone' => null,
        'whatsapp_number' => null,
        'address' => null,
        'occupation' => null,
        'ktp_number' => '7371011705',
    ]);

    $this->actingAs(fncfMasterRegistrar())
        ->put(route('settings.patients.update', $legacy), [
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'name' => 'Pasien Lama Diperbarui',
            'date_of_birth' => '1899-05-01',
            'ktp_number' => '7371011705',
            'is_active' => 1,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.patients.index'));

    $fresh = $legacy->fresh();

    expect($fresh->name)->toBe('Pasien Lama Diperbarui')
        ->and($fresh->gender)->toBeNull()
        ->and($fresh->place_of_birth)->toBeNull()
        ->and($fresh->occupation)->toBeNull()
        ->and($fresh->phone)->toBeNull()
        ->and($fresh->ktp_number)->toBe('7371011705')
        ->and($fresh->medical_record_number)->toBe('DG-FCF1-2026-0042')
        ->and($fresh->no_phone_reason_code)->toBeNull();
});

it('CF-17 lets an edit add the place of birth, and checks a CHANGED date of birth or NIK only', function (): void {
    $legacy = Patient::factory()->create(['branch_id' => $this->branch->id, 'ktp_number' => null]);
    $registrar = fncfMasterRegistrar();
    $base = [
        'clinic_id' => $this->clinic->id,
        'doctor_id' => $this->doctor->id,
        'name' => $legacy->name,
        'is_active' => 1,
    ];

    $this->actingAs($registrar)
        ->put(route('settings.patients.update', $legacy), $base + ['place_of_birth' => 'Maros'])
        ->assertSessionHasNoErrors();
    $this->actingAs($registrar)
        ->put(route('settings.patients.update', $legacy), $base + ['date_of_birth' => '2026-06-14'])
        ->assertSessionHasErrors('date_of_birth');
    $this->actingAs($registrar)
        ->put(route('settings.patients.update', $legacy), $base + ['ktp_number' => '12345'])
        ->assertSessionHasErrors('ktp_number');

    expect($legacy->fresh()->place_of_birth)->toBe('Maros')
        ->and($legacy->fresh()->ktp_number)->toBeNull();
});

it('CF-18 never refuses an existing-patient visit on core-field state left in the hidden Pasien Baru panel', function (): void {
    $registrar = fncfVisitRegistrar();
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id, 'phone' => null, 'whatsapp_number' => null, 'gender' => null]);

    $this->actingAs($registrar)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'existing',
            'patient_id' => $patient->id,
            'branch_id' => $this->branch->id,
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
            'new_patient' => ['name' => '', 'date_of_birth' => '2099-01-01', 'ktp_number' => '12'],
        ])
        ->assertSessionHasNoErrors();

    expect(ClinicVisit::query()->where('patient_id', $patient->id)->exists())->toBeTrue()
        ->and($patient->fresh()->gender)->toBeNull();
});

// =============================================================================
// Branch scope and KTP masking unchanged
// =============================================================================

it('CF-19 keeps the branch scope: a desk account still registers only at its working branch', function (): void {
    $other = Branch::factory()->create(['code' => 'FCF2', 'is_active' => true, 'is_rme_enabled' => true]);
    $frontOffice = fncfFrontOffice();

    $this->actingAs($frontOffice)
        ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Cabang Lain', 'branch_id' => $other->id]))
        ->assertSessionHasErrors('branch_id');

    expect(Patient::query()->where('name', 'Pasien Cabang Lain')->exists())->toBeFalse();
});

it('CF-20 keeps the NIK masked on the patient list and the Audit Data Pasien report', function (): void {
    $this->actingAs(fncfMasterRegistrar())
        ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien NIK Tertutup', 'ktp_number' => FNCF_NIK]))
        ->assertSessionHasNoErrors();

    $this->actingAs(superAdmin())
        ->get(route('settings.patients.index'))
        ->assertOk()
        ->assertSee('Pasien NIK Tertutup')
        ->assertDontSee(FNCF_NIK);

    $this->actingAs(superAdmin())
        ->get(route('rme.patients.audit'))
        ->assertOk()
        ->assertSee('Pasien NIK Tertutup')
        ->assertSee('****'.substr(FNCF_NIK, -4))
        ->assertDontSee(FNCF_NIK);
});

// =============================================================================
// Views (rule 135 mirror) and seeders
// =============================================================================

it('CF-21 marks the core fields on both forms and offers the no-phone exception only to holders', function (): void {
    $holder = fncfFrontOffice();
    rmeMakeDoctorOnline($this->doctor, $this->branch);

    $this->actingAs($holder)
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('name="place_of_birth"', false)
        ->assertSee('data-core-field-required', false)
        ->assertSee('name="no_phone_reason_code"', false)
        ->assertSee('Pasien tidak memiliki nomor telepon/WhatsApp', false);

    $this->actingAs($holder)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('name="new_patient[place_of_birth]"', false)
        ->assertSee('data-core-field-required', false)
        ->assertSee('name="new_patient[no_phone_reason_code]"', false);

    $nonHolder = fncfMasterRegistrar();
    $nonHolder->givePermissionTo(['manage_clinic_visits', 'view_clinic_visits']);

    $this->actingAs($nonHolder)
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('name="place_of_birth"', false)
        ->assertDontSee('no_phone_reason_code', false);

    $this->actingAs($nonHolder)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('name="new_patient[place_of_birth]"', false)
        ->assertDontSee('no_phone_reason_code', false);
});

it('CF-22 shows an open exception on the edit page and offers the place of birth there', function (): void {
    $frontOffice = fncfFrontOffice();
    $this->actingAs($frontOffice)
        ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Edit Pengecualian', 'phone' => '', 'no_phone_reason_code' => 'TIDAK_PUNYA_TELEPON']))
        ->assertSessionHasNoErrors();
    $patient = Patient::query()->where('name', 'Pasien Edit Pengecualian')->sole();

    $this->actingAs($frontOffice)
        ->get(route('settings.patients.edit', $patient))
        ->assertOk()
        ->assertSee('name="place_of_birth"', false)
        ->assertSee('Terdaftar tanpa nomor telepon', false)
        ->assertSee('Pasien tidak memiliki nomor telepon/WhatsApp', false)
        ->assertDontSee('name="no_phone_reason_code"', false);
});

it('CF-23 seeds register_patient_without_phone for Front Office and Admin Klinik only (Super Admin through *)', function (): void {
    expect(RoleSeeder::ROLE_PERMISSIONS['Front Office'])->toContain(FNCF_PERMISSION)
        ->and(RoleSeeder::ROLE_PERMISSIONS['Admin Klinik'])->toContain(FNCF_PERMISSION)
        ->and(RoleSeeder::ROLE_PERMISSIONS['Kasir'])->not->toContain(FNCF_PERMISSION)
        ->and(RoleSeeder::ROLE_PERMISSIONS['Super Admin'])->toBe('*')
        ->and(PatientCoreFields::WITHOUT_PHONE_PERMISSION)->toBe(FNCF_PERMISSION);

    $holders = Role::query()->get()
        ->filter(fn (Role $role): bool => $role->hasPermissionTo(FNCF_PERMISSION))
        ->pluck('name')->sort()->values()->all();

    expect($holders)->toBe(['Admin Klinik', 'Front Office', 'Super Admin']);
});

it('CF-24 lets the paper-card path carry the exception when its holder sends it; a paper-card holder without that permission may leave the number out but may not send the exception', function (): void {
    $actor = userWith(['manage patients', 'register_patient_paper_card_rm', FNCF_PERMISSION]);

    $this->actingAs($actor)
        ->post(route('settings.patients.store'), fncfMasterPayload([
            'name' => 'Pasien Kartu Tanpa Ponsel',
            'phone' => '',
            'no_phone_reason_code' => 'TIDAK_PUNYA_TELEPON',
            'paper_card_year' => '2025',
            'paper_card_sequence' => '0012',
            'paper_card_reason_code' => 'KARTU_LAMA_PASIEN_LAMA',
        ]))
        ->assertSessionHasNoErrors();

    $patient = Patient::query()->where('name', 'Pasien Kartu Tanpa Ponsel')->sole();

    expect($patient->medical_record_number)->toBe('DG-FCF1-2025-0012')
        ->and($patient->no_phone_reason_code)->toBe('TIDAK_PUNYA_TELEPON')
        ->and(AuditLog::query()->where('action', PatientService::AUDIT_NO_PHONE_RECORDED)->where('entity_id', $patient->id)->exists())->toBeTrue();

    // Owner decision 2026-11-17 (rule 244 §5.5) — the paper-card registration
    // is exempt from the core fields, phone included: no exception is needed.
    $paperCardOnly = userWith(['manage patients', 'register_patient_paper_card_rm']);

    $this->actingAs($paperCardOnly)
        ->post(route('settings.patients.store'), fncfMasterPayload([
            'name' => 'Pasien Kartu Tanpa Nomor',
            'phone' => '',
            'paper_card_year' => '2025',
            'paper_card_sequence' => '0013',
            'paper_card_reason_code' => 'KARTU_LAMA_PASIEN_LAMA',
        ]))
        ->assertSessionHasNoErrors();

    $exempt = Patient::query()->where('name', 'Pasien Kartu Tanpa Nomor')->sole();

    expect($exempt->medical_record_number)->toBe('DG-FCF1-2025-0013')
        ->and($exempt->phone)->toBeNull()
        ->and($exempt->no_phone_reason_code)->toBeNull()
        ->and(AuditLog::query()->where('action', PatientService::AUDIT_NO_PHONE_RECORDED)->where('entity_id', $exempt->id)->exists())->toBeFalse();

    // Sending the exception still needs its own permission (403): a posted value
    // is refused, never silently honoured.
    $this->actingAs($paperCardOnly)
        ->post(route('settings.patients.store'), fncfMasterPayload([
            'name' => 'Pasien Kartu Tolak',
            'phone' => '',
            'no_phone_reason_code' => 'TIDAK_PUNYA_TELEPON',
            'paper_card_year' => '2025',
            'paper_card_sequence' => '0014',
            'paper_card_reason_code' => 'KARTU_LAMA_PASIEN_LAMA',
        ]))
        ->assertForbidden();

    expect(Patient::query()->where('name', 'Pasien Kartu Tolak')->exists())->toBeFalse();
});

// =============================================================================
// Owner decision 2026-11-17 — the paper-card registration is exempt from the
// core fields (rule 244 §5.5); an ordinary registration never is
// =============================================================================

it('CF-25 exempts a legitimate paper-card registration from every core field, keeping the typed number, the reason and the audit', function (): void {
    $supervisor = fncfUser('Supervisor RME');

    $this->actingAs($supervisor)
        ->post(route('settings.patients.store'), fncfPaperCardOnly())
        ->assertSessionHasNoErrors();

    $patient = Patient::query()->where('name', 'Pasien Kartu Bebas')->sole();

    expect($patient->medical_record_number)->toBe('DG-FCF1-2025-0012')
        ->and($patient->manual_rm_number)->toBe('0012')
        ->and($patient->only(['gender', 'place_of_birth', 'date_of_birth', 'phone', 'whatsapp_number', 'address', 'occupation', 'no_phone_reason_code']))
        ->toBe(['gender' => null, 'place_of_birth' => null, 'date_of_birth' => null, 'phone' => null, 'whatsapp_number' => null, 'address' => null, 'occupation' => null, 'no_phone_reason_code' => null])
        ->and(PatientRmPaperCardRegistration::query()->where('patient_id', $patient->id)->sole()->reason_code)->toBe('KARTU_LAMA_PASIEN_LAMA')
        ->and(AuditLog::query()->where('action', PatientService::AUDIT_PAPER_CARD_REGISTERED)->where('entity_id', $patient->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', PatientService::AUDIT_NO_PHONE_RECORDED)->exists())->toBeFalse();
});

it('CF-26 never extends the exemption to an ordinary registration: without the authorized, complete and valid paper-card path the core fields still bind', function (): void {
    $supervisor = fncfUser('Supervisor RME');
    $ordinary = array_diff_key(fncfPaperCardOnly(), array_flip(PatientService::PAPER_CARD_FIELDS));

    // The paper-card holder registering an ordinary (automatic-number) patient.
    $this->actingAs($supervisor)
        ->post(route('settings.patients.store'), $ordinary)
        ->assertSessionHasErrors(FNCF_CORE_FIELDS);

    // A partial card set is not the paper-card path: the core fields still bind.
    foreach ([
        ['paper_card_reason_code' => 'KARTU_LAMA_PASIEN_LAMA'],
        ['paper_card_year' => '2025', 'paper_card_reason_code' => 'KARTU_LAMA_PASIEN_LAMA'],
        ['paper_card_sequence' => '0012', 'paper_card_reason_code' => 'KARTU_LAMA_PASIEN_LAMA'],
        ['paper_card_year' => '2025', 'paper_card_sequence' => '0012'],
        ['paper_card_reason_note' => 'Catatan kartu yang cukup panjang'],
    ] as $partial) {
        $this->actingAs($supervisor)
            ->post(route('settings.patients.store'), $ordinary + $partial)
            ->assertSessionHasErrors(FNCF_CORE_FIELDS);
    }

    // A complete but invalid card is refused by the paper-card validation itself.
    foreach ([
        [['paper_card_sequence' => '0000'], 'paper_card_sequence'],
        [['paper_card_year' => '2027'], 'paper_card_year'],
        [['paper_card_reason_code' => 'BUKAN_KODE'], 'paper_card_reason_code'],
        [['paper_card_reason_code' => 'LAINNYA'], 'paper_card_reason_note'],
    ] as [$overrides, $errorKey]) {
        $this->actingAs($supervisor)
            ->post(route('settings.patients.store'), fncfPaperCardOnly($overrides))
            ->assertSessionHasErrors($errorKey);
    }

    // A number already held is refused by the claim under the counter lock.
    Patient::factory()->create(['branch_id' => $this->branch->id, 'medical_record_number' => 'DG-FCF1-2025-0099']);

    $this->actingAs($supervisor)
        ->post(route('settings.patients.store'), fncfPaperCardOnly(['paper_card_sequence' => '0099']))
        ->assertSessionHasErrors('paper_card_sequence');

    // Without the paper-card permission the card fields are refused (403).
    $this->actingAs(fncfFrontOffice())
        ->post(route('settings.patients.store'), fncfPaperCardOnly())
        ->assertForbidden();
    $this->actingAs(fncfMasterRegistrar())
        ->post(route('settings.patients.store'), fncfPaperCardOnly())
        ->assertForbidden();

    // Kunjungan → Pasien Baru has no paper-card path: card fields change nothing.
    $visitRegistrar = fncfVisitRegistrar();
    $visitRegistrar->givePermissionTo('register_patient_paper_card_rm');
    $card = ['paper_card_year' => '2025', 'paper_card_sequence' => '0012', 'paper_card_reason_code' => 'KARTU_LAMA_PASIEN_LAMA'];
    $visit = fncfVisitPayload(['name' => 'Pasien Kartu Bebas'] + $card, $card);
    $visit['new_patient'] = array_diff_key($visit['new_patient'], array_flip(FNCF_CORE_FIELDS));

    $this->actingAs($visitRegistrar)
        ->post(route('rme.visits.store'), $visit)
        ->assertSessionHasErrors(array_map(fn (string $field): string => 'new_patient.'.$field, FNCF_CORE_FIELDS));

    expect(Patient::query()->where('name', 'Pasien Kartu Bebas')->exists())->toBeFalse()
        ->and(PatientRmPaperCardRegistration::query()->exists())->toBeFalse();

    // The request decides on the permission pair AND the full card set; the
    // controller routes every card field to the paper-card service path.
    $decides = function (?User $user, array $payload): bool {
        $request = StorePatientRequest::create('/settings/patients', 'POST', $payload);
        $request->setUserResolver(fn () => $user);

        return $request->isPaperCardRegistration();
    };

    expect($decides($supervisor, fncfPaperCardOnly()))->toBeTrue()
        ->and($decides(superAdmin(), fncfPaperCardOnly()))->toBeTrue()
        ->and($decides($supervisor, $ordinary + ['paper_card_year' => '2025', 'paper_card_sequence' => '0012']))->toBeFalse()
        ->and($decides(fncfFrontOffice(), fncfPaperCardOnly()))->toBeFalse()
        ->and($decides(userWith(['register_patient_paper_card_rm']), fncfPaperCardOnly()))->toBeFalse()
        ->and($decides(userWith(['manage patients']), fncfPaperCardOnly()))->toBeFalse()
        ->and($decides(null, fncfPaperCardOnly()))->toBeFalse();
});

it('CF-27 keeps NIK and every value format on the exempt paper-card path, writing nothing on a refusal', function (): void {
    $supervisor = fncfUser('Supervisor RME');

    foreach ([
        [['ktp_number' => '737101170590000'], 'ktp_number'],
        [['ktp_number' => '73710117059000AB'], 'ktp_number'],
        [['date_of_birth' => '2026-06-14'], 'date_of_birth'],
        [['date_of_birth' => '1899-12-31'], 'date_of_birth'],
        [['place_of_birth' => "Makassar\x07"], 'place_of_birth'],
        [['place_of_birth' => str_repeat('M', 101)], 'place_of_birth'],
        [['gender' => 'X'], 'gender'],
        [['occupation' => str_repeat('o', 151)], 'occupation'],
    ] as [$overrides, $errorKey]) {
        $this->actingAs($supervisor)
            ->post(route('settings.patients.store'), fncfPaperCardOnly($overrides))
            ->assertSessionHasErrors($errorKey);
    }

    expect(Patient::query()->where('name', 'Pasien Kartu Bebas')->exists())->toBeFalse();

    // A value that IS given is stored as given.
    $this->actingAs($supervisor)
        ->post(route('settings.patients.store'), fncfPaperCardOnly(['ktp_number' => FNCF_NIK, 'place_of_birth' => 'Maros']))
        ->assertSessionHasNoErrors();

    expect(Patient::query()->where('name', 'Pasien Kartu Bebas')->sole()->only(['ktp_number', 'place_of_birth']))
        ->toBe(['ktp_number' => FNCF_NIK, 'place_of_birth' => 'Maros']);
});

// =============================================================================
// Owner decision 2026-11-17 — the no-phone note is restricted like the
// paper-card reason note (rule 244 §5.3)
// =============================================================================

it('CF-28 shows the no-phone note only to Supervisor RME, Owner and Super Admin on the restricted page (private, no-store); other staff see the reason code only', function (): void {
    $patient = fncfPatientWithNote(fncfFrontOffice());

    // The edit page shows the code (label) to every `update` holder, the
    // readers included, and never the note.
    foreach ([fncfFrontOffice(), fncfUser('Supervisor RME'), superAdmin()] as $editor) {
        $this->actingAs($editor)
            ->get(route('settings.patients.edit', $patient))
            ->assertOk()
            ->assertSee('Terdaftar tanpa nomor telepon')
            ->assertSee('Lainnya (wajib catatan)')
            ->assertDontSee('pendamping panti');
    }

    foreach (['Supervisor RME', 'Owner', 'Super Admin'] as $role) {
        $response = $this->actingAs(fncfUser($role))
            ->get(fncfReasonsUrl($patient))
            ->assertOk()
            ->assertSee(FNCF_NOTE)
            ->assertSee('Lainnya (wajib catatan)');

        expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }

    foreach (['Front Office', 'Admin Klinik', 'Kasir'] as $role) {
        $response = $this->actingAs(fncfUser($role))->get(fncfReasonsUrl($patient))->assertForbidden();

        expect((string) $response->getContent())->not->toContain('pendamping panti');
    }

    // A holder of the read permission outside the patient's branch: 404 (CG-05).
    $elsewhere = Branch::factory()->create(['code' => 'FCF2', 'is_active' => true, 'is_rme_enabled' => true]);
    $outside = fncfUser('Front Office', $elsewhere);
    $outside->givePermissionTo(PatientService::PAPER_CARD_REASON_READ_PERMISSION);

    $this->actingAs($outside->fresh())->get(fncfReasonsUrl($patient))->assertNotFound();

    // The service re-asserts both for a non-HTTP caller.
    expect(fn () => app(PatientService::class)->paperCardRegistrationsFor($patient, fncfFrontOffice()))
        ->toThrow(AuthorizationException::class);
});

it('CF-29 audits each read of the no-phone note with ids only, and no read that was refused', function (): void {
    $patient = fncfPatientWithNote(fncfFrontOffice());
    $owner = fncfUser('Owner');

    $this->actingAs(fncfUser('Kasir'))->get(fncfReasonsUrl($patient))->assertForbidden();
    expect(AuditLog::query()->where('action', PatientService::AUDIT_NO_PHONE_REASON_VIEWED)->exists())->toBeFalse();

    $this->actingAs($owner)->get(fncfReasonsUrl($patient))->assertOk();

    $log = AuditLog::query()->where('action', PatientService::AUDIT_NO_PHONE_REASON_VIEWED)->sole();

    expect($log->new_values)->toEqual(['patient_id' => $patient->id, 'actor_user_id' => $owner->id])
        ->and((int) $log->entity_id)->toBe($patient->id)
        ->and(fncfAuditPayloads())->not->toContain('pendamping panti');

    // A patient without a note: nothing to read, nothing audited.
    $this->actingAs(fncfFrontOffice())
        ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Tanpa Catatan']))
        ->assertSessionHasNoErrors();
    $plain = Patient::query()->where('name', 'Pasien Tanpa Catatan')->sole();
    $this->actingAs($owner)->get(fncfReasonsUrl($plain))->assertOk();

    expect(AuditLog::query()->where('action', PatientService::AUDIT_NO_PHONE_REASON_VIEWED)->count())->toBe(1);
});

it('CF-30 never flashes the no-phone note into the session and never refills it on either form', function (): void {
    $frontOffice = fncfFrontOffice();
    rmeMakeDoctorOnline($this->doctor, $this->branch);
    $exception = ['phone' => '', 'occupation' => '', 'no_phone_reason_code' => 'LAINNYA', 'no_phone_reason_note' => FNCF_NOTE];

    $this->actingAs($frontOffice)
        ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Sesi'] + $exception))
        ->assertSessionHasErrors('occupation');

    expect(json_encode(session()->getOldInput()))->not->toContain('pendamping panti')
        ->and(session()->getOldInput('no_phone_reason_code'))->toBe('LAINNYA');

    $this->actingAs($frontOffice)
        ->post(route('rme.visits.store'), fncfVisitPayload(['name' => 'Pasien Sesi'] + $exception))
        ->assertSessionHasErrors('new_patient.occupation');

    expect(json_encode(session()->getOldInput()))->not->toContain('pendamping panti')
        ->and(session()->getOldInput('new_patient.no_phone_reason_code'))->toBe('LAINNYA');

    // Even an old input that holds a note is never written back into a form.
    $this->actingAs($frontOffice)
        ->withSession(['_old_input' => ['no_phone_reason_code' => 'LAINNYA', 'no_phone_reason_note' => FNCF_NOTE]])
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('name="no_phone_reason_note"', false)
        ->assertDontSee('pendamping panti');

    $this->actingAs($frontOffice)
        ->withSession(['_old_input' => ['patient_mode' => 'new', 'new_patient' => ['no_phone_reason_code' => 'LAINNYA', 'no_phone_reason_note' => FNCF_NOTE]]])
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('name="new_patient[no_phone_reason_note]"', false)
        ->assertDontSee('pendamping panti');

    expect(Patient::query()->where('name', 'Pasien Sesi')->exists())->toBeFalse();
});

it('CF-31 keeps the no-phone note out of every log line and the general patient exports', function (): void {
    $supervisor = fncfUser('Supervisor RME');
    $frontOffice = fncfFrontOffice();
    rmeMakeDoctorOnline($this->doctor, $this->branch);

    $logged = fncfLogLines(function () use ($frontOffice, $supervisor): void {
        $patient = fncfPatientWithNote($frontOffice);

        $this->actingAs($frontOffice)
            ->post(route('rme.visits.store'), fncfVisitPayload([
                'name' => 'Pasien Kunjungan Catatan',
                'phone' => '',
                'no_phone_reason_code' => 'LAINNYA',
                'no_phone_reason_note' => FNCF_NOTE,
            ]))
            ->assertSessionHasNoErrors();

        // Refused (occupation missing), read, and refused a read.
        $this->actingAs($frontOffice)
            ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Ditolak', 'phone' => '', 'occupation' => '', 'no_phone_reason_code' => 'LAINNYA', 'no_phone_reason_note' => FNCF_NOTE]))
            ->assertSessionHasErrors('occupation');
        $this->actingAs($supervisor)->get(fncfReasonsUrl($patient))->assertOk();
        $this->actingAs($frontOffice)->get(fncfReasonsUrl($patient))->assertForbidden();
    });

    expect($logged)->not->toContain('pendamping panti');

    // Both exports stream: read the streamed body, and prove the patients are
    // in it so the absence of the note is not vacuous.
    $masterPatient = Patient::query()->where('name', 'Pasien Catatan Terbatas')->sole();
    $visitPatient = Patient::query()->where('name', 'Pasien Kunjungan Catatan')->sole();

    $report = $this->actingAs($supervisor)->get(route('rme.reports.patients.export'))->assertOk()->streamedContent();
    $audit = $this->actingAs($supervisor)->get(route('rme.patients.audit.export'))->assertOk()->streamedContent();

    expect($report)->toContain($visitPatient->medical_record_number)
        ->not->toContain('pendamping panti')
        ->and($audit)->toContain($masterPatient->medical_record_number)
        ->toContain($visitPatient->medical_record_number)
        ->not->toContain('pendamping panti');
});

it('CF-32 marks every parameter the no-phone note passes through #[\SensitiveParameter], so a logged stack trace never carries it', function (): void {
    // Every application frame the registration payload (note and NIK) passes
    // through. Framework frames (the validator, the router) cannot be marked:
    // there the arrays print as "Array" in a logged trace (rule 244 §5.3).
    foreach ([
        [PatientService::class, 'create', 'data'],
        [PatientService::class, 'createWithPaperCardNumber', 'data'],
        [PatientService::class, 'pullNoPhoneException', 'data'],
        [PatientRepository::class, 'create', 'data'],
        [PatientRepository::class, 'update', 'data'],
        [ClinicVisitService::class, 'create', 'data'],
        [ClinicVisitService::class, 'resolvePatient', 'data'],
        [ClinicVisitService::class, 'resolveBranchId', 'data'],
        [ClinicVisitRegistrationGuard::class, 'resolveDoctor', 'data'],
        [ClinicVisitRegistrationGuard::class, 'assertFollowUpLink', 'data'],
        [ClinicVisitRegistrationGuard::class, 'followUpViolation', 'data'],
        [ClinicVisitRegistrationGuard::class, 'assertPatientSelectable', 'data'],
        [ClinicVisitRegistrationGuard::class, 'isNewPatient', 'data'],
        [PatientCoreFields::class, 'rules', 'input'],
        [PatientCoreFields::class, 'hasNumber', 'fields'],
        // The driver exceptions themselves (their message holds the bindings).
        [PatientRepository::class, 'valueFree', 'e'],
        [PatientService::class, 'valueFreeUniqueViolation', 'e'],
        [PatientService::class, 'refuseUniqueViolation', 'e'],
    ] as [$class, $method, $name]) {
        $parameter = collect((new ReflectionMethod($class, $method))->getParameters())
            ->first(fn (ReflectionParameter $candidate): bool => $candidate->getName() === $name);

        expect($parameter)->not->toBeNull("{$class}::{$method}(\${$name})")
            ->and($parameter->getAttributes(SensitiveParameter::class))->not->toBeEmpty("{$class}::{$method}(\${$name})");
    }

    // The settings a non-production php.ini may carry: traces keep arguments.
    $ignoreArgs = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    try {
        // An accepted exception, then a refused branch: the trace is built
        // after the note entered the registration payload.
        app(PatientService::class)->create(fncfMasterPayload([
            'branch_id' => 999999,
            'phone' => null,
            'no_phone_reason_code' => 'LAINNYA',
            'no_phone_reason_note' => FNCF_NOTE,
        ]), null, fncfFrontOffice());

        $this->fail('The unknown branch must be refused.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('branch_id')
            ->and(fncfTraceArgsContain($e, 'pendamping panti'))->toBeFalse();
    } finally {
        ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
    }
});

// =============================================================================
// RM PR-4b (re-review of 98726f39) — the note and the NIK never reach the log
// through a database error; open exceptions are listed for the readers
// =============================================================================

it('CF-33 answers invalid UTF-8 in a registration with a 422, never a database error, on both forms (re-review M1)', function (): void {
    $frontOffice = fncfFrontOffice();
    rmeMakeDoctorOnline($this->doctor, $this->branch);

    $logged = fncfLogLines(function () use ($frontOffice): void {
        // The reviewer's case: Master Data → Tambah Pasien, invalid bytes in the name.
        $this->actingAs($frontOffice)
            ->post(route('settings.patients.store'), fncfMasterPayload([
                'name' => 'Pasien '.FNCF_BAD_UTF8.' Rusak',
                'phone' => '',
                'ktp_number' => FNCF_NIK,
                'no_phone_reason_code' => 'LAINNYA',
                'no_phone_reason_note' => FNCF_NOTE,
            ]))
            ->assertStatus(302)
            ->assertSessionHasErrors('name');

        // And Kunjungan → Pasien Baru, invalid bytes in the address.
        $this->actingAs($frontOffice)
            ->post(route('rme.visits.store'), fncfVisitPayload([
                'address' => 'Jl. '.FNCF_BAD_UTF8.' Rusak',
                'phone' => '',
                'ktp_number' => FNCF_NIK,
                'no_phone_reason_code' => 'LAINNYA',
                'no_phone_reason_note' => FNCF_NOTE,
            ]))
            ->assertStatus(302)
            ->assertSessionHasErrors('new_patient.address');
    });

    expect(Patient::query()->count())->toBe(0)
        ->and(ClinicVisit::query()->count())->toBe(0)
        ->and($logged)->not->toContain('pendamping panti')
        ->not->toContain(FNCF_NIK_HEAD);
});

it('CF-34 refuses invalid UTF-8 and the NUL byte in every text field either form writes (422), writing nothing', function (): void {
    $frontOffice = fncfFrontOffice();
    rmeMakeDoctorOnline($this->doctor, $this->branch);

    // In the middle of the value: a request trims a NUL at either end.
    $spoil = fn (string $field, string $bad): string => match ($field) {
        'email' => 'pasien'.$bad.'@contoh.test',
        'ktp_number' => substr(FNCF_NIK, 0, 8).$bad.substr(FNCF_NIK, 8),
        default => 'Isi'.$bad.'Teks',
    };

    foreach ([FNCF_BAD_UTF8, "\x00"] as $bad) {
        foreach (['name', 'email', 'phone', 'whatsapp_number', 'address', 'occupation', 'place_of_birth', 'ktp_number', 'duplicate_override_reason'] as $field) {
            $this->actingAs($frontOffice)
                ->post(route('settings.patients.store'), fncfMasterPayload([$field => $spoil($field, $bad)]))
                ->assertStatus(302)
                ->assertSessionHasErrors($field);

            $this->actingAs($frontOffice)
                ->post(route('rme.visits.store'), fncfVisitPayload([$field => $spoil($field, $bad)]))
                ->assertStatus(302)
                ->assertSessionHasErrors('new_patient.'.$field);
        }

        // The visit's own text, written in the same registration.
        foreach (['chief_complaint', 'initial_service_note'] as $field) {
            $this->actingAs($frontOffice)
                ->post(route('rme.visits.store'), fncfVisitPayload([], [$field => $spoil($field, $bad)]))
                ->assertStatus(302)
                ->assertSessionHasErrors($field);
        }
    }

    expect(Patient::query()->count())->toBe(0)
        ->and(ClinicVisit::query()->count())->toBe(0);
});

it('CF-35 turns a database failure on the patient insert into a value-free error: the log holds neither the note, the NIK nor the name (re-review M1)', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: it enforces the column width and the UTF-8 encoding.');
    }

    // The settings a non-production php.ini may carry: traces keep arguments.
    $ignoreArgs = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    try {
        // A configured code wider than its column (40) passes validation and
        // fails the insert (SQLSTATE 22001) with the note and the NIK bound.
        $tooWide = str_repeat('Y', 41);
        config()->set('patient.no_phone_reasons', config('patient.no_phone_reasons') + [$tooWide => 'Kode terlalu panjang']);
        $frontOffice = fncfFrontOffice();
        rmeMakeDoctorOnline($this->doctor, $this->branch);
        $exception = ['name' => 'Pasien Gagal Simpan', 'phone' => '', 'ktp_number' => FNCF_NIK, 'no_phone_reason_code' => $tooWide, 'no_phone_reason_note' => FNCF_NOTE];

        $masterLog = fncfLogLines(fn () => $this->actingAs($frontOffice)->post(route('settings.patients.store'), fncfMasterPayload($exception))->assertStatus(500));
        $visitLog = fncfLogLines(fn () => $this->actingAs($frontOffice)->post(route('rme.visits.store'), fncfVisitPayload($exception))->assertStatus(500));

        foreach ([$masterLog, $visitLog] as $logged) {
            expect($logged)->toContain('The patient could not be stored (SQLSTATE 22001).')
                ->not->toContain('pendamping panti')
                ->not->toContain(FNCF_NIK_HEAD)
                ->not->toContain('Gagal Simpan');
        }

        // The validation bypassed: invalid UTF-8 reaches the insert (SQLSTATE 22021).
        try {
            app(PatientService::class)->create(fncfMasterPayload([
                'name' => 'Pasien '.FNCF_BAD_UTF8.' Langsung',
                'phone' => null,
                'ktp_number' => FNCF_NIK,
                'no_phone_reason_code' => 'LAINNYA',
                'no_phone_reason_note' => FNCF_NOTE,
            ]), null, $frontOffice);

            $this->fail('The invalid bytes must be refused by the database.');
        } catch (RuntimeException $e) {
            $reported = fncfLogLines(fn () => report($e));

            expect(get_class($e))->toBe(RuntimeException::class)
                ->and($e->getPrevious())->toBeNull()
                ->and($e->getMessage())->toBe('The patient could not be stored (SQLSTATE 22021).')
                ->and($reported)->toContain('SQLSTATE 22021')
                ->not->toContain('pendamping panti')
                ->not->toContain(FNCF_NIK_HEAD)
                ->not->toContain('Langsung');
        }
    } finally {
        ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
    }

    expect(Patient::query()->count())->toBe(0);
});

it('CF-36 keeps a failed patient update value-free too: an edited NIK never reaches the log (re-review M1)', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: it enforces the UTF-8 encoding.');
    }

    $patient = fncfPatientWithNote(fncfFrontOffice());

    try {
        app(PatientService::class)->update($patient, ['name' => 'Nama '.FNCF_BAD_UTF8.' Ubah', 'ktp_number' => FNCF_NIK]);

        $this->fail('The invalid bytes must be refused by the database.');
    } catch (RuntimeException $e) {
        $reported = fncfLogLines(fn () => report($e));

        expect(get_class($e))->toBe(RuntimeException::class)
            ->and($e->getPrevious())->toBeNull()
            ->and($e->getMessage())->toBe('The patient could not be updated (SQLSTATE 22021).')
            ->and($reported)->not->toContain(FNCF_NIK_HEAD)
            ->not->toContain('Ubah');
    }

    expect($patient->fresh()->ktp_number)->toBeNull();
});

it('CF-37 lists the open no-phone exceptions for the readers on the restricted index — never the note; others 403; another branch hidden (re-review L2)', function (): void {
    $open = fncfPatientWithNote(fncfFrontOffice());

    $elsewhere = Branch::factory()->create(['code' => 'FCF2', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->actingAs(fncfUser('Front Office', $elsewhere))
        ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Cabang Lain', 'branch_id' => $elsewhere->id, 'phone' => '', 'no_phone_reason_code' => 'TIDAK_PUNYA_TELEPON']))
        ->assertSessionHasNoErrors();
    $other = Patient::query()->where('name', 'Pasien Cabang Lain')->sole();

    // A closed exception is no longer listed.
    $closed = fncfPatientWithNote(fncfFrontOffice(), 'Pasien Sudah Bernomor');
    app(PatientService::class)->update($closed, ['phone' => '081234500099'], fncfFrontOffice());
    expect($closed->fresh()->no_phone_reason_code)->toBeNull();

    // The registrations' success toasts name their numbers: not part of the list.
    $this->flushSession();

    $response = $this->actingAs(fncfUser('Owner'))
        ->get(route('settings.patients.paper-card-registrations.index'))
        ->assertOk()
        ->assertSee('Pengecualian tanpa nomor telepon')
        ->assertSee($open->medical_record_number)
        ->assertSee($other->medical_record_number)
        ->assertSee('Lainnya (wajib catatan)')
        ->assertSee(fncfReasonsUrl($open), false)
        ->assertDontSee($closed->medical_record_number)
        ->assertDontSee('pendamping panti');

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    // A reader whose scope is the other branch sees that branch only (CG-05).
    $reader = fncfUser('Front Office', $elsewhere);
    $reader->givePermissionTo(PatientService::PAPER_CARD_REASON_READ_PERMISSION);

    $this->actingAs($reader->fresh())
        ->get(route('settings.patients.paper-card-registrations.index'))
        ->assertOk()
        ->assertSee($other->medical_record_number)
        ->assertDontSee($open->medical_record_number);

    foreach (['Front Office', 'Admin Klinik', 'Kasir'] as $role) {
        $this->actingAs(fncfUser($role))->get(route('settings.patients.paper-card-registrations.index'))->assertForbidden();
    }

    expect(fn () => app(PatientService::class)->openNoPhoneExceptionList(fncfFrontOffice()))
        ->toThrow(AuthorizationException::class)
        ->and(app(PatientService::class)->openNoPhoneExceptionList(fncfUser('Owner'))->pluck('id')->all())
        ->toEqualCanonicalizing([$open->id, $other->id]);
});

it('CF-38 shows and audits a note only with an open exception: a stray note without a reason code is neither shown nor audited', function (): void {
    $this->actingAs(fncfFrontOffice())
        ->post(route('settings.patients.store'), fncfMasterPayload(['name' => 'Pasien Catatan Liar']))
        ->assertSessionHasNoErrors();
    $patient = Patient::query()->where('name', 'Pasien Catatan Liar')->sole();

    // Only PatientService writes these columns; a direct write stands for any drift.
    DB::table('mst_patients')->where('id', $patient->id)->update(['no_phone_reason_note' => 'Catatan liar tanpa kode xq9z']);

    $this->actingAs(fncfUser('Owner'))
        ->get(fncfReasonsUrl($patient))
        ->assertOk()
        ->assertDontSee('xq9z');

    expect(AuditLog::query()->where('action', PatientService::AUDIT_NO_PHONE_REASON_VIEWED)->exists())->toBeFalse();
});

it('CF-39 refuses invalid UTF-8 and the NUL byte in every text field an edit writes (422, never a 500), while an unchanged legacy value still saves', function (): void {
    // Legacy text PostgreSQL stores: a short NIK, a tab, a line feed and a BEL.
    $legacy = Patient::factory()->create([
        'branch_id' => $this->branch->id,
        'medical_record_number' => 'DG-FCF1-2026-0077',
        'name' => "Pasien Lama\tTab",
        'address' => "Jl. Lama\nBaris Dua",
        'occupation' => "Petani\x07",
        'phone' => '0812 3456',
        'whatsapp_number' => null,
        'email' => null,
        'place_of_birth' => null,
        'ktp_number' => '7371011705',
    ]);
    $registrar = fncfMasterRegistrar();
    $unchanged = [
        'clinic_id' => $this->clinic->id,
        'doctor_id' => $this->doctor->id,
        'name' => $legacy->name,
        'address' => $legacy->address,
        'occupation' => $legacy->occupation,
        'phone' => $legacy->phone,
        'ktp_number' => $legacy->ktp_number,
        'is_active' => 1,
    ];

    // In the middle of the value: a request trims a NUL at either end.
    $spoil = fn (string $field, string $bad): string => match ($field) {
        'email' => 'pasien'.$bad.'@contoh.test',
        'ktp_number' => substr(FNCF_NIK, 0, 8).$bad.substr(FNCF_NIK, 8),
        default => 'Isi'.$bad.'Teks',
    };

    foreach ([FNCF_BAD_UTF8, "\x00"] as $bad) {
        foreach (['name', 'email', 'phone', 'whatsapp_number', 'address', 'occupation', 'place_of_birth', 'ktp_number'] as $field) {
            $this->actingAs($registrar)
                ->put(route('settings.patients.update', $legacy), array_merge($unchanged, [$field => $spoil($field, $bad)]))
                ->assertStatus(302)
                ->assertSessionHasErrors($field);
        }
    }

    expect($legacy->fresh()->only(['name', 'address', 'occupation', 'phone', 'ktp_number']))
        ->toBe(['name' => "Pasien Lama\tTab", 'address' => "Jl. Lama\nBaris Dua", 'occupation' => "Petani\x07", 'phone' => '0812 3456', 'ktp_number' => '7371011705']);

    // The unchanged legacy values themselves still save.
    $this->actingAs($registrar)
        ->put(route('settings.patients.update', $legacy), $unchanged)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.patients.index'));

    expect($legacy->fresh()->occupation)->toBe("Petani\x07");
});

it('CF-40 answers an edit that collides on another patient\'s NIK in the database with the registration\'s 422, changing nothing and logging no NIK (re-review L1)', function (): void {
    $holder = Patient::factory()->create(['branch_id' => $this->branch->id, 'ktp_number' => '7371011705909999']);
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id, 'ktp_number' => null, 'name' => 'Pasien Edit NIK']);

    // A caller that bypasses UpdatePatientRequest: the database refuses it.
    $logged = fncfLogLines(function () use ($patient): void {
        try {
            app(PatientService::class)->update($patient, ['ktp_number' => '7371011705909999']);

            $this->fail('The NIK collision must be refused.');
        } catch (ValidationException $e) {
            expect(array_keys($e->errors()))->toBe(['ktp_number'])
                ->and($e->errors()['ktp_number'][0])->toBe('Nomor KTP sudah terdaftar pada pasien lain.');
        }
    });

    // The race the form cannot see: another patient takes the NIK between the
    // request's unique rule and the UPDATE.
    Patient::updating(function (Patient $updating) use ($holder): void {
        if ($updating->ktp_number === FNCF_NIK) {
            DB::table('mst_patients')->where('id', $holder->id)->update(['ktp_number' => FNCF_NIK]);
        }
    });

    $logged .= fncfLogLines(function () use ($patient): void {
        $this->actingAs(fncfMasterRegistrar())
            ->put(route('settings.patients.update', $patient), [
                'clinic_id' => $this->clinic->id,
                'doctor_id' => $this->doctor->id,
                'name' => 'Pasien Edit NIK Baru',
                'ktp_number' => FNCF_NIK,
                'is_active' => 1,
            ])
            ->assertStatus(302)
            ->assertSessionHasErrors('ktp_number');
    });

    // Nothing changed (the race's own write rolls back with the refused edit here).
    expect($patient->fresh()->only(['name', 'ktp_number']))->toBe(['name' => 'Pasien Edit NIK', 'ktp_number' => null])
        ->and($logged)->not->toContain(FNCF_NIK_HEAD);

    // Any other unique violation on an edit is refused without a value.
    app()->instance(PatientRepositoryInterface::class, new class extends PatientRepository
    {
        public function __construct() {}

        public function update(Patient $patient, array $data): Patient
        {
            $pdo = new PDOException('duplicate key');
            $pdo->errorInfo = ['23505', 7, 'ERROR:  duplicate key value violates unique constraint "mst_patients_other_unique"'
                ."\nDETAIL:  Key (phone)=(0819 9988 7766) already exists."];

            throw new UniqueConstraintViolationException('pgsql', 'update mst_patients set phone = ?', ['0819 9988 7766'], $pdo);
        }
    });

    try {
        app(PatientService::class)->update($patient->fresh(), ['phone' => '0819 9988 7766']);

        $this->fail('The violation must be refused.');
    } catch (RuntimeException $e) {
        expect(get_class($e))->toBe(RuntimeException::class)
            ->and($e->getPrevious())->toBeNull()
            ->and($e->getMessage())->toContain('mst_patients_other_unique')
            ->toContain('SQLSTATE 23505')
            ->not->toContain('9988');
    }
});

it('CF-41 keeps every configured reason code within its database column, so a misconfigured code fails CI rather than every registration', function (): void {
    $widths = [
        'patient.no_phone_reasons' => ['mst_patients', 'no_phone_reason_code'],
        'patient.paper_card_reasons' => ['trx_patient_rm_paper_card_registrations', 'reason_code'],
    ];

    foreach ($widths as $key => [$table, $column]) {
        $reasons = config($key);

        expect($reasons)->toBeArray()->not->toBeEmpty();

        // The column's own width where the database enforces one (CI: PostgreSQL), else the migrations' 40.
        $width = DB::connection()->getDriverName() === 'pgsql'
            ? (int) DB::table('information_schema.columns')
                ->where('table_schema', DB::raw('current_schema()'))
                ->where('table_name', $table)
                ->where('column_name', $column)
                ->value('character_maximum_length')
            : 40;

        expect($width)->toBe(40, "{$table}.{$column}");

        foreach (array_keys($reasons) as $code) {
            expect($code)->toBeString()
                ->and(mb_strlen((string) $code))->toBeGreaterThan(0)->toBeLessThanOrEqual($width, "{$key}: {$code}");
        }
    }
});
