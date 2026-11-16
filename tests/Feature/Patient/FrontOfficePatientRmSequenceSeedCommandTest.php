<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §1) —
| `patient:rm-sequence-seed`
|--------------------------------------------------------------------------
|
| Seeds the automatic Nomor RM counters from the numbers already issued, so the
| first automatic number of a branch-year continues after the highest one. Dry
| run by default (reads and counts only). `--apply` raises each counter to
| GREATEST(current, computed) — never lowers it — and writes one audit row of
| counts. Historical alias codes are folded onto their branch; non-numeric
| sequences, MAIN and unknown codes are counted, never seeded. The output never
| contains a Nomor RM. Running `--apply` on production is a separate,
| owner-approved step.
|
| Selected by the existing `FrontOffice` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `fprs` is unique because the critical gate loads every suite into one process.
*/

use App\Console\Commands\PatientRmSequenceSeedCommand;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientRmSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    // 2031-03-01 10:00 WITA: the clinic year is 2031.
    Carbon::setTestNow(Carbon::parse('2031-03-01 02:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function fprsBranch(string $code): Branch
{
    return Branch::factory()->create(['code' => $code, 'is_active' => true, 'is_rme_enabled' => true]);
}

function fprsPatient(Branch $branch, string $number): Patient
{
    return Patient::factory()->create(['branch_id' => $branch->id, 'medical_record_number' => $number]);
}

function fprsRun(array $options = []): array
{
    $code = Artisan::call('patient:rm-sequence-seed', $options);

    return [$code, Artisan::output()];
}

function fprsCounter(Branch $branch, int $year = 2031): ?int
{
    $value = PatientRmSequence::query()->where('branch_id', $branch->id)->where('year', $year)->value('last_number');

    return $value === null ? null : (int) $value;
}

it('SD-01 writes nothing on a dry run and prints neither a Nomor RM nor the highest sequence', function () {
    $branch = fprsBranch('FPS1');
    fprsPatient($branch, 'DG-FPS1-2031-0587');

    [$code, $out] = fprsRun();

    // The branch and year are printed, so the highest sequence would complete a
    // real patient's Nomor RM (the repository is public, CG-11): only its digit
    // count is printed, in no spelling the number itself.
    expect($code)->toBe(0)
        ->and($out)->toContain('PATIENT_RM_SEQUENCE_SEED=DRY_RUN')
        ->and($out)->toMatch('/^GROUPS=1$/m')
        ->and($out)->toContain('PROPOSED_COUNTER_DIGITS=3')
        ->and($out)->not->toContain('DG-')
        ->and($out)->not->toContain('FPS1-2031-')
        ->and($out)->not->toContain('0587')
        ->and($out)->not->toMatch('/(?<!\\d)587(?!\\d)/')
        ->and(PatientRmSequence::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', PatientRmSequenceSeedCommand::AUDIT_ACTION)->count())->toBe(0);
});

it('SD-02 raises each counter of the current clinic year to the highest issued number and audits counts only', function () {
    $branch = fprsBranch('FPS1');
    fprsPatient($branch, 'DG-FPS1-2031-7');
    fprsPatient($branch, 'DG-FPS1-2031-0042');
    fprsPatient($branch, 'DG-FPS1-2030-0900');

    [$code, $out] = fprsRun(['--apply' => true]);

    $audit = AuditLog::query()->where('action', PatientRmSequenceSeedCommand::AUDIT_ACTION)->sole();

    expect($code)->toBe(0)
        ->and($out)->toContain('PATIENT_RM_SEQUENCE_SEED=APPLIED')
        ->and($out)->not->toMatch('/(?<!\\d)0*42(?!\\d)/')
        ->and(fprsCounter($branch))->toBe(42)
        ->and(fprsCounter($branch, 2030))->toBeNull()
        ->and(PatientRmSequence::query()->where('branch_id', $branch->id)->value('seed_source'))->toBe(PatientRmSequence::SOURCE_COMMAND)
        ->and($audit->new_values['created'])->toBe(1)
        ->and(json_encode($audit->new_values))->not->toContain('DG-');
});

it('SD-03 never lowers a counter and is idempotent', function () {
    $branch = fprsBranch('FPS1');
    fprsPatient($branch, 'DG-FPS1-2031-0012');
    PatientRmSequence::query()->create([
        'branch_id' => $branch->id, 'year' => 2031, 'last_number' => 50,
        'seed_source' => PatientRmSequence::SOURCE_LAZY, 'seeded_at' => now(),
    ]);

    fprsRun(['--apply' => true]);
    [, $out] = fprsRun(['--apply' => true]);

    expect(fprsCounter($branch))->toBe(50)
        ->and($out)->toMatch('/^RAISED=0$/m')
        ->and($out)->toMatch('/^CREATED=0$/m');
});

it('SD-04 folds a historical alias code onto its branch', function () {
    $telkomas = fprsBranch('TLK1');
    fprsPatient($telkomas, 'DG-TKM1-2031-0090');
    fprsPatient($telkomas, 'DG-TLK1-2031-0031');

    fprsRun(['--apply' => true]);

    expect(fprsCounter($telkomas))->toBe(90)
        ->and(PatientRmSequence::query()->count())->toBe(1);
});

it('SD-05 counts non-numeric and very high numbers, and --ignore-above keeps the high ones out of the counter', function () {
    $branch = fprsBranch('FPS1');
    fprsPatient($branch, 'DG-FPS1-2031-0020');
    fprsPatient($branch, 'DG-FPS1-2031-A-7');
    fprsPatient($branch, 'DG-FPS1-2031-123456');

    [, $dry] = fprsRun();
    expect($dry)->toMatch('/^NON_NUMERIC=1$/m')
        ->and($dry)->toMatch('/^ABOVE_9999=1$/m');

    [, $out] = fprsRun(['--apply' => true, '--ignore-above' => 9999]);

    expect(fprsCounter($branch))->toBe(20)
        ->and($out)->toMatch('/^IGNORED_ABOVE=1$/m');
});

it('SD-06 seeds only the current clinic year unless --all-years is given', function () {
    $branch = fprsBranch('FPS1');
    fprsPatient($branch, 'DG-FPS1-2029-0300');

    fprsRun(['--apply' => true]);
    expect(fprsCounter($branch, 2029))->toBeNull();

    fprsRun(['--apply' => true, '--all-years' => true]);
    expect(fprsCounter($branch, 2029))->toBe(300);
});

it('SD-07 counts MAIN and unknown branch codes and never seeds them', function () {
    $main = fprsBranch(Branch::MAIN_CODE);
    fprsPatient($main, 'DG-MAIN-2031-0005');
    fprsPatient($main, 'DG-ZZZ9-2031-0005');

    [, $out] = fprsRun(['--apply' => true]);

    expect(PatientRmSequence::query()->count())->toBe(0)
        ->and($out)->toMatch('/^MAIN_NUMBERS=1$/m')
        ->and($out)->toMatch('/^UNKNOWN_CODE_NUMBERS=1$/m')
        ->and($out)->not->toContain('ZZZ9');
});

it('SD-08 refuses to run as root, or as a user it cannot identify, before it reads anything', function () {
    // The process cannot become root, nor lose the posix extension, inside a
    // test; the guard is pinned at the source, as the first statement of
    // handle(), like rme:billing-dispute-status. It fails CLOSED: without
    // posix_geteuid() the effective user is unknown and the run is refused,
    // never assumed not to be root.
    $source = (string) file_get_contents(app_path('Console/Commands/PatientRmSequenceSeedCommand.php'));
    $handle = substr($source, (int) strpos($source, 'public function handle('));
    $plan = (int) strpos($handle, '->plan(');

    expect($handle)->toContain('posix_geteuid() === 0')
        ->and(strpos($handle, 'posix_geteuid'))->toBeLessThan($plan)
        ->and($handle)->toContain("if (! function_exists('posix_geteuid')) {")
        ->and(strpos($handle, "if (! function_exists('posix_geteuid')) {"))->toBeLessThan($plan)
        ->and($handle)->not->toContain("function_exists('posix_geteuid') &&");
});

it('SD-09 leaves an existing number too wide for the allocator out of the counter, and counts it', function () {
    // A phone number typed as the sequence (12 digits) can never collide with an
    // issuable number (at most eight digits); seeding it would exhaust the
    // branch-year for good, because a counter is never lowered.
    $branch = fprsBranch('FPS1');
    fprsPatient($branch, 'DG-FPS1-2031-0020');
    fprsPatient($branch, 'DG-FPS1-2031-081234567890');

    [, $dry] = fprsRun();

    expect($dry)->toMatch('/^IGNORED_OVERSIZED=1$/m')
        ->and($dry)->toContain('PROPOSED_COUNTER_DIGITS=2')
        ->and($dry)->not->toContain('81234567890');

    [, $out] = fprsRun(['--apply' => true]);

    $audit = AuditLog::query()->where('action', PatientRmSequenceSeedCommand::AUDIT_ACTION)->sole();

    expect(fprsCounter($branch))->toBe(20)
        ->and($out)->toMatch('/^IGNORED_OVERSIZED=1$/m')
        ->and($audit->new_values['ignored_oversized'])->toBe(1);
});

it('SD-10 scopes to the clinic year at 00:30 WITA on 1 January, while UTC is still in the previous year', function () {
    // 2031-12-31 16:30 UTC is 2032-01-01 00:30 WITA: the clinic year is 2032.
    Carbon::setTestNow(Carbon::parse('2031-12-31 16:30:00', 'UTC'));

    $branch = fprsBranch('FPS1');
    fprsPatient($branch, 'DG-FPS1-2032-0005');
    fprsPatient($branch, 'DG-FPS1-2031-0900');

    [, $out] = fprsRun(['--apply' => true]);

    expect($out)->toMatch('/^SCOPE=YEAR_2032$/m')
        ->and(fprsCounter($branch, 2032))->toBe(5)
        ->and(fprsCounter($branch, 2031))->toBeNull();
});
