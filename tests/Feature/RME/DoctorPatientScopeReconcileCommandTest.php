<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 — PR A4 (rule 243 §2.13)
| `rme:doctor-assignments-reconcile`
|--------------------------------------------------------------------------
| Dry run by default: counts, and visit ids where they are safe, never a name,
| a NIK, a Nomor RM or a visit number. It reports
|  - the STALE auto_visit rows: active, and no live (non-cancelled,
|    non-deleted) visit of the patient has that doctor any more — the same
|    predicate revocation uses (§2.13), so a doctor change with the flag ON
|    leaves none and one with the flag OFF leaves one;
|  - the stale OPEN visits that keep a doctor's encounter window open (an open
|    status on a clinical date before today, WITA; §2.10 precondition);
|  - the roomless PRE-EXAM canonical anchors (§2.11), mirroring
|    PatientRmWorkspaceResolver::resolveCanonicalWorkspaceVisit().
| `--apply --confirm-plan=<digest>` ends exactly the planned stale rows by
| setting `unassigned_at` only (no other column, never a delete), refuses a
| missing or stale digest, writes one audit row of ids, and is idempotent.
|
| Selected by the existing `DoctorPatientScope` critical-gate token in both
| runner variants; declared in critical_gate_mandatory_suites. Helper prefix
| `dpsc` is unique (the critical gate loads every suite into one process).
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Services\PatientRmWorkspaceResolver;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RME\Services\PatientDoctorAssignmentService;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;

const DPSC_COMMAND = 'rme:doctor-assignments-reconcile';

const DPSC_AUDIT = 'PATIENT_DOCTOR_ASSIGNMENTS_RECONCILED';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-18 02:00:00', 'UTC')); // 10:00 WITA

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    test()->dpscBranch = Branch::factory()->create(['code' => 'SC01', 'is_rme_enabled' => true]);
    test()->dpscRoom = ClinicRoom::factory()->create(['branch_id' => test()->dpscBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $clinic = Clinic::factory()->create();

    foreach (['A', 'B'] as $name) {
        test()->{'dpscDoctor'.$name} = Doctor::factory()->create([
            'clinic_id' => $clinic->id,
            'branch_id' => test()->dpscBranch->id,
            'name' => 'drg. Rahasia Rekonsiliasi '.$name,
        ]);
    }
});

afterEach(function () {
    Carbon::setTestNow();
});

function dpscPatient(int $n): Patient
{
    return Patient::factory()->create([
        'branch_id' => test()->dpscBranch->id,
        'name' => 'Pasien Rahasia Rekonsiliasi '.$n,
        'ktp_number' => sprintf('73710101010100%02d', $n),
        'medical_record_number' => sprintf('DG-SC01-2026-%04d', 9000 + $n),
    ]);
}

function dpscVisit(Patient $patient, ?Doctor $doctor, string $status, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => test()->dpscBranch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor?->id,
        'clinic_room_id' => test()->dpscRoom->id,
        'status' => $status,
        'visit_date' => '2026-11-18',
    ], $overrides))->refresh();
}

function dpscRow(Patient $patient, Doctor $doctor, ?ClinicVisit $source, array $overrides = []): PatientDoctorAssignment
{
    return PatientDoctorAssignment::factory()->create(array_merge([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'branch_id' => test()->dpscBranch->id,
        'source_visit_id' => $source?->id,
        'assigned_at' => '2026-11-01 02:00:00',
        'assignment_type' => PatientDoctorAssignment::TYPE_AUTO_VISIT,
        'notes' => 'Auto-assigned from queue room assignment',
        'created_at' => '2026-11-01 02:00:00',
        'updated_at' => '2026-11-01 02:00:00',
    ], $overrides))->refresh();
}

/** @return array{0: int, 1: string} */
function dpscRun(array $options = []): array
{
    $code = Artisan::call(DPSC_COMMAND, $options);

    return [$code, Artisan::output()];
}

function dpscValue(string $output, string $key): ?string
{
    return preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $output, $m) === 1 ? trim($m[1]) : null;
}

/** @return list<int> */
function dpscIds(string $output, string $key): array
{
    $value = (string) dpscValue($output, $key);

    return $value === '' ? [] : array_map('intval', explode(',', $value));
}

/**
 * Three stale auto_visit rows (one per cause), four rows that must never be
 * touched, two stale open visits, two roomless pre-exam anchors.
 *
 * @return array<string, mixed>
 */
function dpscFixture(): array
{
    $a = test()->dpscDoctorA;
    $b = test()->dpscDoctorB;
    [$p1, $p2, $p3, $p4, $p5, $p6, $p7, $p8] = array_map('dpscPatient', range(1, 8));

    // P1: the queue visit's doctor changed from A to B (no revocation then).
    $v1 = dpscVisit($p1, $b, ClinicVisit::STATUS_WAITING);
    $staleChanged = dpscRow($p1, $a, $v1);
    $keepB = dpscRow($p1, $b, $v1);
    // P2: A's visit was cancelled.
    $v2 = dpscVisit($p2, $a, ClinicVisit::STATUS_CANCELLED, ['cancelled_at' => '2026-11-18 01:00:00']);
    $staleCancelled = dpscRow($p2, $a, $v2);
    $keepManual = dpscRow($p2, $b, null, ['assignment_type' => PatientDoctorAssignment::TYPE_MANUAL, 'notes' => null]);
    // P3: A's visit was soft-deleted.
    $v3 = dpscVisit($p3, $a, ClinicVisit::STATUS_WAITING);
    $v3->delete();
    $staleMissing = dpscRow($p3, $a, $v3);
    $alreadyEnded = dpscRow($p3, $b, null, ['unassigned_at' => '2026-11-05 02:00:00']);
    // P4: A examined the patient; the row is still true.
    $v4 = dpscVisit($p4, $a, ClinicVisit::STATUS_COMPLETED, ['visit_date' => '2026-11-03', 'completed_at' => '2026-11-03 03:00:00']);
    $keepTrue = dpscRow($p4, $a, $v4);
    // P5: the source visit was cancelled, but A still has another visit of P5.
    $v5 = dpscVisit($p5, $a, ClinicVisit::STATUS_CANCELLED, ['visit_date' => '2026-11-04']);
    dpscVisit($p5, $a, ClinicVisit::STATUS_COMPLETED, ['visit_date' => '2026-11-06', 'completed_at' => '2026-11-06 03:00:00']);
    $keepSupported = dpscRow($p5, $a, $v5);

    // Stale open visits (with a doctor, dated before today in WITA).
    $staleWaiting = dpscVisit($p6, $b, ClinicVisit::STATUS_WAITING, ['visit_date' => '2026-11-10']);
    $staleCashier = dpscVisit($p7, $a, ClinicVisit::STATUS_CASHIER_PENDING, ['visit_date' => '2026-11-17']);
    dpscVisit($p7, null, ClinicVisit::STATUS_REGISTERED, ['visit_date' => '2026-11-12']);              // no doctor
    dpscVisit($p6, $a, ClinicVisit::STATUS_IN_PROGRESS, ['visit_date' => '2026-11-11'])->delete();      // soft-deleted

    // Roomless pre-exam anchors. A roomless queue visit has no doctor yet (the
    // doctor comes with the room), so it keeps nobody's window open.
    $anchorStale = dpscVisit($p8, null, ClinicVisit::STATUS_REGISTERED, ['visit_date' => '2026-11-09', 'clinic_room_id' => null]);
    $p9 = dpscPatient(9);
    dpscVisit($p9, $a, ClinicVisit::STATUS_CANCELLED, ['visit_date' => '2026-11-01', 'clinic_room_id' => null]);
    $anchorToday = dpscVisit($p9, null, ClinicVisit::STATUS_REGISTERED, ['clinic_room_id' => null]);
    $p10 = dpscPatient(10); // its earliest visit is completed: a later roomless visit is no anchor
    dpscVisit($p10, $a, ClinicVisit::STATUS_COMPLETED, ['visit_date' => '2026-01-02', 'completed_at' => '2026-01-02 03:00:00']);
    dpscVisit($p10, null, ClinicVisit::STATUS_REGISTERED, ['clinic_room_id' => null]);
    $nonRme = Branch::factory()->create(['code' => 'SC02', 'is_rme_enabled' => false]);
    dpscVisit(dpscPatient(11), null, ClinicVisit::STATUS_REGISTERED, ['branch_id' => $nonRme->id, 'clinic_room_id' => null]);

    return [
        'stale' => [$staleChanged, $staleCancelled, $staleMissing],
        'kept' => [$keepB, $keepManual, $alreadyEnded, $keepTrue, $keepSupported],
        'staleOpen' => [$staleWaiting, $staleCashier],
        'anchors' => [$anchorStale, $anchorToday],
        'patients' => [$p1, $p2, $p3, $p4, $p5, $p6, $p7, $p8, $p9, $p10],
    ];
}

/** @return array<int, array<string, mixed>> every assignment row, as stored */
function dpscSnapshot(): array
{
    return PatientDoctorAssignment::query()->orderBy('id')->get()
        ->mapWithKeys(fn (PatientDoctorAssignment $row) => [(int) $row->id => $row->getRawOriginal()])
        ->all();
}

it('RC-01 reports the plan on a dry run and writes nothing', function () {
    $f = dpscFixture();
    $before = dpscSnapshot();

    [$code, $out] = dpscRun();

    expect($code)->toBe(0)
        ->and(dpscValue($out, 'DOCTOR_ASSIGNMENTS_RECONCILE'))->toBe('DRY_RUN')
        ->and(dpscValue($out, 'CLINICAL_TODAY'))->toBe('2026-11-18')
        ->and(dpscValue($out, 'ACTIVE_AUTO_VISIT'))->toBe('6')
        ->and(dpscValue($out, 'STALE_AUTO_VISIT'))->toBe('3')
        ->and(dpscValue($out, 'STALE_AUTO_VISIT_SOURCE_DOCTOR_CHANGED'))->toBe('1')
        ->and(dpscValue($out, 'STALE_AUTO_VISIT_SOURCE_CANCELLED'))->toBe('1')
        ->and(dpscValue($out, 'STALE_AUTO_VISIT_SOURCE_MISSING'))->toBe('1')
        ->and(dpscValue($out, 'ACTIVE_OTHER_TYPES_UNTOUCHED'))->toBe('1')
        ->and(dpscValue($out, 'STALE_OPEN_VISITS'))->toBe('2')
        ->and(dpscValue($out, 'STALE_OPEN_VISITS_WAITING'))->toBe('1')
        ->and(dpscValue($out, 'STALE_OPEN_VISITS_CASHIER_PENDING'))->toBe('1')
        ->and(dpscValue($out, 'STALE_OPEN_VISITS_REGISTERED'))->toBe('0')
        ->and(dpscValue($out, 'STALE_OPEN_VISITS_IN_PROGRESS'))->toBe('0')
        // P7's doctorless visit and P8's roomless anchor: counted, never listed.
        ->and(dpscValue($out, 'STALE_OPEN_VISITS_WITHOUT_DOCTOR'))->toBe('2')
        ->and(dpscIds($out, 'STALE_OPEN_VISIT_IDS'))->toBe(collect($f['staleOpen'])->map(fn ($v) => (int) $v->id)->sort()->values()->all())
        ->and(dpscValue($out, 'ROOMLESS_PRE_EXAM_ANCHORS'))->toBe('2')
        ->and(dpscValue($out, 'ROOMLESS_PRE_EXAM_ANCHORS_BEFORE_TODAY'))->toBe('1')
        ->and(dpscIds($out, 'ROOMLESS_PRE_EXAM_ANCHOR_IDS'))->toBe(collect($f['anchors'])->map(fn ($v) => (int) $v->id)->sort()->values()->all())
        ->and(dpscValue($out, 'PLAN_DIGEST'))->toMatch('/^[0-9a-f]{64}$/')
        ->and(dpscValue($out, 'ENDED'))->toBe('0')
        ->and(dpscSnapshot())->toBe($before)
        ->and(AuditLog::query()->where('action', DPSC_AUDIT)->count())->toBe(0);
});

it('RC-02 prints no patient or doctor data and no stale row id', function () {
    $f = dpscFixture();

    [, $out] = dpscRun();

    foreach ($f['patients'] as $patient) {
        expect($out)->not->toContain($patient->name)
            ->and($out)->not->toContain((string) $patient->ktp_number)
            ->and($out)->not->toContain((string) $patient->medical_record_number);
    }

    foreach (ClinicVisit::withTrashed()->get() as $visit) {
        expect($out)->not->toContain((string) $visit->visit_number);
    }

    expect($out)->not->toContain('Rahasia')
        ->and($out)->not->toContain('DG-SC01')
        ->and($out)->not->toContain('VIS-');
});

it('RC-03 mirrors the workspace anchor: every reported anchor is its patient\'s canonical visit', function () {
    $f = dpscFixture();
    $resolver = app(PatientRmWorkspaceResolver::class);

    [, $out] = dpscRun();

    $reported = dpscIds($out, 'ROOMLESS_PRE_EXAM_ANCHOR_IDS');
    $expected = Patient::query()->pluck('id')
        ->map(fn ($id) => $resolver->resolveCanonicalWorkspaceVisit((int) $id))
        ->filter(fn (?ClinicVisit $anchor) => $anchor !== null
            && $anchor->clinic_room_id === null
            && in_array($anchor->status, ClinicVisit::PRE_EXAM_STATUSES, true))
        ->map(fn (ClinicVisit $anchor) => (int) $anchor->id)
        ->sort()->values()->all();

    expect($reported)->toBe($expected)->and($reported)->not->toBe([]);
});

it('RC-04 refuses --apply without a digest or with a stale one, writing nothing', function () {
    dpscFixture();
    $before = dpscSnapshot();

    [$missing, $missingOut] = dpscRun(['--apply' => true]);
    [$wrong, $wrongOut] = dpscRun(['--apply' => true, '--confirm-plan' => str_repeat('0', 64)]);

    expect($missing)->toBe(1)
        ->and($missingOut)->toContain('--confirm-plan')
        ->and($wrong)->toBe(1)
        ->and($wrongOut)->toContain('PLAN_DIGEST_MISMATCH')
        ->and(dpscSnapshot())->toBe($before)
        ->and(AuditLog::query()->where('action', DPSC_AUDIT)->count())->toBe(0);
});

it('RC-05 ends exactly the planned stale rows by setting unassigned_at only, and audits their ids', function () {
    $f = dpscFixture();
    $before = dpscSnapshot();
    [, $dry] = dpscRun();

    [$code, $out] = dpscRun(['--apply' => true, '--confirm-plan' => dpscValue($dry, 'PLAN_DIGEST')]);
    $after = dpscSnapshot();
    $staleIds = collect($f['stale'])->map(fn ($row) => (int) $row->id)->sort()->values()->all();

    expect($code)->toBe(0)
        ->and(dpscValue($out, 'DOCTOR_ASSIGNMENTS_RECONCILE'))->toBe('APPLIED')
        ->and(dpscValue($out, 'ENDED'))->toBe('3')
        ->and(dpscValue($out, 'SKIPPED'))->toBe('0');

    foreach ($before as $id => $row) {
        if (in_array($id, $staleIds, true)) {
            expect($after[$id]['unassigned_at'])->not->toBeNull()
                ->and(Carbon::parse($after[$id]['unassigned_at'])->equalTo(now()))->toBeTrue()
                // Every other column, updated_at and the reason included, unchanged.
                ->and(array_diff_key($after[$id], ['unassigned_at' => true]))->toBe(array_diff_key($row, ['unassigned_at' => true]));

            continue;
        }

        expect($after[$id])->toBe($row);
    }

    $audit = AuditLog::query()->where('action', DPSC_AUDIT)->sole();

    expect(PatientDoctorAssignment::query()->count())->toBe(count($before))
        ->and($audit->entity_type)->toBe('trx_rme_patient_doctor_assignments')
        ->and($audit->entity_id)->toBeNull()
        ->and($audit->new_values['assignment_ids'])->toBe($staleIds)
        ->and($audit->new_values['ended'])->toBe(3)
        ->and($audit->new_values['plan_digest'])->toBe(dpscValue($dry, 'PLAN_DIGEST'))
        ->and(json_encode($audit->new_values))->not->toContain('Rahasia');
});

it('RC-06 is idempotent: a second run plans nothing and changes nothing', function () {
    dpscFixture();
    [, $dry] = dpscRun();
    dpscRun(['--apply' => true, '--confirm-plan' => dpscValue($dry, 'PLAN_DIGEST')]);
    $settled = dpscSnapshot();

    [, $again] = dpscRun();
    [$code, $out] = dpscRun(['--apply' => true, '--confirm-plan' => dpscValue($again, 'PLAN_DIGEST')]);

    expect(dpscValue($again, 'STALE_AUTO_VISIT'))->toBe('0')
        ->and(dpscValue($again, 'PLAN_DIGEST'))->not->toBe(dpscValue($dry, 'PLAN_DIGEST'))
        ->and($code)->toBe(0)
        ->and(dpscValue($out, 'ENDED'))->toBe('0')
        ->and(dpscSnapshot())->toBe($settled);
});

it('RC-07 refuses a digest the plan has drifted from', function () {
    dpscFixture();
    [, $dry] = dpscRun();

    // A further doctor change lands between the review and the apply.
    $p = dpscPatient(20);
    $v = dpscVisit($p, test()->dpscDoctorB, ClinicVisit::STATUS_WAITING);
    dpscRow($p, test()->dpscDoctorA, $v);
    $before = dpscSnapshot();

    [$code, $out] = dpscRun(['--apply' => true, '--confirm-plan' => dpscValue($dry, 'PLAN_DIGEST')]);

    expect($code)->toBe(1)
        ->and($out)->toContain('PLAN_DIGEST_MISMATCH')
        ->and(dpscSnapshot())->toBe($before);
});

it('RC-08 shares its stale predicate with revocation: none is left by a flag-ON doctor change, one by a flag-OFF one', function (bool $on) {
    $flags = config('feature_flags.flags', []);
    $flags['rme.doctor_encounter_scope']['default'] = $on;
    $flags['rme.doctor_encounter_scope']['env_value'] = null;
    config()->set('feature_flags.flags', $flags);

    $roomB = ClinicRoom::factory()->create(['branch_id' => test()->dpscBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    rmeMakeDoctorOnline(test()->dpscDoctorA, test()->dpscBranch, test()->dpscRoom);
    rmeMakeDoctorOnline(test()->dpscDoctorB, test()->dpscBranch, $roomB);
    $admin = User::factory()->create()->assignRole('Admin Klinik');
    $admin->givePermissionTo(['manage_clinic_visits', 'view_clinic_visits']);
    rmeMakeAdminClinicActive($admin, test()->dpscBranch);

    $visit = dpscVisit(dpscPatient(30), test()->dpscDoctorA, ClinicVisit::STATUS_WAITING);
    app(PatientDoctorAssignmentService::class)->ensureAssignedFromVisit($visit, $admin);
    app(ClinicVisitService::class)->assignRoom($visit, (int) $roomB->id, $admin->fresh());

    [, $out] = dpscRun();

    expect((int) $visit->fresh()->doctor_id)->toBe((int) test()->dpscDoctorB->id)
        ->and(dpscValue($out, 'STALE_AUTO_VISIT'))->toBe($on ? '0' : '1');
})->with(['flag ON' => [true], 'flag OFF' => [false]]);

it('RC-09 dates a stale open visit by the clinical calendar (WITA), never the UTC date', function () {
    // 00:30 WITA on 2026-11-18 — the UTC date is still 2026-11-17.
    Carbon::setTestNow(Carbon::parse('2026-11-17 16:30:00', 'UTC'));
    $p = dpscPatient(40);
    $yesterday = dpscVisit($p, test()->dpscDoctorA, ClinicVisit::STATUS_WAITING, ['visit_date' => '2026-11-17']);
    dpscVisit($p, test()->dpscDoctorA, ClinicVisit::STATUS_REGISTERED, ['visit_date' => '2026-11-18']);

    [, $out] = dpscRun();

    expect(dpscValue($out, 'CLINICAL_TODAY'))->toBe('2026-11-18')
        ->and(dpscIds($out, 'STALE_OPEN_VISIT_IDS'))->toBe([(int) $yesterday->id]);
});
