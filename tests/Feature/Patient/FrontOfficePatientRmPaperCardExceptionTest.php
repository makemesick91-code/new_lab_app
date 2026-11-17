<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §4, PR-3) — the
| Supervisor RME paper-card exception and the legacy CSV commit gate
|--------------------------------------------------------------------------
|
| Plan defaults 1 and 4 (owner, 2026-11-17): an old paper-card Nomor RM may be
| entered only with `register_patient_paper_card_rm` (Supervisor RME; Super
| Admin through '*'), with a written reason, and only through the allocator's
| claimExisting() gate — every spelling checked under the branch-year counter
| lock, branch and format validated, audited WITHOUT the number. Everyone else
| keeps the automatic number: the fields are not rendered for them and a posted
| value is refused. Committing a legacy patient CSV batch (which stores typed
| numbers) needs the same permission. The pilot backup importer refuses a
| number held in another spelling.
|
| Selected by the existing `FrontOffice` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `fppc` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientRmSequence;
use App\Modules\Patient\Repositories\PatientRepository;
use App\Modules\Patient\Services\LegacyPatientImportService;
use App\Modules\Patient\Services\PatientService;
use App\Modules\PatientMerge\Models\PatientRmAlias;
use App\Support\PilotImport\PilotBackupImportService;
use Database\Seeders\BranchSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

const FPPC_PERMISSION = 'register_patient_paper_card_rm';

beforeEach(function (): void {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');
    pinTestClock('2026-06-13 01:00:00'); // 09:00 WITA, clinical year 2026

    Branch::query()->where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    $this->clinic = Clinic::factory()->create();
    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->sunu = Branch::factory()->create(['code' => 'SPN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true]);
});

afterEach(fn () => freeTestClock());

/** An account in `$role`; the context-bound desk roles get their working branch (SPN4). */
function fppcUser(string $role): User
{
    $user = User::factory()->create();

    match ($role) {
        'Front Office' => rmeMakeFrontOfficeActive($user, test()->sunu),
        'Admin Klinik' => rmeMakeAdminClinicActive($user, test()->sunu),
        'Kasir' => rmeMakeKasirActive($user, test()->sunu),
        default => $user->assignRole($role),
    };

    return $user;
}

function fppcPayload(array $overrides = []): array
{
    return array_merge([
        'clinic_id' => test()->clinic->id,
        'doctor_id' => test()->doctor->id,
        'branch_id' => test()->sunu->id,
        'name' => 'Pasien Kartu Lama '.Str::random(5),
        'gender' => 'Female',
        'is_active' => 1,
        'paper_card_year' => '2025',
        'paper_card_sequence' => '0012',
        'paper_card_reason' => 'Pasien membawa kartu berobat kertas lama dari cabang.',
    ], $overrides);
}

function fppcService(User $actor, array $overrides = []): Patient
{
    $payload = fppcPayload($overrides);

    return app(PatientService::class)->createWithPaperCardNumber(
        array_diff_key($payload, array_flip(['paper_card_year', 'paper_card_sequence', 'paper_card_reason'])),
        $actor,
        (int) $payload['paper_card_year'],
        (string) $payload['paper_card_sequence'],
        (string) $payload['paper_card_reason'],
    );
}

/** An active merge alias holding `$alias` (the number is on no patient row). */
function fppcAlias(Branch $branch, string $alias): void
{
    $source = Patient::factory()->create(['branch_id' => $branch->id, 'medical_record_number' => 'DG-'.$branch->code.'-2019-0001']);
    $canonical = Patient::factory()->create(['branch_id' => $branch->id, 'medical_record_number' => 'DG-'.$branch->code.'-2019-0002']);
    $caseId = DB::table('trx_patient_merge_cases')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'case_number' => 'FPPC-MERGE-'.$branch->id,
        'patient_a_id' => $source->id,
        'patient_b_id' => $canonical->id,
        'requested_by' => User::factory()->create()->id,
        'requested_at' => now(),
        'request_reason' => 'Paper card alias fixture',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    PatientRmAlias::query()->create([
        'alias_medical_record_number' => $alias,
        'source_patient_id' => $source->id,
        'canonical_patient_id' => $canonical->id,
        'merge_case_id' => $caseId,
        'merged_at' => now(),
    ]);
}

/** A staged, error-free legacy patient CSV batch for SPN4. */
function fppcStagedBatch(): LegacyPatientImportBatch
{
    $keys = array_map(fn ($c) => $c['key'], LegacyPatientImportService::COLUMNS);
    $header = array_map(fn ($c) => $c['label'], LegacyPatientImportService::COLUMNS);
    $row = ['branch' => 'SPN4', 'manual_rm_number' => '0077', 'timestamp' => '2025-03-01', 'name' => 'Pasien Impor Kartu', 'gender' => 'Perempuan'];

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $header);
    fputcsv($handle, array_map(fn ($k) => $row[$k] ?? '', $keys));
    rewind($handle);
    $path = tempArtifactFile('fppc');
    file_put_contents($path, stream_get_contents($handle));
    fclose($handle);

    return app(LegacyPatientImportService::class)
        ->parseAndStage(new UploadedFile($path, 'legacy.csv', 'text/csv', null, true), null);
}

// =============================================================================
// The exception itself
// =============================================================================

it('PC-01 lets Supervisor RME register an old paper-card number, stored verbatim under the branch code', function (): void {
    $this->actingAs(fppcUser('Supervisor RME'))
        ->post(route('settings.patients.store'), fppcPayload(['name' => 'Pasien Kartu Satu']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.patients.index'))
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'DG-SPN4-2025-0012'));

    $patient = Patient::query()->where('name', 'Pasien Kartu Satu')->sole();

    expect($patient->medical_record_number)->toBe('DG-SPN4-2025-0012')
        ->and($patient->manual_rm_number)->toBe('0012')
        ->and($patient->branch_id)->toBe($this->sunu->id)
        ->and($patient->registered_at->toDateString())->toBe('2026-06-13')
        // The claim locked (and so created) the card's branch-year counter and
        // raised it to the claimed value: continue after the highest (default 3).
        ->and((int) PatientRmSequence::query()->where('branch_id', $this->sunu->id)->where('year', 2025)->value('last_number'))->toBe(12);
});

it('PC-02 refuses every role without the permission over HTTP and writes nothing', function (string $role): void {
    $response = $this->actingAs(fppcUser($role))
        ->post(route('settings.patients.store'), fppcPayload(['name' => 'Pasien Ditolak '.$role]));

    if ($role === 'Doctor') {
        // A doctor account is turned away by the doctor access gate before the
        // patient route is reached; either way it is never the success redirect.
        expect($response->status())->toBeIn([302, 403])
            ->and($response->headers->get('Location'))->not->toBe(route('settings.patients.index'));
    } else {
        $response->assertForbidden();
    }

    expect(Patient::query()->where('name', 'Pasien Ditolak '.$role)->exists())->toBeFalse()
        ->and(PatientRmSequence::query()->count())->toBe(0);
})->with(['Front Office', 'Admin Klinik', 'Kasir', 'Doctor']);

it('PC-03 re-asserts the permission in the service, whatever the caller', function (string $role): void {
    expect(fn () => fppcService(fppcUser($role)))->toThrow(AuthorizationException::class);

    expect(Patient::query()->count())->toBe(0)
        ->and(PatientRmSequence::query()->count())->toBe(0);
})->with(['Front Office', 'Admin Klinik', 'Kasir', 'Doctor']);

it('PC-04 lets Super Admin through the wildcard, but the reason still binds it in the service', function (): void {
    $super = fppcUser('Super Admin');

    expect(fppcService($super)->medical_record_number)->toBe('DG-SPN4-2025-0012');

    try {
        fppcService($super, ['paper_card_sequence' => '0013', 'paper_card_reason' => 'singkat']);
        $this->fail('A Super Admin paper-card registration without a proper reason must be refused.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('paper_card_reason');
    }
});

it('PC-05 requires a written reason of 10 to 500 characters', function (?string $reason): void {
    $this->actingAs(fppcUser('Supervisor RME'))
        ->post(route('settings.patients.store'), fppcPayload(['name' => 'Pasien Alasan', 'paper_card_reason' => $reason]))
        ->assertSessionHasErrors('paper_card_reason');

    expect(Patient::query()->where('name', 'Pasien Alasan')->exists())->toBeFalse();
})->with([
    'missing' => [null],
    'too short' => ['kartu'],
    'too long' => [str_repeat('a', 501)],
]);

it('PC-06 validates the format: four-digit past year, one to eight digits, never zero', function (array $overrides, string $field): void {
    $this->actingAs(fppcUser('Supervisor RME'))
        ->post(route('settings.patients.store'), fppcPayload($overrides + ['name' => 'Pasien Format']))
        ->assertSessionHasErrors($field);

    expect(Patient::query()->where('name', 'Pasien Format')->exists())->toBeFalse();
})->with([
    'future year' => [['paper_card_year' => '2027'], 'paper_card_year'],
    'three-digit year' => [['paper_card_year' => '999'], 'paper_card_year'],
    'letters' => [['paper_card_sequence' => '12A'], 'paper_card_sequence'],
    'zero' => [['paper_card_sequence' => '0000'], 'paper_card_sequence'],
    'nine digits' => [['paper_card_sequence' => '123456789'], 'paper_card_sequence'],
]);

// =============================================================================
// Never a duplicate in any spelling, under the counter lock
// =============================================================================

it('PC-07 refuses a paper-card number held in another spelling, case, soft-deleted or as a merge alias', function (string $held): void {
    if ($held === 'alias') {
        fppcAlias($this->sunu, 'DG-SUN4-2025-12');
    } elseif ($held === 'trashed') {
        Patient::factory()->create(['branch_id' => $this->sunu->id, 'medical_record_number' => 'DG-SPN4-2025-000012'])->delete();
    } else {
        Patient::factory()->create(['branch_id' => $this->sunu->id, 'medical_record_number' => $held]);
    }
    $before = Patient::withTrashed()->count();

    $this->actingAs(fppcUser('Supervisor RME'))
        ->post(route('settings.patients.store'), fppcPayload(['name' => 'Pasien Duplikat Kartu']))
        ->assertSessionHasErrors('paper_card_sequence');

    expect(Patient::withTrashed()->count())->toBe($before)
        ->and(Patient::query()->where('name', 'Pasien Duplikat Kartu')->exists())->toBeFalse();
})->with([
    'historical alias code, unpadded' => ['DG-SUN4-2025-12'],
    'lower case' => ['dg-spn4-2025-0012'],
    'soft-deleted, other padding' => ['trashed'],
    'merge alias' => ['alias'],
]);

it('PC-08 refuses MAIN and a branch code the Nomor RM format cannot carry, writing nothing', function (): void {
    $main = Branch::query()->where('code', Branch::MAIN_CODE)->firstOrFail();
    $main->update(['is_rme_enabled' => true]);
    $hyphen = Branch::factory()->create(['code' => 'PC-X1', 'is_active' => true, 'is_rme_enabled' => true]);
    $supervisor = fppcUser('Supervisor RME');

    foreach ([$main, $hyphen] as $branch) {
        $this->actingAs($supervisor)
            ->post(route('settings.patients.store'), fppcPayload(['branch_id' => $branch->id, 'name' => 'Pasien Cabang '.$branch->id]))
            ->assertSessionHasErrors();

        expect(Patient::query()->where('name', 'Pasien Cabang '.$branch->id)->exists())->toBeFalse()
            ->and(PatientRmSequence::query()->where('branch_id', $branch->id)->exists())->toBeFalse();
    }
});

it('PC-09 reserves the paper-card number: the allocator steps over it', function (): void {
    PatientRmSequence::query()->create([
        'branch_id' => $this->sunu->id, 'year' => 2026, 'last_number' => 4,
        'seed_source' => PatientRmSequence::SOURCE_COMMAND, 'seeded_at' => now(),
    ]);

    fppcService(fppcUser('Supervisor RME'), ['paper_card_year' => '2026', 'paper_card_sequence' => '5']);

    $automatic = app(PatientService::class)->create([
        'clinic_id' => $this->clinic->id,
        'doctor_id' => $this->doctor->id,
        'branch_id' => $this->sunu->id,
        'name' => 'Pasien Otomatis Sesudah Kartu',
    ]);

    expect($automatic->medical_record_number)->toBe('DG-SPN4-2026-0006');
});

it('PC-10 audits the exception with ids and the reason length only, never the number or the reason', function (): void {
    $supervisor = fppcUser('Supervisor RME');
    $patient = fppcService($supervisor);

    $log = AuditLog::query()->where('action', 'PATIENT_RM_PAPER_CARD_REGISTERED')->sole();
    $json = json_encode([$log->old_values, $log->new_values]);

    expect($log->entity_type)->toBe('mst_patients')
        ->and((int) $log->entity_id)->toBe((int) $patient->id)
        ->and(array_keys($log->new_values))->toEqualCanonicalizing(['patient_id', 'branch_id', 'actor_user_id', 'reason_length'])
        ->and($log->new_values['reason_length'])->toBe(mb_strlen(fppcPayload()['paper_card_reason']))
        ->and($json)->not->toContain('0012')
        ->and($json)->not->toContain('DG-SPN4')
        ->and($json)->not->toContain('kartu berobat');
});

// =============================================================================
// The form mirrors the server (rule 135)
// =============================================================================

it('PC-11 renders the paper-card fields only for an account holding the permission', function (): void {
    $this->actingAs(fppcUser('Supervisor RME'))
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('name="paper_card_sequence"', false)
        ->assertSee('name="paper_card_reason"', false);

    foreach (['Front Office', 'Admin Klinik'] as $role) {
        $this->actingAs(fppcUser($role))
            ->get(route('settings.patients.create'))
            ->assertOk()
            ->assertDontSee('name="paper_card_sequence"', false)
            ->assertDontSee('name="paper_card_year"', false)
            ->assertDontSee('name="paper_card_reason"', false);
    }
});

it('PC-12 keeps Kunjungan → Pasien Baru automatic even for Supervisor RME', function (): void {
    $patient = app(PatientService::class)->create([
        'clinic_id' => $this->clinic->id,
        'doctor_id' => $this->doctor->id,
        'branch_id' => $this->sunu->id,
        'name' => 'Pasien Kunjungan Otomatis',
        'paper_card_year' => '2025',
        'paper_card_sequence' => '0012',
        'paper_card_reason' => 'Tidak berlaku pada jalur pendaftaran biasa.',
    ]);

    expect($patient->medical_record_number)->toBe('DG-SPN4-2026-0001');
});

// =============================================================================
// Legacy CSV commit needs the same permission (owner default 4)
// =============================================================================

it('PC-13 refuses the legacy CSV commit without the permission over HTTP, while staging stays open', function (string $role): void {
    $batch = fppcStagedBatch();
    expect($batch->error_rows)->toBe(0);

    $this->actingAs(fppcUser($role))
        ->post(route('settings.patients.import.commit', $batch), ['acknowledged' => '1'])
        ->assertForbidden();

    expect(Patient::query()->where('name', 'Pasien Impor Kartu')->exists())->toBeFalse()
        ->and($batch->refresh()->isCommitted())->toBeFalse();
})->with(['Front Office', 'Admin Klinik']);

it('PC-14 re-asserts the commit permission in the service and audits the refusal without a number', function (): void {
    $batch = fppcStagedBatch();
    $frontOffice = fppcUser('Front Office');

    expect(fn () => app(LegacyPatientImportService::class)->commit($batch, $frontOffice->id))->toThrow(AuthorizationException::class)
        ->and(fn () => app(LegacyPatientImportService::class)->commit($batch, null))->toThrow(AuthorizationException::class);

    $refusal = AuditLog::query()
        ->where('action', LegacyPatientImportService::AUDIT_CONFIRM_BLOCKED)
        ->latest('id')
        ->first();

    expect(Patient::query()->where('name', 'Pasien Impor Kartu')->exists())->toBeFalse()
        ->and($refusal?->new_values['reason'] ?? null)->toBe('paper_card_permission_missing')
        ->and(json_encode($refusal?->new_values))->not->toContain('DG-SPN4');
});

it('PC-15 lets Supervisor RME commit the batch, and shows the commit form only to holders', function (): void {
    $batch = fppcStagedBatch();

    $this->actingAs(fppcUser('Front Office'))
        ->get(route('settings.patients.import.show', $batch))
        ->assertOk()
        ->assertDontSee(route('settings.patients.import.commit', $batch), false);

    $supervisor = fppcUser('Supervisor RME');

    $this->actingAs($supervisor)
        ->get(route('settings.patients.import.show', $batch))
        ->assertOk()
        ->assertSee(route('settings.patients.import.commit', $batch), false);

    $this->actingAs($supervisor)
        ->post(route('settings.patients.import.commit', $batch), ['acknowledged' => '1'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.patients.import.show', $batch));

    expect(Patient::query()->where('name', 'Pasien Impor Kartu')->value('medical_record_number'))->toBe('DG-SPN4-2025-0077');
});

// =============================================================================
// Seeders and the pilot backup importer
// =============================================================================

it('PC-16 seeds the permission for Supervisor RME only (Super Admin through the wildcard)', function (): void {
    expect(PermissionSeeder::PERMISSIONS)->toContain(FPPC_PERMISSION)
        ->and(RoleSeeder::ROLE_PERMISSIONS['Supervisor RME'])->toContain(FPPC_PERMISSION)
        ->and(RoleSeeder::ROLE_PERMISSIONS['Super Admin'])->toBe('*')
        ->and(RoleSeeder::NO_ROLE_PERMISSIONS)->not->toContain(FPPC_PERMISSION);

    $holders = Role::query()->get()->filter(fn (Role $role): bool => $role->hasPermissionTo(FPPC_PERMISSION))->pluck('name')->sort()->values()->all();

    expect($holders)->toBe(['Super Admin', 'Supervisor RME']);

    foreach (['Front Office', 'Admin Klinik', 'Kasir', 'Doctor', 'Perawat', 'Kepala Cabang'] as $role) {
        expect(fppcUser($role)->can(FPPC_PERMISSION))->toBeFalse("{$role} must not hold the paper-card permission");
    }
});

it('PC-17 makes the pilot backup importer skip a number held in another spelling and claim a free one under the lock', function (): void {
    Doctor::factory()->create(['code' => 'FPPC-DOC']);
    Patient::factory()->create(['branch_id' => $this->sunu->id, 'medical_record_number' => 'DG-SUN4-2025-41']);

    $extracted = ['tables' => [
        'mst_doctors' => [['id' => '9', 'code' => 'FPPC-DOC', 'name' => 'Dokter Pilot']],
        'mst_patients' => [
            ['medical_record_number' => 'DG-SPN4-2025-0041', 'name' => 'Pilot Bentrok', 'doctor_id' => '9'],
            ['medical_record_number' => 'DG-SPN4-2025-0042', 'name' => 'Pilot Bebas', 'doctor_id' => '9'],
        ],
    ], 'skipped_tables' => [], 'whitelisted_row_counts' => []];

    $result = app(PilotBackupImportService::class)->import($extracted, dryRun: false, only: 'patients');

    expect(Patient::query()->where('name', 'Pilot Bentrok')->exists())->toBeFalse()
        ->and(Patient::query()->where('name', 'Pilot Bebas')->value('medical_record_number'))->toBe('DG-SPN4-2025-0042')
        ->and(PatientRmSequence::query()->where('branch_id', $this->sunu->id)->where('year', 2025)->exists())->toBeTrue()
        ->and(implode(' ', $result->messages))->not->toContain('DG-SPN4');
});

// =============================================================================
// PR-3 review fixes (independent review of e0d89b5e)
// =============================================================================

/** A repository whose insert fails with a unique violation on the named constraint. */
function fppcUniqueViolation(string $constraint): UniqueConstraintViolationException
{
    $driver = new PDOException('duplicate key');
    $driver->errorInfo = ['23505', 7, 'ERROR:  duplicate key value violates unique constraint "'.$constraint.'"'];

    return new UniqueConstraintViolationException('pgsql', 'insert into "mst_patients" ...', [], $driver);
}

it('PC-18 reports only a Nomor RM unique violation as taken; KTP stays its 422 and any other violation is rethrown (review I3)', function (string $constraint, ?string $field): void {
    $violation = fppcUniqueViolation($constraint);
    app()->instance(PatientRepositoryInterface::class, new class($violation) extends PatientRepository
    {
        public function __construct(private readonly UniqueConstraintViolationException $violation) {}

        public function create(array $data): Patient
        {
            throw $this->violation;
        }
    });

    $thrown = null;
    try {
        fppcService(fppcUser('Supervisor RME'));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    if ($field === null) {
        expect($thrown)->toBe($violation);
    } else {
        expect($thrown)->toBeInstanceOf(ValidationException::class)
            ->and(array_keys($thrown->errors()))->toBe([$field]);
    }

    expect(Patient::query()->count())->toBe(0);
})->with([
    'Nomor RM' => ['mst_patients_medical_record_number_unique', 'paper_card_sequence'],
    'KTP' => ['mst_patients_ktp_number_unique', 'ktp_number'],
    'another constraint' => ['mst_patients_some_other_key_unique', null],
]);

it('PC-19 refuses a legacy CSV committer holding the paper-card permission but not manage patients (review I6)', function (): void {
    $batch = fppcStagedBatch();
    $paperCardOnly = User::factory()->create();
    $paperCardOnly->givePermissionTo(FPPC_PERMISSION);

    expect($paperCardOnly->can(FPPC_PERMISSION))->toBeTrue()
        ->and($paperCardOnly->can('manage patients'))->toBeFalse()
        ->and(fn () => app(LegacyPatientImportService::class)->commit($batch, $paperCardOnly->id))->toThrow(AuthorizationException::class);

    expect(Patient::query()->where('name', 'Pasien Impor Kartu')->exists())->toBeFalse()
        ->and($batch->refresh()->isCommitted())->toBeFalse()
        ->and(AuditLog::query()->where('action', LegacyPatientImportService::AUDIT_CONFIRM_BLOCKED)->latest('id')->value('new_values')['reason'] ?? null)
        ->toBe('paper_card_permission_missing');
});

it('PC-20 pre-locks the pilot import counters in sorted (branch, year) order before the first patient insert (review L3)', function (): void {
    Doctor::factory()->create(['code' => 'FPPC-DOC']);
    $other = Branch::factory()->create(['code' => 'LDK2', 'name' => 'Cabang Landak', 'is_active' => true, 'is_rme_enabled' => true]);
    [$low, $high] = $this->sunu->id < $other->id ? [$this->sunu, $other] : [$other, $this->sunu];

    // The backup's own order is deliberately unsorted.
    $extracted = ['tables' => [
        'mst_doctors' => [['id' => '9', 'code' => 'FPPC-DOC', 'name' => 'Dokter Pilot']],
        'mst_patients' => [
            ['medical_record_number' => "DG-{$high->code}-2025-0001", 'name' => 'Pilot Urut Satu', 'doctor_id' => '9'],
            ['medical_record_number' => "DG-{$low->code}-2025-0002", 'name' => 'Pilot Urut Dua', 'doctor_id' => '9'],
            ['medical_record_number' => "DG-{$low->code}-2024-0003", 'name' => 'Pilot Urut Tiga', 'doctor_id' => '9'],
            ['medical_record_number' => "DG-{$high->code}-2023-0004", 'name' => 'Pilot Urut Empat', 'doctor_id' => '9'],
        ],
    ], 'skipped_tables' => [], 'whitelisted_row_counts' => []];

    $events = [];
    DB::listen(function ($query) use (&$events): void {
        $sql = ltrim(strtolower($query->sql));

        if (str_starts_with($sql, 'select') && str_contains($sql, 'mst_patient_rm_sequences')) {
            $events[] = [(int) $query->bindings[0], (int) $query->bindings[1]];
        } elseif (str_starts_with($sql, 'insert into "mst_patients"')) {
            $events[] = 'insert';
        }
    });

    app(PilotBackupImportService::class)->import($extracted, dryRun: false, only: 'patients');

    $beforeFirstInsert = array_slice($events, 0, (int) array_search('insert', $events, true));
    $lockOrder = array_values(array_unique(array_map(fn (array $pair): string => $pair[0].'|'.$pair[1], $beforeFirstInsert)));

    expect($lockOrder)->toBe([$low->id.'|2024', $low->id.'|2025', $high->id.'|2023', $high->id.'|2025'])
        ->and(Patient::query()->where('name', 'like', 'Pilot Urut %')->count())->toBe(4);
});
