<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §2, PR-2) — the
| automatic, read-only Nomor RM at registration
|--------------------------------------------------------------------------
|
| Owner decision 2026-11-17 "Auto, read-only": both registration forms (Master
| Data → Tambah Pasien and Kunjungan → Pasien Baru) take the Nomor RM from the
| allocator inside the patient-create transaction. A number, a manual sequence
| or a registration date posted by a client is ignored; the registration date
| and the RM year are the clinic's calendar day (ClinicalClock). The free
| override and the UTC fallback generator are retired; an edit never changes the
| number, the branch or the registration date; existing numbers are never
| rewritten. A unique-index violation is retried up to three times under the held
| counter lock, then answers 422 — never 500, nothing half-written.
|
| Selected by the existing `FrontOffice` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `fpan` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Interfaces\PatientRmSequenceRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientRmSequence;
use App\Modules\Patient\Repositories\PatientRepository;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use App\Modules\Patient\Services\PatientRmNumberAllocator;
use App\Modules\Patient\Services\PatientService;
use App\Modules\PatientMerge\Models\PatientRmAlias;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    pinTestClock('2026-06-13 01:00:00'); // 09:00 WITA, clinical year 2026

    Branch::query()->where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    $this->clinic = Clinic::factory()->create();
    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->branch = fpanBranch('FPN1');
});

afterEach(fn () => freeTestClock());

function fpanBranch(string $code): Branch
{
    return Branch::factory()->create(['code' => $code, 'is_active' => true, 'is_rme_enabled' => true]);
}

function fpanPayload(array $overrides = []): array
{
    return array_merge([
        'clinic_id' => test()->clinic->id,
        'doctor_id' => test()->doctor->id,
        'branch_id' => test()->branch->id,
        'name' => 'Pasien Otomatis '.uniqid(),
        'gender' => 'Female',
        'is_active' => 1,
    ], $overrides);
}

function fpanRegister(array $overrides = []): Patient
{
    $payload = fpanPayload($overrides);

    test()->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.patients.index'));

    return Patient::query()->where('name', $payload['name'])->firstOrFail();
}

function fpanCounter(Branch $branch, int $year = 2026): ?int
{
    $value = PatientRmSequence::query()->where('branch_id', $branch->id)->where('year', $year)->value('last_number');

    return $value === null ? null : (int) $value;
}

function fpanSeedCounter(Branch $branch, int $last, int $year = 2026): void
{
    PatientRmSequence::query()->create([
        'branch_id' => $branch->id,
        'year' => $year,
        'last_number' => $last,
        'seed_source' => PatientRmSequence::SOURCE_COMMAND,
        'seeded_at' => now(),
    ]);
}

/**
 * A sequence repository that cannot see existing patients — the shape of a
 * writer that stored a number without the counter lock (an old process during a
 * rolling deploy). The allocator then issues a number the unique index refuses.
 */
function fpanBlindSequences(): void
{
    $inner = app(PatientRmSequenceRepositoryInterface::class);

    app()->instance(PatientRmSequenceRepositoryInterface::class, new class($inner) implements PatientRmSequenceRepositoryInterface
    {
        public function __construct(private readonly PatientRmSequenceRepositoryInterface $inner) {}

        public function lockOrCreate(int $branchId, int $year, callable $seed): PatientRmSequence
        {
            return $this->inner->lockOrCreate($branchId, $year, $seed);
        }

        public function advanceTo(PatientRmSequence $sequence, int $lastNumber): void
        {
            $this->inner->advanceTo($sequence, $lastNumber);
        }

        public function raiseTo(int $branchId, int $year, int $value): string
        {
            return $this->inner->raiseTo($branchId, $year, $value);
        }

        public function anyNumberIssued(array $medicalRecordNumbers): bool
        {
            return false;
        }

        public function issuedNumbersFor(array $branchCodes, int $year): LazyCollection
        {
            return LazyCollection::empty();
        }

        public function issuedPrefixedNumbers(): LazyCollection
        {
            return LazyCollection::empty();
        }
    });
}

/** An active merge alias holding `$alias` (the number is on no patient row). */
function fpanAlias(Branch $branch, string $alias): void
{
    $source = Patient::factory()->create(['branch_id' => $branch->id, 'medical_record_number' => 'DG-'.$branch->code.'-2019-0001']);
    $canonical = Patient::factory()->create(['branch_id' => $branch->id, 'medical_record_number' => 'DG-'.$branch->code.'-2019-0002']);
    $caseId = DB::table('trx_patient_merge_cases')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'case_number' => 'FPAN-MERGE-'.$branch->id,
        'patient_a_id' => $source->id,
        'patient_b_id' => $canonical->id,
        'requested_by' => User::factory()->create()->id,
        'requested_at' => now(),
        'request_reason' => 'Automatic number alias fixture',
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

// =============================================================================
// The normal path: automatic, read-only
// =============================================================================

it('AN-01 issues DG-{KODE}-{TAHUN}-0001, then 0002, from the master form and stamps the clinical day', function (): void {
    $first = fpanRegister();
    $second = fpanRegister();

    expect($first->medical_record_number)->toBe('DG-FPN1-2026-0001')
        ->and($second->medical_record_number)->toBe('DG-FPN1-2026-0002')
        ->and($first->registered_at->toDateString())->toBe('2026-06-13')
        ->and($first->manual_rm_number)->toBeNull()
        ->and($first->branch_id)->toBe($this->branch->id)
        ->and(fpanCounter($this->branch))->toBe(2);
});

it('AN-02 shows the issued number only after saving', function (): void {
    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), fpanPayload())
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'DG-FPN1-2026-0001'));
});

it('AN-03 ignores a posted Nomor RM, manual sequence and registration date on the master form', function (): void {
    $patient = fpanRegister([
        'medical_record_number' => 'DG-FPN1-2026-0777',
        'manual_rm_number' => '0555',
        'registered_at' => '2019-01-01',
    ]);

    expect($patient->medical_record_number)->toBe('DG-FPN1-2026-0001')
        ->and($patient->manual_rm_number)->toBeNull()
        ->and($patient->registered_at->toDateString())->toBe('2026-06-13')
        ->and(Patient::query()->where('medical_record_number', 'DG-FPN1-2026-0777')->exists())->toBeFalse();
});

it('AN-04 issues the automatic number on Kunjungan → Pasien Baru and ignores a posted number there too', function (): void {
    $treatment = Treatment::factory()->create(['is_active' => true]);
    rmeMakeDoctorOnline($this->doctor, $this->branch);

    $this->actingAs(userWith(['manage_clinic_visits', 'view_clinic_visits', 'manage patients']))
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'branch_id' => $this->branch->id,
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $treatment->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
            'new_patient' => [
                'name' => 'Pasien Baru Kunjungan',
                'branch_id' => $this->branch->id,
                'registered_at' => '2020-02-02',
                'manual_rm_number' => '0999',
                'medical_record_number' => 'DG-FPN1-2026-0999',
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $patient = Patient::query()->where('name', 'Pasien Baru Kunjungan')->firstOrFail();

    expect($patient->medical_record_number)->toBe('DG-FPN1-2026-0001')
        ->and($patient->registered_at->toDateString())->toBe('2026-06-13')
        ->and($patient->manual_rm_number)->toBeNull()
        ->and(ClinicVisit::query()->where('patient_id', $patient->id)->exists())->toBeTrue();
});

it('AN-05 takes the RM year from the clinic calendar: 00:30 WITA on 1 January is the new year while UTC is still 31 December', function (): void {
    pinTestClock('2026-12-31 16:30:00');

    expect(now()->format('Y-m-d'))->toBe('2026-12-31');

    $patient = fpanRegister();

    expect($patient->medical_record_number)->toBe('DG-FPN1-2027-0001')
        ->and($patient->registered_at->toDateString())->toBe('2027-01-01')
        ->and(fpanCounter($this->branch, 2027))->toBe(1)
        ->and(fpanCounter($this->branch, 2026))->toBeNull();
});

it('AN-06 widens from 9999 to 10000 without wrapping or truncating', function (): void {
    fpanSeedCounter($this->branch, 9998);

    expect(fpanRegister()->medical_record_number)->toBe('DG-FPN1-2026-9999')
        ->and(fpanRegister()->medical_record_number)->toBe('DG-FPN1-2026-10000')
        ->and(fpanCounter($this->branch))->toBe(10000);
});

// =============================================================================
// Never reissued in any spelling
// =============================================================================

it('AN-07 continues after the highest existing number in any spelling, the historical alias code included', function (): void {
    $sunu = fpanBranch(BranchCodeAlias::SUNU_CANONICAL);
    Patient::factory()->create(['branch_id' => $sunu->id, 'medical_record_number' => 'DG-SUN4-2026-0007']);
    Patient::factory()->create(['branch_id' => $sunu->id, 'medical_record_number' => 'DG-SPN4-2026-5']);

    expect(fpanRegister(['branch_id' => $sunu->id])->medical_record_number)->toBe('DG-SPN4-2026-0008');
});

it('AN-08 skips a number held under another spelling, case or padding, soft-deleted or as a merge alias', function (): void {
    $sunu = fpanBranch(BranchCodeAlias::SUNU_CANONICAL);
    fpanSeedCounter($sunu, 6);

    // Each of these appeared after the counter was seeded at 6.
    Patient::factory()->create(['branch_id' => $sunu->id, 'medical_record_number' => 'DG-SUN4-2026-7']);
    Patient::factory()->create(['branch_id' => $sunu->id, 'medical_record_number' => 'dg-spn4-2026-0008']);
    Patient::factory()->create(['branch_id' => $sunu->id, 'medical_record_number' => 'DG-SPN4-2026-000009'])->delete();
    fpanAlias($sunu, 'DG-SUN4-2026-10');

    expect(fpanRegister(['branch_id' => $sunu->id])->medical_record_number)->toBe('DG-SPN4-2026-0011');
});

it('AN-09 reports a taken number in every spelling through exists(), case-insensitively', function (): void {
    Patient::factory()->create(['medical_record_number' => 'DG-SPN4-2026-0007']);
    $numbers = app(PatientMedicalRecordNumberService::class);

    expect($numbers->exists('DG-SUN4-2026-7'))->toBeTrue()
        ->and($numbers->exists('dg-spn4-2026-07'))->toBeTrue()
        ->and($numbers->exists('DG-SPN4-2026-000000007'))->toBeTrue()
        ->and($numbers->exists('DG-SPN4-2027-0007'))->toBeFalse()
        ->and($numbers->exists('DG-SPN4-2026-0070'))->toBeFalse();
});

// =============================================================================
// Failure is a clean 422
// =============================================================================

it('AN-10 retries an injected unique violation three times under the held lock, then answers 422, writing nothing', function (): void {
    // One attempt plus three retries (owner wording "retried up to 3 times"):
    // four colliding numbers exhaust them.
    fpanSeedCounter($this->branch, 0);
    foreach (['0001', '0002', '0003', '0004'] as $sequence) {
        Patient::factory()->create(['branch_id' => $this->branch->id, 'medical_record_number' => 'DG-FPN1-2026-'.$sequence]);
    }
    $before = Patient::withTrashed()->count();
    fpanBlindSequences();

    $this->actingAs(userWith(['manage patients']))
        ->postJson(route('settings.patients.store'), fpanPayload(['name' => 'Pasien Bentrok']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('medical_record_number');

    expect(Patient::withTrashed()->count())->toBe($before)
        ->and(Patient::query()->where('name', 'Pasien Bentrok')->exists())->toBeFalse()
        ->and(fpanCounter($this->branch))->toBe(0);
});

it('AN-11 succeeds on the last retry when three issued numbers collide on the unique index', function (): void {
    fpanSeedCounter($this->branch, 0);
    foreach (['0001', '0002', '0003'] as $sequence) {
        Patient::factory()->create(['branch_id' => $this->branch->id, 'medical_record_number' => 'DG-FPN1-2026-'.$sequence]);
    }
    fpanBlindSequences();

    expect(fpanRegister()->medical_record_number)->toBe('DG-FPN1-2026-0004')
        ->and(fpanCounter($this->branch))->toBe(4);
});

it('AN-12 answers a KTP unique violation with a KTP error instead of a 500, writing nothing', function (): void {
    Patient::factory()->create(['ktp_number' => '7300000000000001']);

    try {
        app(PatientService::class)->create(fpanPayload(['ktp_number' => '7300000000000001']));
        $this->fail('A duplicate KTP must be refused.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('ktp_number');
    }

    expect(fpanCounter($this->branch))->toBeNull()
        ->and(Patient::query()->count())->toBe(1);
});

it('AN-13 refuses MAIN with a 422 and allocates nothing, even when MAIN is RME-enabled', function (): void {
    $main = Branch::query()->where('code', Branch::MAIN_CODE)->firstOrFail();
    $main->update(['is_rme_enabled' => true]);

    $this->actingAs(userWith(['manage patients']))
        ->postJson(route('settings.patients.store'), fpanPayload(['branch_id' => $main->id, 'name' => 'Pasien MAIN']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('medical_record_number');

    expect(Patient::query()->where('name', 'Pasien MAIN')->exists())->toBeFalse()
        ->and(PatientRmSequence::query()->where('branch_id', $main->id)->exists())->toBeFalse();
});

it('AN-14 requires a branch: the UTC fallback generator is retired', function (): void {
    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), fpanPayload(['branch_id' => null, 'name' => 'Tanpa Cabang']))
        ->assertSessionHasErrors('branch_id');

    expect(Patient::query()->where('name', 'Tanpa Cabang')->exists())->toBeFalse();

    try {
        app(PatientService::class)->create(fpanPayload(['branch_id' => null, 'name' => 'Tanpa Cabang']));
        $this->fail('A patient without a branch must be refused by the service too.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('branch_id');
    }

    expect(Patient::query()->where('name', 'Tanpa Cabang')->exists())->toBeFalse();
});

it('AN-21 refuses cleanly with a 422 once the branch-year would need a nine-digit sequence', function (): void {
    fpanSeedCounter($this->branch, 99_999_999);

    $this->actingAs(userWith(['manage patients']))
        ->postJson(route('settings.patients.store'), fpanPayload(['name' => 'Pasien Batas']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('medical_record_number');

    expect(Patient::query()->where('name', 'Pasien Batas')->exists())->toBeFalse()
        ->and(fpanCounter($this->branch))->toBe(99_999_999);
});

// =============================================================================
// Authorization, branch isolation, existing patients
// =============================================================================

it('AN-15 keeps registration authorization unchanged: no `manage patients`, no patient and no counter', function (): void {
    $this->actingAs(userWith([]))
        ->post(route('settings.patients.store'), fpanPayload(['name' => 'Tanpa Izin']))
        ->assertForbidden();

    $frontOffice = User::factory()->create()->assignRole('Front Office');

    expect($frontOffice->can('manage patients'))->toBeTrue()
        ->and(Patient::query()->where('name', 'Tanpa Izin')->exists())->toBeFalse()
        ->and(fpanCounter($this->branch))->toBeNull();
});

it('AN-16 keeps each branch sequence separate', function (): void {
    $other = fpanBranch('FPN2');
    fpanSeedCounter($other, 40);

    fpanRegister();
    fpanRegister();

    expect(fpanRegister(['branch_id' => $other->id])->medical_record_number)->toBe('DG-FPN2-2026-0041')
        ->and(fpanCounter($this->branch))->toBe(2)
        ->and(fpanCounter($other))->toBe(41);
});

it('AN-17 never rewrites an existing patient: an edit keeps the number, the branch and the registration date', function (): void {
    $other = fpanBranch('FPN2');
    $legacy = Patient::factory()->create([
        'branch_id' => $this->branch->id,
        'medical_record_number' => 'DG-FPN1-2024-17',
        'manual_rm_number' => '17',
        'registered_at' => '2024-03-04',
        'name' => 'Pasien Lama',
    ]);

    fpanRegister();

    $this->actingAs(userWith(['manage patients']))
        ->put(route('settings.patients.update', $legacy), fpanPayload([
            'name' => 'Pasien Lama Diperbarui',
            'branch_id' => $other->id,
            'registered_at' => '2026-06-13',
            'manual_rm_number' => '0001',
            'medical_record_number' => 'DG-FPN2-2026-0001',
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.patients.index'));

    $legacy->refresh();

    expect($legacy->name)->toBe('Pasien Lama Diperbarui')
        ->and($legacy->medical_record_number)->toBe('DG-FPN1-2024-17')
        ->and($legacy->manual_rm_number)->toBe('17')
        ->and($legacy->branch_id)->toBe($this->branch->id)
        ->and($legacy->registered_at->toDateString())->toBe('2024-03-04')
        ->and(fpanCounter($other))->toBeNull();
});

// =============================================================================
// The forms
// =============================================================================

it('AN-18 renders the master create form with the number read-only and no number, sequence or date input', function (): void {
    $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('otomatis')
        ->assertSee('DG-{KODE}-2026-####', false)
        ->assertDontSee('name="manual_rm_number"', false)
        ->assertDontSee('name="medical_record_number"', false)
        ->assertDontSee('name="registered_at"', false);
});

it('AN-19 renders the master edit form with the stored number, branch and date read-only', function (): void {
    $patient = Patient::factory()->create([
        'branch_id' => $this->branch->id,
        'medical_record_number' => 'DG-FPN1-2025-0042',
        'registered_at' => '2025-02-03',
    ]);

    $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.edit', $patient))
        ->assertOk()
        ->assertSee('DG-FPN1-2025-0042')
        ->assertDontSee('name="branch_id"', false)
        ->assertDontSee('name="manual_rm_number"', false)
        ->assertDontSee('name="medical_record_number"', false)
        ->assertDontSee('name="registered_at"', false);
});

it('AN-20 renders Kunjungan → Pasien Baru with the number read-only and the clinical year, no sequence or date input', function (): void {
    pinTestClock('2026-12-31 16:30:00');

    $this->actingAs(userWith(['view_clinic_visits', 'manage_clinic_visits']))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('otomatis')
        ->assertSee('DG-{KODE}-2027-####', false)
        ->assertDontSee('name="new_patient[manual_rm_number]"', false)
        ->assertDontSee('name="new_patient[registered_at]"', false)
        ->assertDontSee('name="new_patient[medical_record_number]"', false);
});

// =============================================================================
// Review fixes (PR-2 review, 2026-11-17)
// =============================================================================

it('AN-22 confines a context-bound desk account to its working branch on the master form (CG-05)', function (): void {
    $other = fpanBranch('FPN2');
    $frontOffice = User::factory()->create();
    rmeMakeFrontOfficeActive($frontOffice, $this->branch);

    // The form offers only the working branch.
    $this->actingAs($frontOffice)
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('data-code="FPN1"', false)
        ->assertDontSee('data-code="FPN2"', false);

    // A request naming another branch is refused (422) and consumes nothing.
    $this->actingAs($frontOffice)
        ->post(route('settings.patients.store'), fpanPayload(['branch_id' => $other->id, 'name' => 'Pasien Cabang Lain']))
        ->assertSessionHasErrors('branch_id');

    expect(Patient::query()->where('name', 'Pasien Cabang Lain')->exists())->toBeFalse()
        ->and(fpanCounter($other))->toBeNull();

    // The service refuses the same actor whatever the caller (403).
    expect(fn () => app(PatientService::class)->create(fpanPayload(['branch_id' => $other->id]), $frontOffice))
        ->toThrow(AuthorizationException::class);

    // Its own working branch works.
    $this->actingAs($frontOffice)
        ->post(route('settings.patients.store'), fpanPayload(['name' => 'Pasien Cabang Sendiri']))
        ->assertSessionHasNoErrors();

    expect(Patient::query()->where('name', 'Pasien Cabang Sendiri')->value('medical_record_number'))->toBe('DG-FPN1-2026-0001');
});

it('AN-23 keeps Kunjungan → Pasien Baru on the working branch: another branch in the request only narrows', function (): void {
    $other = fpanBranch('FPN2');
    $treatment = Treatment::factory()->create(['is_active' => true]);
    rmeMakeDoctorOnline($this->doctor, $this->branch);
    rmeMakeDoctorOnline($this->doctor, $other);
    $frontOffice = User::factory()->create();
    rmeMakeFrontOfficeActive($frontOffice, $this->branch);

    $response = $this->actingAs($frontOffice)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'branch_id' => $other->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $treatment->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
            'new_patient' => ['name' => 'Pasien Kunjungan Cabang Lain', 'branch_id' => $other->id],
        ]);

    // StoreClinicVisitRequest pins a pinned desk account's visit AND new
    // patient to its working branch, and ClinicVisitService::resolveBranchId()
    // re-asserts the scope: the request's other branch can only narrow, so the
    // patient lands in the working branch and the other branch's counter is
    // never touched.
    $response->assertSessionHasNoErrors();
    $patient = Patient::query()->where('name', 'Pasien Kunjungan Cabang Lain')->sole();

    expect($patient->branch_id)->toBe($this->branch->id)
        ->and($patient->medical_record_number)->toBe('DG-FPN1-2026-0001')
        ->and(fpanCounter($other))->toBeNull();
});

it('AN-24 retries only a Nomor RM collision: any other unique violation is rethrown untouched', function (): void {
    $probe = (object) ['calls' => 0];
    app()->instance(PatientRepositoryInterface::class, new class($probe) extends PatientRepository
    {
        public function __construct(private readonly object $probe) {}

        public function create(array $data): Patient
        {
            $this->probe->calls++;
            $pdo = new PDOException('SQLSTATE[23505]: duplicate key value violates unique constraint "mst_patients_other_unique"');
            $pdo->errorInfo = ['23505', 7, 'ERROR:  duplicate key value violates unique constraint "mst_patients_other_unique"'];

            throw new UniqueConstraintViolationException('pgsql', 'insert into mst_patients', [], $pdo);
        }
    });

    expect(fn () => app(PatientService::class)->create(fpanPayload()))
        ->toThrow(UniqueConstraintViolationException::class);

    expect($probe->calls)->toBe(1)
        ->and(fpanCounter($this->branch))->toBeNull();
});

it('AN-25 makes PatientService::update() drop the Nomor RM, the branch and the registration date', function (): void {
    $other = fpanBranch('FPN2');
    $patient = Patient::factory()->create([
        'branch_id' => $this->branch->id,
        'medical_record_number' => 'DG-FPN1-2025-0042',
        'manual_rm_number' => '0042',
        'registered_at' => '2025-02-03',
        'name' => 'Pasien Edit Langsung',
    ]);

    app(PatientService::class)->update($patient, [
        'name' => 'Pasien Edit Langsung Baru',
        'medical_record_number' => 'DG-FPN2-2026-0001',
        'manual_rm_number' => '0001',
        'branch_id' => $other->id,
        'registered_at' => '2026-06-13',
    ]);

    $patient->refresh();

    expect($patient->name)->toBe('Pasien Edit Langsung Baru')
        ->and($patient->medical_record_number)->toBe('DG-FPN1-2025-0042')
        ->and($patient->manual_rm_number)->toBe('0042')
        ->and($patient->branch_id)->toBe($this->branch->id)
        ->and($patient->registered_at->toDateString())->toBe('2025-02-03');
});

it('AN-26 counts a number zero-padded past nine digits as taken, everywhere (F1)', function (string $padding): void {
    $held = 'DG-FPN1-2026-'.$padding.'6';
    Patient::factory()->create(['branch_id' => $this->branch->id, 'medical_record_number' => $held]);
    fpanSeedCounter($this->branch, 5);
    $allocator = app(PatientRmNumberAllocator::class);

    expect($allocator->isTaken('DG-FPN1-2026-6'))->toBeTrue()
        ->and($allocator->isTaken('dg-fpn1-2026-0006'))->toBeTrue()
        ->and(app(PatientMedicalRecordNumberService::class)->exists('DG-FPN1-2026-0006'))->toBeTrue()
        ->and($allocator->takenAmong(['DG-FPN1-2026-6', 'DG-FPN1-2026-7']))->toBe(['DG-FPN1-2026-6' => true])
        ->and(fpanRegister()->medical_record_number)->toBe('DG-FPN1-2026-0007');
})->with([
    '10 digits' => [str_repeat('0', 9)],
    '12 digits' => [str_repeat('0', 11)],
    '19 digits' => [str_repeat('0', 18)],
    '30 digits' => [str_repeat('0', 29)],
]);
