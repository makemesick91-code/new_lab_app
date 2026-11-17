<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §4.3, PR-3b) — the
| paper-card reason, stored and restricted
|--------------------------------------------------------------------------
|
| Owner decision 2026-11-17 "PAPER-CARD REASON: store text, restricted": the
| paper-card exception carries a STRUCTURED reason code (config-driven,
| Indonesian labels) plus a bounded note (required only for LAINNYA, 10–500
| characters when given). It is stored in its own additive, immutable table
| (`trx_patient_rm_paper_card_registrations`) in the same transaction as the
| claim. Whoever holds `register_patient_paper_card_rm` enters it; only
| `view_patient_rm_paper_card_reasons` (Supervisor RME, Owner; Super Admin via
| Gate::before) reads it, through a branch-scoped, `private, no-store` surface
| (out of scope 404, unpermitted 403). The note never reaches a log line, an
| audit payload (ids + reason_code only), a general export or the session's
| flashed input.
|
| Selected by the existing `FrontOffice` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `fprr` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Patient\Interfaces\PatientRmPaperCardRegistrationRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientRmPaperCardRegistration;
use App\Modules\Patient\Models\PatientRmSequence;
use App\Modules\Patient\Services\PatientService;
use App\Modules\PatientMerge\Support\PatientOwnedRecordRegistry;
use Database\Seeders\BranchSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

const FPRR_READ = 'view_patient_rm_paper_card_reasons';

/** A note an operator could write; unique enough to search every sink for it. */
const FPRR_NOTE = 'Catatan uji alasan kartu lama zx81q kertas lusuh';

beforeEach(function (): void {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');
    pinTestClock('2026-06-13 01:00:00'); // 09:00 WITA, clinical year 2026

    Branch::query()->where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    $this->clinic = Clinic::factory()->create();
    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->sunu = Branch::factory()->create(['code' => 'SPN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->landak = Branch::factory()->create(['code' => 'LDK2', 'name' => 'Cabang Landak', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->supervisor = User::factory()->create();
    $this->supervisor->assignRole('Supervisor RME');
});

afterEach(fn () => freeTestClock());

function fprrPayload(array $overrides = []): array
{
    return array_merge([
        'clinic_id' => test()->clinic->id,
        'doctor_id' => test()->doctor->id,
        'branch_id' => test()->sunu->id,
        'name' => 'Pasien Alasan '.Str::random(5),
        'gender' => 'Female',
        'is_active' => 1,
        'paper_card_year' => '2025',
        'paper_card_sequence' => '0012',
        'paper_card_reason_code' => 'LAINNYA',
        'paper_card_reason_note' => FPRR_NOTE,
    ], $overrides);
}

/** The paper-card registration through the real service. */
function fprrRegister(User $actor, array $overrides = []): Patient
{
    $payload = fprrPayload($overrides);

    return app(PatientService::class)->createWithPaperCardNumber(
        array_diff_key($payload, array_flip(PatientService::PAPER_CARD_FIELDS)),
        $actor,
        (int) $payload['paper_card_year'],
        (string) $payload['paper_card_sequence'],
        (string) $payload['paper_card_reason_code'],
        $payload['paper_card_reason_note'],
    );
}

/** An account in `$role`; the context-bound desk roles work at `$branch` (default SPN4). */
function fprrUser(string $role, ?Branch $branch = null): User
{
    $user = User::factory()->create();
    $branch ??= test()->sunu;

    match ($role) {
        'Front Office' => rmeMakeFrontOfficeActive($user, $branch),
        'Admin Klinik' => rmeMakeAdminClinicActive($user, $branch),
        'Kasir' => rmeMakeKasirActive($user, $branch),
        'Perawat' => rmeMakePerawatActive($user, $branch),
        default => $user->assignRole($role),
    };

    return $user;
}

function fprrShowUrl(Patient $patient): string
{
    return route('settings.patients.paper-card-registrations.show', $patient);
}

// =============================================================================
// Entering the reason (whoever holds register_patient_paper_card_rm)
// =============================================================================

it('PR-01 stores the structured reason and the note in its own row, in the same transaction as the claim', function (): void {
    $this->actingAs($this->supervisor)
        ->post(route('settings.patients.store'), fprrPayload(['name' => 'Pasien Kartu Beralasan']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.patients.index'));

    $patient = Patient::query()->where('name', 'Pasien Kartu Beralasan')->sole();
    $row = PatientRmPaperCardRegistration::query()->sole();

    expect((int) $row->patient_id)->toBe((int) $patient->id)
        ->and((int) $row->branch_id)->toBe((int) $this->sunu->id)
        ->and((int) $row->year)->toBe(2025)
        ->and($row->reason_code)->toBe('LAINNYA')
        ->and($row->reason_note)->toBe(FPRR_NOTE)
        ->and((int) $row->actor_user_id)->toBe((int) $this->supervisor->id)
        ->and($row->created_at)->not->toBeNull();
});

it('PR-02 keeps the note optional for a listed reason, and stores none when it is blank', function (): void {
    fprrRegister($this->supervisor, ['paper_card_reason_code' => 'KARTU_LAMA_PASIEN_LAMA', 'paper_card_reason_note' => '   ']);

    $row = PatientRmPaperCardRegistration::query()->sole();

    expect($row->reason_code)->toBe('KARTU_LAMA_PASIEN_LAMA')
        ->and($row->reason_note)->toBeNull();
});

it('PR-03 requires a note for LAINNYA and bounds any note to 10–500 characters, binding Super Admin too', function (?string $note, bool $accepted): void {
    $caught = null;

    try {
        fprrRegister(superAdmin(), ['paper_card_reason_note' => $note]);
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught === null)->toBe($accepted);

    if (! $accepted) {
        expect($caught->errors())->toHaveKey('paper_card_reason_note')
            ->and(Patient::query()->count())->toBe(0)
            ->and(PatientRmPaperCardRegistration::query()->count())->toBe(0)
            ->and(PatientRmSequence::query()->count())->toBe(0);
    }
})->with([
    'missing for LAINNYA' => [null, false],
    'nine characters' => [str_repeat('a', 9), false],
    'ten characters' => [str_repeat('a', 10), true],
    'five hundred' => [str_repeat('a', 500), true],
    'five hundred and one' => [str_repeat('a', 501), false],
]);

it('PR-04 refuses a reason code that is not configured, over HTTP and in the service', function (): void {
    $this->actingAs($this->supervisor)
        ->post(route('settings.patients.store'), fprrPayload(['name' => 'Pasien Kode Liar', 'paper_card_reason_code' => 'BEBAS']))
        ->assertSessionHasErrors('paper_card_reason_code');

    expect(fn () => fprrRegister($this->supervisor, ['paper_card_reason_code' => 'BEBAS']))->toThrow(ValidationException::class)
        ->and(Patient::query()->count())->toBe(0)
        ->and(PatientRmPaperCardRegistration::query()->count())->toBe(0);
});

it('PR-05 requires a reason code with the card number (the free-text field is gone)', function (): void {
    $payload = fprrPayload(['name' => 'Pasien Tanpa Kode']);
    unset($payload['paper_card_reason_code'], $payload['paper_card_reason_note']);
    $payload['paper_card_reason'] = 'Alasan bebas lama yang tidak lagi diterima.';

    $this->actingAs($this->supervisor)
        ->post(route('settings.patients.store'), $payload)
        ->assertSessionHasErrors('paper_card_reason_code');

    expect(Patient::query()->where('name', 'Pasien Tanpa Kode')->exists())->toBeFalse();
});

it('PR-06 writes no reason row when the claim is refused (taken, or past the claim cap)', function (): void {
    Patient::factory()->create(['branch_id' => $this->sunu->id, 'medical_record_number' => 'DG-SPN4-2025-12']);

    expect(fn () => fprrRegister($this->supervisor))->toThrow(ValidationException::class)
        ->and(fn () => fprrRegister($this->supervisor, ['paper_card_year' => '2026', 'paper_card_sequence' => '5000']))->toThrow(ValidationException::class)
        ->and(PatientRmPaperCardRegistration::query()->count())->toBe(0);
});

it('PR-07 refuses the reason fields from every account without the paper-card permission (403)', function (string $role): void {
    $this->actingAs(fprrUser($role))
        ->post(route('settings.patients.store'), [
            'clinic_id' => $this->clinic->id, 'doctor_id' => $this->doctor->id, 'branch_id' => $this->sunu->id,
            'name' => 'Pasien Kode Saja', 'gender' => 'Female', 'is_active' => 1,
            'paper_card_reason_code' => 'KARTU_LAMA_PASIEN_LAMA',
        ])
        ->assertForbidden();

    expect(Patient::query()->where('name', 'Pasien Kode Saja')->exists())->toBeFalse()
        ->and(PatientRmPaperCardRegistration::query()->count())->toBe(0);
})->with(['Front Office', 'Admin Klinik', 'Kasir']);

it('PR-08 renders the reason list, the note and the privacy hint only for the paper-card permission', function (): void {
    $this->actingAs($this->supervisor)
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('name="paper_card_reason_code"', false)
        ->assertSee('value="KARTU_LAMA_PASIEN_LAMA"', false)
        ->assertSee('value="MIGRASI_ARSIP_KERTAS"', false)
        ->assertSee('value="LAINNYA"', false)
        ->assertSee('name="paper_card_reason_note"', false)
        ->assertSee('Jangan menulis data identitas pasien');

    $this->actingAs(fprrUser('Front Office'))
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertDontSee('name="paper_card_reason_code"', false)
        ->assertDontSee('name="paper_card_reason_note"', false);
});

// =============================================================================
// Reading the reason (Supervisor RME, Owner; Super Admin via Gate::before)
// =============================================================================

it('PR-09 lets Supervisor RME, Owner and Super Admin read the patient\'s reasons, private and no-store', function (string $role): void {
    $patient = fprrRegister($this->supervisor);

    $response = $this->actingAs(fprrUser($role))->get(fprrShowUrl($patient))
        ->assertOk()
        ->assertSee(FPRR_NOTE)
        ->assertSee('Lainnya');

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('no-store')
        ->and($cacheControl)->toContain('private');
})->with(['Supervisor RME', 'Owner', 'Super Admin']);

it('PR-10 refuses every other account with 403, whatever else it may do with patients', function (string $role): void {
    $patient = fprrRegister($this->supervisor);

    $response = $this->actingAs(fprrUser($role))->get(fprrShowUrl($patient));

    if ($role === 'Doctor') {
        // The doctor access gate turns a doctor account away before the route.
        expect($response->status())->toBeIn([302, 403]);
    } else {
        $response->assertForbidden();
    }

    expect($response->getContent())->not->toContain(FPRR_NOTE);
})->with(['Front Office', 'Admin Klinik', 'Kasir', 'Perawat', 'Kepala Cabang', 'Doctor']);

it('PR-11 answers 404 to a holder outside the patient\'s branch, and lists only in-scope rows (CG-05)', function (): void {
    $patient = fprrRegister($this->supervisor);

    // A context-bound desk account explicitly granted the read permission.
    $atLandak = fprrUser('Front Office', $this->landak);
    $atLandak->givePermissionTo(FPRR_READ);
    $atSunu = fprrUser('Front Office', $this->sunu);
    $atSunu->givePermissionTo(FPRR_READ);

    $this->actingAs($atLandak)->get(fprrShowUrl($patient))->assertNotFound();
    $this->actingAs($atSunu)->get(fprrShowUrl($patient))->assertOk()->assertSee(FPRR_NOTE);

    $this->actingAs($atLandak)->get(route('settings.patients.paper-card-registrations.index'))
        ->assertOk()
        ->assertDontSee($patient->medical_record_number);
    $this->actingAs($atSunu)->get(route('settings.patients.paper-card-registrations.index'))
        ->assertOk()
        ->assertSee($patient->medical_record_number);
});

it('PR-12 re-asserts the permission (403) and the branch scope (404) in the service', function (): void {
    $patient = fprrRegister($this->supervisor);
    $service = app(PatientService::class);
    $atLandak = fprrUser('Front Office', $this->landak);
    $atLandak->givePermissionTo(FPRR_READ);

    expect(fn () => $service->paperCardRegistrationsFor($patient, fprrUser('Front Office')))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->paperCardRegistrationsFor($patient, $atLandak))->toThrow(NotFoundHttpException::class)
        ->and($service->paperCardRegistrationsFor($patient, userInRole('Owner'))->pluck('reason_note')->all())->toBe([FPRR_NOTE]);
});

it('PR-13 lists registrations for the readers without any note, private and no-store; others get 403', function (): void {
    $patient = fprrRegister($this->supervisor);

    $response = $this->actingAs(userInRole('Owner'))
        ->get(route('settings.patients.paper-card-registrations.index'))
        ->assertOk()
        ->assertSee($patient->medical_record_number)
        ->assertSee('Lainnya')
        ->assertDontSee(FPRR_NOTE);

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');

    $this->actingAs(fprrUser('Admin Klinik'))
        ->get(route('settings.patients.paper-card-registrations.index'))
        ->assertForbidden();
});

it('PR-14 seeds the read permission for Supervisor RME and Owner only (Super Admin through the wildcard)', function (): void {
    expect(PermissionSeeder::PERMISSIONS)->toContain(FPRR_READ)
        ->and(RoleSeeder::ROLE_PERMISSIONS['Supervisor RME'])->toContain(FPRR_READ)
        ->and(RoleSeeder::ROLE_PERMISSIONS['Owner'])->toContain(FPRR_READ)
        ->and(RoleSeeder::ROLE_PERMISSIONS['Owner'])->not->toContain('register_patient_paper_card_rm')
        ->and(RoleSeeder::ROLE_PERMISSIONS['Super Admin'])->toBe('*');

    $holders = Role::query()->get()->filter(fn (Role $role): bool => $role->hasPermissionTo(FPRR_READ))->pluck('name')->sort()->values()->all();

    expect($holders)->toBe(['Owner', 'Super Admin', 'Supervisor RME']);
});

// =============================================================================
// Retention: immutable for the life of the patient record
// =============================================================================

it('PR-15 keeps every row immutable: no update or delete through the model, none offered by the repository', function (): void {
    $patient = fprrRegister($this->supervisor);
    $row = PatientRmPaperCardRegistration::query()->sole();

    expect(fn () => $row->forceFill(['reason_note' => 'Diubah setelah tersimpan.'])->save())->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);

    $methods = array_map(fn (ReflectionMethod $m): string => strtolower($m->getName()), (new ReflectionClass(PatientRmPaperCardRegistrationRepositoryInterface::class))->getMethods());

    expect(array_filter($methods, fn (string $name): bool => str_contains($name, 'update') || str_contains($name, 'delete')))->toBe([])
        ->and($row->refresh()->reason_note)->toBe(FPRR_NOTE);

    // A soft-deleted patient keeps its reason (rule 169: it keeps its number too).
    $patient->delete();

    expect(PatientRmPaperCardRegistration::query()->count())->toBe(1);
});

// =============================================================================
// Privacy: the note stays in its table and on the restricted page
// =============================================================================

it('PR-16 audits ids and the reason code only, never the note or the number', function (): void {
    $patient = fprrRegister($this->supervisor);
    $row = PatientRmPaperCardRegistration::query()->sole();

    $log = AuditLog::query()->where('action', PatientService::AUDIT_PAPER_CARD_REGISTERED)->sole();

    expect(array_keys($log->new_values))->toEqualCanonicalizing(['patient_id', 'branch_id', 'actor_user_id', 'registration_id', 'reason_code'])
        ->and($log->new_values['reason_code'])->toBe('LAINNYA')
        ->and((int) $log->new_values['registration_id'])->toBe((int) $row->id);

    $everyPayload = AuditLog::query()->get()->map(fn (AuditLog $a): string => json_encode([$a->old_values, $a->new_values]))->implode(' ');

    expect($everyPayload)->not->toContain('zx81q')
        ->and($everyPayload)->not->toContain($patient->medical_record_number);
});

it('PR-17 never writes the note to a log line, on success, on refusal or on reading', function (): void {
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$lines): void {
        $lines[] = $event->message.' '.json_encode($event->context);
    });

    $patient = fprrRegister($this->supervisor);

    try {
        fprrRegister($this->supervisor, ['name' => 'Pasien Ditolak Log']); // taken: refused
    } catch (ValidationException) {
    }

    $this->actingAs(userInRole('Owner'))->get(fprrShowUrl($patient))->assertOk();
    $this->actingAs(fprrUser('Front Office'))->get(fprrShowUrl($patient))->assertForbidden();

    expect(implode("\n", $lines))->not->toContain('zx81q');
});

it('PR-18 keeps the note out of the general patient exports and the patient edit page', function (): void {
    $patient = fprrRegister($this->supervisor);
    // The patient report lists visits: give the card patient one today so its
    // row is in the export.
    ClinicVisit::factory()->create([
        'branch_id' => $this->sunu->id,
        'patient_id' => $patient->id,
        'doctor_id' => $this->doctor->id,
        'visit_date' => '2026-06-13',
    ]);

    // Both exports stream: assertDontSee() on a streamed response reads an empty
    // body. Read the streamed body and prove the patient is in it, so the
    // absence of the note is not vacuous.
    $report = $this->actingAs($this->supervisor)
        ->get(route('rme.reports.patients.export'))
        ->assertOk()
        ->streamedContent();
    $audit = $this->actingAs($this->supervisor)
        ->get(route('rme.patients.audit.export'))
        ->assertOk()
        ->streamedContent();

    expect($report)->toContain((string) $patient->medical_record_number)
        ->not->toContain('zx81q')
        ->and($audit)->toContain((string) $patient->medical_record_number)
        ->not->toContain('zx81q');

    $this->actingAs($this->supervisor)
        ->get(route('settings.patients.edit', $patient))
        ->assertOk()
        ->assertDontSee('zx81q')
        ->assertSee(fprrShowUrl($patient), false);

    // Without the read permission the edit page does not even link to it.
    $this->actingAs(fprrUser('Admin Klinik'))
        ->get(route('settings.patients.edit', $patient))
        ->assertOk()
        ->assertDontSee(fprrShowUrl($patient), false);
});

it('PR-19 never flashes the note into the session when the form is refused', function (): void {
    Patient::factory()->create(['branch_id' => $this->sunu->id, 'medical_record_number' => 'DG-SPN4-2025-0012']);

    $this->actingAs($this->supervisor)
        ->post(route('settings.patients.store'), fprrPayload(['name' => 'Pasien Sesi']))
        ->assertSessionHasErrors('paper_card_sequence');

    expect(json_encode(session()->getOldInput()))->not->toContain('zx81q')
        ->and(session()->getOldInput('paper_card_reason_code'))->toBe('LAINNYA');
});

it('PR-20 creates the table additively with named foreign keys, no updated_at, preserved by a merge', function (): void {
    expect(DB::getSchemaBuilder()->hasTable('trx_patient_rm_paper_card_registrations'))->toBeTrue()
        ->and(DB::getSchemaBuilder()->hasColumns('trx_patient_rm_paper_card_registrations', [
            'id', 'patient_id', 'branch_id', 'year', 'reason_code', 'reason_note', 'actor_user_id', 'created_at',
        ]))->toBeTrue()
        ->and(DB::getSchemaBuilder()->hasColumn('trx_patient_rm_paper_card_registrations', 'updated_at'))->toBeFalse()
        // A patient merge leaves the row on the patient it was registered for.
        ->and(PatientOwnedRecordRegistry::PRESERVE)->toHaveKey('trx_patient_rm_paper_card_registrations.patient_id');

    if (DB::connection()->getDriverName() === 'pgsql') {
        $names = collect(DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'trx_patient_rm_paper_card_registrations'::regclass AND contype = 'f'"
        ))->pluck('conname')->sort()->values()->all();

        expect($names)->toBe(['trx_prpcr_actor_fk', 'trx_prpcr_branch_fk', 'trx_prpcr_patient_fk']);
    }
});

// =============================================================================
// Review of f98c2c0e: B1 (the note in laravel.log), B3, B4, B5, B8
// =============================================================================

/** Every log line written while `$action` runs: message, context and the exception as it would be printed. */
function fprrLogLines(callable $action): string
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

it('PR-21 refuses a note with invalid UTF-8 or control characters over HTTP (422), writing nothing and logging nothing (review B1)', function (string $note): void {
    $logged = fprrLogLines(function () use ($note): void {
        $this->actingAs($this->supervisor)
            ->post(route('settings.patients.store'), fprrPayload(['name' => 'Pasien Catatan Rusak', 'paper_card_reason_note' => $note]))
            ->assertSessionHasErrors('paper_card_reason_note');
    });

    expect(Patient::query()->where('name', 'Pasien Catatan Rusak')->exists())->toBeFalse()
        ->and(PatientRmPaperCardRegistration::query()->count())->toBe(0)
        ->and($logged)->not->toContain('zx81q');
})->with([
    'invalid UTF-8' => ["Catatan uji zx81q rusak \xff\xfe kertas"],
    'NUL byte' => ["Catatan uji zx81q nol \0 kertas lama"],
    'bell control character' => ["Catatan uji zx81q bel \x07 kertas lama"],
]);

it('PR-22 refuses the same notes in the service, binding Super Admin (review B1)', function (string $note): void {
    expect(fn () => fprrRegister(superAdmin(), ['paper_card_reason_note' => $note]))
        ->toThrow(ValidationException::class);

    expect(PatientRmPaperCardRegistration::query()->count())->toBe(0);
})->with([
    'invalid UTF-8' => ["Catatan uji zx81q rusak \xff\xfe kertas"],
    'NUL byte' => ["Catatan uji zx81q nol \0 kertas lama"],
]);

it('PR-23 turns a database failure on the insert into an error that carries no value and no driver exception (review B1)', function (): void {
    $caught = null;

    try {
        DB::transaction(fn () => app(PatientRmPaperCardRegistrationRepositoryInterface::class)->record([
            'patient_id' => 987654321, // no such patient: the foreign key refuses the insert
            'branch_id' => (int) $this->sunu->id,
            'year' => 2025,
            'reason_code' => 'LAINNYA',
            'reason_note' => FPRR_NOTE,
            'actor_user_id' => (int) $this->supervisor->id,
        ]));
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and($caught)->not->toBeInstanceOf(QueryException::class)
        ->and($caught->getPrevious())->toBeNull()
        ->and((string) $caught)->not->toContain('zx81q');
});

it('PR-24 answers a failed insert with a 500 whose log line never contains the note (PostgreSQL; review B1)', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs a database that enforces the reason_code column width.');
    }

    // A configured code wider than the column (40): only the database refuses it.
    $tooWide = str_repeat('X', 41);
    config()->set('patient.paper_card_reasons', config('patient.paper_card_reasons') + [$tooWide => 'Kode terlalu panjang']);

    $logged = fprrLogLines(function () use ($tooWide): void {
        $this->actingAs($this->supervisor)
            ->post(route('settings.patients.store'), fprrPayload(['name' => 'Pasien Gagal Simpan', 'paper_card_reason_code' => $tooWide]))
            ->assertStatus(500);
    });

    expect($logged)->toContain('could not be stored')
        ->and($logged)->not->toContain('zx81q')
        ->and(Patient::query()->where('name', 'Pasien Gagal Simpan')->exists())->toBeFalse();
});

it('PR-24b keeps the note out of a logged stack trace even when PHP keeps call arguments (re-review F1)', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs a database that enforces the reason_code column width.');
    }

    // The settings a non-production php.ini may carry: traces keep string
    // arguments, truncated at 15 bytes. The marker sits in those 15 bytes.
    $ignoreArgs = ini_get('zend.exception_ignore_args');
    $maxLen = ini_get('zend.exception_string_param_max_len');
    ini_set('zend.exception_ignore_args', '0');
    ini_set('zend.exception_string_param_max_len', '15');

    try {
        $tooWide = str_repeat('Y', 41);
        config()->set('patient.paper_card_reasons', config('patient.paper_card_reasons') + [$tooWide => 'Kode terlalu panjang']);

        $logged = fprrLogLines(function () use ($tooWide): void {
            $this->actingAs($this->supervisor)
                ->post(route('settings.patients.store'), fprrPayload([
                    'name' => 'Pasien Jejak Argumen',
                    'paper_card_reason_code' => $tooWide,
                    'paper_card_reason_note' => 'qq7zt catatan awal yang sensitif',
                ]))
                ->assertStatus(500);
        });
    } finally {
        ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        ini_set('zend.exception_string_param_max_len', (string) $maxLen);
    }

    expect($logged)->toContain('could not be stored')
        ->and($logged)->not->toContain('qq7zt')
        ->and($logged)->not->toContain('catatan awal');
});

it('PR-25 opens the page of a soft-deleted patient, whose reason the list still links to (review B3)', function (): void {
    $patient = fprrRegister($this->supervisor);
    $patient->delete();

    $this->actingAs($this->supervisor)->get(route('settings.patients.paper-card-registrations.index'))
        ->assertOk()
        ->assertSee(fprrShowUrl($patient), false);

    $this->actingAs($this->supervisor)->get(fprrShowUrl($patient))->assertOk()->assertSee(FPRR_NOTE);

    $atLandak = fprrUser('Front Office', $this->landak);
    $atLandak->givePermissionTo(FPRR_READ);
    $this->actingAs($atLandak)->get(fprrShowUrl($patient))->assertNotFound();
});

it('PR-26 also shows, read only and labelled, the reasons of patients merged INTO this one, inside the branch scope (review B4)', function (): void {
    $source = fprrRegister($this->supervisor, ['name' => 'Pasien Sumber Gabung']);
    $canonical = Patient::factory()->create(['branch_id' => $this->sunu->id, 'medical_record_number' => 'DG-SPN4-2026-0091']);
    // Fixture: the merge pointer as the merge service leaves it (the row itself stays on the source).
    DB::table('mst_patients')->where('id', $source->id)->update(['merged_into_patient_id' => $canonical->id]);

    $this->actingAs($this->supervisor)->get(fprrShowUrl($canonical))
        ->assertOk()
        ->assertSee(FPRR_NOTE)
        ->assertSee('Dari pasien yang digabungkan')
        ->assertSee($source->medical_record_number);

    expect((int) PatientRmPaperCardRegistration::query()->sole()->patient_id)->toBe((int) $source->id);

    // A reader outside the source row's branch does not see it on the canonical page.
    $canonicalAtLandak = Patient::factory()->create(['branch_id' => $this->landak->id, 'medical_record_number' => 'DG-LDK2-2026-0092']);
    DB::table('mst_patients')->where('id', $source->id)->update(['merged_into_patient_id' => $canonicalAtLandak->id]);
    $atLandak = fprrUser('Front Office', $this->landak);
    $atLandak->givePermissionTo(FPRR_READ);

    $this->actingAs($atLandak)->get(fprrShowUrl($canonicalAtLandak))
        ->assertOk()
        ->assertDontSee(FPRR_NOTE);
});

it('PR-27 refuses UPDATE, DELETE and TRUNCATE in the database itself (PostgreSQL trigger; review B5)', function (string $statement): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The immutability trigger is PostgreSQL-only; the model guard covers SQLite.');
    }

    fprrRegister($this->supervisor);
    $refused = false;

    try {
        DB::transaction(fn () => DB::statement($statement));
    } catch (QueryException $e) {
        $refused = str_contains($e->getMessage(), 'append-only');
    }

    expect($refused)->toBeTrue()
        ->and(PatientRmPaperCardRegistration::query()->sole()->reason_note)->toBe(FPRR_NOTE);
})->with([
    'update' => ["UPDATE trx_patient_rm_paper_card_registrations SET reason_note = 'diubah'"],
    'delete' => ['DELETE FROM trx_patient_rm_paper_card_registrations'],
    'truncate' => ['TRUNCATE trx_patient_rm_paper_card_registrations'],
]);

it('PR-28 audits each read of the notes with ids only (review B8)', function (): void {
    $patient = fprrRegister($this->supervisor);
    $owner = userInRole('Owner');

    $this->actingAs($owner)->get(fprrShowUrl($patient))->assertOk();

    $log = AuditLog::query()->where('action', PatientService::AUDIT_PAPER_CARD_REASON_VIEWED)->sole();

    expect(array_keys($log->new_values))->toEqualCanonicalizing(['patient_id', 'actor_user_id', 'registration_ids'])
        ->and((int) $log->new_values['actor_user_id'])->toBe((int) $owner->id)
        ->and(json_encode($log->new_values))->not->toContain('zx81q');
});
