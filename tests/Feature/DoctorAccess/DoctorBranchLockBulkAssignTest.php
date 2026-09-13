<?php

/**
 * DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1 — the bulk home-branch assignment
 * adapter.
 *
 * WHAT THIS FILE IS ACTUALLY GUARDING
 *
 * The tool writes a clinician's PERMANENT branch, fourteen at a time, from a
 * terminal. Every assertion below exists because of a specific way that could
 * go wrong: a matrix that silently widens to the whole fleet, a branch inferred
 * from a doctor code, an initial assignment quietly performed as a transfer, one
 * person acting as both maker and checker, a plan approved against a state that
 * has since moved, or a doctor losing their session mid-consultation because a
 * batch did not stop to ask.
 *
 * WHY IT DOES NOT RE-TEST THE APPROVAL RULES
 *
 * It must not. Branch eligibility, the practice-set check, the row lock, the
 * session invalidation, the markOffline and the audit pair all belong to
 * DoctorBranchLockApprovalService and are proven by DoctorHomeBranchLockTest.
 * What is proven here is that this adapter REACHES that service and adds no
 * second opinion of its own — the failure mode being a CLI that drifts away
 * from the screen until the two disagree about what is legal.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Models\DoctorBranchLock;
use App\Modules\DoctorAccess\Services\DoctorBranchLockBulkAssignmentService;
use App\Modules\DoctorAccess\Support\DoctorBranchLockBulkAssignmentOutcome as Outcome;
use App\Modules\LabOrder\Models\AuditLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    seedAccessControl();
});

/** File-unique `bulkAssign` prefix: Pest shares helpers across files. */
function bulkAssignBranch(string $code): Branch
{
    return Branch::factory()->create([
        'code' => $code,
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
}

function bulkAssignDoctor(string $name, Branch ...$practice): array
{
    $user = User::factory()->create(['name' => $name, 'is_active' => true]);
    $user->assignRole('Doctor');

    $doctor = Doctor::factory()->create([
        'user_id' => $user->id,
        'name' => $name,
        'is_active' => true,
    ]);

    foreach ($practice as $branch) {
        $doctor->branches()->syncWithoutDetaching([$branch->id]);
    }

    return [$user, $doctor];
}

/** An actor holding exactly the permission their half of the workflow needs. */
function bulkAssignActor(string $permission): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo($permission);

    return $user;
}

function bulkAssignMaker(): User
{
    return bulkAssignActor('manage_doctor_branch_locks');
}

function bulkAssignChecker(): User
{
    return bulkAssignActor('approve_doctor_branch_locks');
}

function bulkAssignRun(array $options = []): array
{
    $exit = Artisan::call('doctor:branch-lock-bulk-assign', $options);

    return ['exit' => $exit, 'output' => Artisan::output()];
}

function bulkAssignLock(Doctor $doctor, Branch $branch): DoctorBranchLock
{
    $lock = new DoctorBranchLock;

    $lock->forceFill([
        'doctor_id' => $doctor->id,
        'home_branch_id' => $branch->id,
        'established_via' => DoctorBranchLock::VIA_INITIAL_ASSIGNMENT,
        'established_at' => now(),
        'transfer_count' => 0,
    ])->save();

    return $lock;
}

// ---------------------------------------------------------------------------
// Dry run writes nothing
// ---------------------------------------------------------------------------

it('writes absolutely nothing without --apply', function () {
    $branch = bulkAssignBranch('SPN4');
    [, $doctor] = bulkAssignDoctor('drg Dry', $branch);

    $result = bulkAssignRun([
        '--maker' => bulkAssignMaker()->id,
        '--checker' => bulkAssignChecker()->id,
        '--matrix' => $doctor->id.'=SPN4',
    ]);

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toContain(Outcome::PENDING_ASSIGNMENT);
    expect($result['output'])->toContain('PLAN_DIGEST=');

    expect(DB::table('mst_doctor_branch_locks')->count())->toBe(0);
    expect(DB::table('trx_doctor_branch_lock_requests')->count())->toBe(0);
    expect(AuditLog::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Maker / checker
// ---------------------------------------------------------------------------

it('refuses when maker and checker are the same user', function () {
    $branch = bulkAssignBranch('SPN4');
    [, $doctor] = bulkAssignDoctor('drg Same', $branch);

    // Deliberately holds BOTH permissions — the refusal must be about identity,
    // not about authority.
    $both = bulkAssignActor('manage_doctor_branch_locks');
    $both->givePermissionTo('approve_doctor_branch_locks');

    $result = bulkAssignRun([
        '--maker' => $both->id,
        '--checker' => $both->id,
        '--matrix' => $doctor->id.'=SPN4',
    ]);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('tidak boleh pengguna yang sama');
    expect(DB::table('mst_doctor_branch_locks')->count())->toBe(0);
});

it('refuses a maker who cannot file a branch lock request', function () {
    $branch = bulkAssignBranch('SPN4');
    [, $doctor] = bulkAssignDoctor('drg NoMaker', $branch);

    $result = bulkAssignRun([
        // Holds the APPROVE permission, not the FILE one.
        '--maker' => bulkAssignChecker()->id,
        '--checker' => bulkAssignChecker()->id,
        '--matrix' => $doctor->id.'=SPN4',
    ]);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('manage_doctor_branch_locks');
    expect($result['output'])->toContain('Akses shell bukan otorisasi bisnis');
});

it('refuses a checker who cannot approve a branch lock request', function () {
    $branch = bulkAssignBranch('SPN4');
    [, $doctor] = bulkAssignDoctor('drg NoChecker', $branch);

    $result = bulkAssignRun([
        '--maker' => bulkAssignMaker()->id,
        '--checker' => bulkAssignMaker()->id,
        '--matrix' => $doctor->id.'=SPN4',
    ]);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('approve_doctor_branch_locks');
});

// ---------------------------------------------------------------------------
// Fail closed
// ---------------------------------------------------------------------------

it('refuses an empty matrix rather than treating it as every doctor', function () {
    bulkAssignBranch('SPN4');
    [, $doctor] = bulkAssignDoctor('drg Untouched', Branch::query()->where('code', 'SPN4')->first());

    $result = bulkAssignRun([
        '--maker' => bulkAssignMaker()->id,
        '--checker' => bulkAssignChecker()->id,
    ]);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('tidak ada perilaku "semua dokter"');
    expect(DB::table('mst_doctor_branch_locks')->where('doctor_id', $doctor->id)->count())->toBe(0);
});

it('refuses a malformed matrix entry instead of skipping or coercing it', function () {
    $branch = bulkAssignBranch('SPN4');
    [, $doctor] = bulkAssignDoctor('drg Malformed', $branch);

    $result = bulkAssignRun([
        '--maker' => bulkAssignMaker()->id,
        '--checker' => bulkAssignChecker()->id,
        '--matrix' => $doctor->id.'=SPN4,notanid=SPN4',
    ]);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain(Outcome::REFUSED);
    expect($result['output'])->toContain(Outcome::REASON_MALFORMED_ENTRY);
});

it('refuses a branch code that is not RME enabled', function () {
    $main = Branch::factory()->create(['code' => 'MAIN', 'is_active' => true, 'is_rme_enabled' => false]);
    [, $doctor] = bulkAssignDoctor('drg Main', $main);

    $result = bulkAssignRun([
        '--maker' => bulkAssignMaker()->id,
        '--checker' => bulkAssignChecker()->id,
        '--matrix' => $doctor->id.'=MAIN',
    ]);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain(Outcome::REASON_BRANCH_NOT_RME);
});

it('refuses a branch outside the doctor\'s practice set', function () {
    $practice = bulkAssignBranch('SPN4');
    $elsewhere = bulkAssignBranch('LDK2');
    [, $doctor] = bulkAssignDoctor('drg Outside', $practice);

    $result = bulkAssignRun([
        '--maker' => bulkAssignMaker()->id,
        '--checker' => bulkAssignChecker()->id,
        '--matrix' => $doctor->id.'='.$elsewhere->code,
    ]);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain(Outcome::REASON_BRANCH_NOT_IN_PRACTICE_SET);
});

it('refuses the same doctor named twice rather than deciding which entry wins', function () {
    $a = bulkAssignBranch('SPN4');
    $b = bulkAssignBranch('LDK2');
    [, $doctor] = bulkAssignDoctor('drg Twice', $a, $b);

    $result = bulkAssignRun([
        '--maker' => bulkAssignMaker()->id,
        '--checker' => bulkAssignChecker()->id,
        '--matrix' => $doctor->id.'=SPN4,'.$doctor->id.'=LDK2',
    ]);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain(Outcome::REASON_DUPLICATE_DOCTOR);
});

// ---------------------------------------------------------------------------
// Preconditions
// ---------------------------------------------------------------------------

it('classifies a doctor already locked to the same branch as satisfied, not as work', function () {
    $branch = bulkAssignBranch('SPN4');
    [, $doctor] = bulkAssignDoctor('drg Settled', $branch);
    bulkAssignLock($doctor, $branch);

    $plan = app(DoctorBranchLockBulkAssignmentService::class)->plan([[(int) $doctor->id, 'SPN4']]);

    expect($plan['rows'][0]['classification'])->toBe(Outcome::ALREADY_SATISFIED);
    expect($plan['summary'][Outcome::PENDING_ASSIGNMENT] ?? 0)->toBe(0);
});

it('classifies a doctor locked elsewhere as TRANSFER_REQUIRED and never overwrites the lock', function () {
    $current = bulkAssignBranch('SPN4');
    $wanted = bulkAssignBranch('LDK2');
    [, $doctor] = bulkAssignDoctor('drg Moved', $current, $wanted);
    bulkAssignLock($doctor, $current);

    $service = app(DoctorBranchLockBulkAssignmentService::class);
    $plan = $service->plan([[(int) $doctor->id, 'LDK2']]);

    expect($plan['rows'][0]['classification'])->toBe(Outcome::TRANSFER_REQUIRED);

    $service->apply($plan, bulkAssignMaker(), bulkAssignChecker(), 'fleet readiness');

    // The lock is untouched: an initial assignment must never silently become a
    // permanent transfer.
    expect((int) DB::table('mst_doctor_branch_locks')->where('doctor_id', $doctor->id)->value('home_branch_id'))
        ->toBe((int) $current->id);
    expect(DB::table('trx_doctor_branch_lock_requests')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// No inference
// ---------------------------------------------------------------------------

it('never infers a branch from the doctor code when the matrix omits the doctor', function () {
    $spn = bulkAssignBranch('SPN4');
    $ldk = bulkAssignBranch('LDK2');

    [, $named] = bulkAssignDoctor('drg Named', $spn, $ldk);
    [, $omitted] = bulkAssignDoctor('drg Omitted', $spn, $ldk);
    $omitted->forceFill(['code' => 'DOC-SPN999'])->save();

    $plan = app(DoctorBranchLockBulkAssignmentService::class)->plan([[(int) $named->id, 'LDK2']]);

    // Exactly one row. The doctor whose CODE names a branch is simply absent,
    // because a code is not an instruction.
    expect($plan['rows'])->toHaveCount(1);
    expect($plan['rows'][0]['doctor_id'])->toBe((int) $named->id);
    expect($plan['rows'][0]['branch_code'])->toBe('LDK2');
});

// ---------------------------------------------------------------------------
// Plan digest
// ---------------------------------------------------------------------------

it('refuses --apply without a confirmed plan digest', function () {
    $branch = bulkAssignBranch('SPN4');
    [, $doctor] = bulkAssignDoctor('drg NoDigest', $branch);

    $result = bulkAssignRun([
        '--maker' => bulkAssignMaker()->id,
        '--checker' => bulkAssignChecker()->id,
        '--matrix' => $doctor->id.'=SPN4',
        '--apply' => true,
    ]);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('--confirm-plan=');
    expect(DB::table('mst_doctor_branch_locks')->count())->toBe(0);
});

it('refuses a stale digest when production state moved between preview and write', function () {
    $branch = bulkAssignBranch('SPN4');
    $other = bulkAssignBranch('LDK2');
    [, $doctor] = bulkAssignDoctor('drg Drift', $branch, $other);

    $stale = app(DoctorBranchLockBulkAssignmentService::class)
        ->plan([[(int) $doctor->id, 'SPN4']])['digest'];

    // Somebody assigns the doctor through the screen in the meantime.
    bulkAssignLock($doctor, $other);

    $result = bulkAssignRun([
        '--maker' => bulkAssignMaker()->id,
        '--checker' => bulkAssignChecker()->id,
        '--matrix' => $doctor->id.'=SPN4',
        '--apply' => true,
        '--confirm-plan' => $stale,
        '--reason' => 'fleet readiness',
    ]);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('basi');
    expect((int) DB::table('mst_doctor_branch_locks')->where('doctor_id', $doctor->id)->value('home_branch_id'))
        ->toBe((int) $other->id);
});

// ---------------------------------------------------------------------------
// Applying, and what it must leave behind
// ---------------------------------------------------------------------------

it('assigns through the canonical service, one auditable request and approval per doctor', function () {
    $branch = bulkAssignBranch('SPN4');
    [, $one] = bulkAssignDoctor('drg One', $branch);
    [, $two] = bulkAssignDoctor('drg Two', $branch);

    $maker = bulkAssignMaker();
    $checker = bulkAssignChecker();

    $plan = app(DoctorBranchLockBulkAssignmentService::class)
        ->plan([[(int) $one->id, 'SPN4'], [(int) $two->id, 'SPN4']]);

    $result = bulkAssignRun([
        '--maker' => $maker->id,
        '--checker' => $checker->id,
        '--matrix' => $one->id.'=SPN4,'.$two->id.'=SPN4',
        '--apply' => true,
        '--confirm-plan' => $plan['digest'],
        '--reason' => 'fleet rollout readiness assignment',
    ]);

    expect($result['exit'])->toBe(0);

    // Two locks, two requests, and the request/approval audit pair for each —
    // never one collapsed bulk row.
    expect(DB::table('mst_doctor_branch_locks')->count())->toBe(2);
    expect(DB::table('trx_doctor_branch_lock_requests')->where('status', 'approved')->count())->toBe(2);
    expect(AuditLog::query()->where('action', 'DOCTOR_BRANCH_LOCK_REQUESTED')->count())->toBe(2);
    expect(AuditLog::query()->where('action', 'DOCTOR_BRANCH_LOCK_APPROVED')->count())->toBe(2);

    foreach ([$one, $two] as $doctor) {
        $request = DB::table('trx_doctor_branch_lock_requests')->where('doctor_id', $doctor->id)->first();

        expect((int) $request->requester_user_id)->toBe((int) $maker->id);
        expect((int) $request->decided_by_user_id)->toBe((int) $checker->id);
        expect($request->request_type)->toBe('initial_assignment');
    }
});

it('proposes nothing on a second run, so the tool is idempotent', function () {
    $branch = bulkAssignBranch('SPN4');
    [, $doctor] = bulkAssignDoctor('drg Once', $branch);

    $service = app(DoctorBranchLockBulkAssignmentService::class);
    $matrix = [[(int) $doctor->id, 'SPN4']];

    $service->apply($service->plan($matrix), bulkAssignMaker(), bulkAssignChecker(), 'fleet readiness');

    $second = $service->plan($matrix);

    expect($second['summary'][Outcome::PENDING_ASSIGNMENT] ?? 0)->toBe(0);
    expect($second['rows'][0]['classification'])->toBe(Outcome::ALREADY_SATISFIED);
    expect(DB::table('mst_doctor_branch_locks')->count())->toBe(1);
});

it('touches no device, authorization, credential or feature flag', function () {
    $branch = bulkAssignBranch('SPN4');
    [, $doctor] = bulkAssignDoctor('drg Isolated', $branch);

    $before = [
        'devices' => DB::table('mst_doctor_devices')->count(),
        'authorizations' => DB::table('mst_doctor_device_authorizations')->count(),
        'credentials' => DB::table('trx_doctor_device_webauthn_credentials')->count(),
        'single_session' => config('feature_flags.flags.doctor.single_active_session.enabled'),
        'branch_lock_flag' => config('feature_flags.flags.doctor.branch_lock.enabled'),
    ];

    $service = app(DoctorBranchLockBulkAssignmentService::class);
    $matrix = [[(int) $doctor->id, 'SPN4']];
    $service->apply($service->plan($matrix), bulkAssignMaker(), bulkAssignChecker(), 'fleet readiness');

    expect(DB::table('mst_doctor_devices')->count())->toBe($before['devices']);
    expect(DB::table('mst_doctor_device_authorizations')->count())->toBe($before['authorizations']);
    expect(DB::table('trx_doctor_device_webauthn_credentials')->count())->toBe($before['credentials']);
    expect(config('feature_flags.flags.doctor.single_active_session.enabled'))->toBe($before['single_session']);
    expect(config('feature_flags.flags.doctor.branch_lock.enabled'))->toBe($before['branch_lock_flag']);
});

it('contains no raw SQL write and no flag mutation in its source', function () {
    $forbidden = ['DB::update', 'DB::insert', 'DB::delete', 'DB::statement', 'putenv(', 'Config::set', 'config(['];

    foreach ([
        'app/Modules/DoctorAccess/Services/DoctorBranchLockBulkAssignmentService.php',
        'app/Console/Commands/DoctorBranchLockBulkAssignCommand.php',
    ] as $file) {
        $source = (string) file_get_contents(base_path($file));

        foreach ($forbidden as $primitive) {
            expect($source)->not->toContain($primitive, "{$file} must not contain {$primitive}");
        }
    }
});

// ---------------------------------------------------------------------------
// Adversarial review regressions — every one of these was a real defect
// ---------------------------------------------------------------------------

it('refuses to execute a plan whose world moved, rather than performing a transfer', function () {
    $wanted = bulkAssignBranch('SPN4');
    $other = bulkAssignBranch('LDK2');
    [, $doctor] = bulkAssignDoctor('drg Raced', $wanted, $other);

    $service = app(DoctorBranchLockBulkAssignmentService::class);
    $plan = $service->plan([[(int) $doctor->id, 'SPN4']]);

    expect($plan['rows'][0]['classification'])->toBe(Outcome::PENDING_ASSIGNMENT);

    // Somebody locks the doctor through the approval screen between the dry run
    // and the write. The frozen plan still says PENDING_ASSIGNMENT.
    bulkAssignLock($doctor, $other);

    $outcome = $service->apply($plan, bulkAssignMaker(), bulkAssignChecker(), 'fleet readiness');

    // Acting on the stale string would have filed an initial_assignment that the
    // approval service re-derives as a TRANSFER and executes — the one operation
    // this tool documents that it never performs.
    expect($outcome['refused'] ?? null)->toBe('plan_is_stale');
    expect(DB::table('trx_doctor_branch_lock_requests')->count())->toBe(0);
    expect((int) DB::table('mst_doctor_branch_locks')->where('doctor_id', $doctor->id)->value('home_branch_id'))
        ->toBe((int) $other->id);
});

it('refuses BOTH occurrences of a duplicated doctor, never first-wins', function () {
    $a = bulkAssignBranch('SPN4');
    $b = bulkAssignBranch('LDK2');
    [, $doctor] = bulkAssignDoctor('drg Contradicted', $a, $b);

    $service = app(DoctorBranchLockBulkAssignmentService::class);
    $plan = $service->plan([[(int) $doctor->id, 'SPN4'], [(int) $doctor->id, 'LDK2']]);

    expect($plan['summary'][Outcome::PENDING_ASSIGNMENT] ?? 0)->toBe(0);
    expect($plan['summary'][Outcome::REFUSED] ?? 0)->toBe(2);

    $service->apply($plan, bulkAssignMaker(), bulkAssignChecker(), 'fleet readiness');

    // Writing the first and refusing the second would be this tool deciding
    // which of two contradictory owner instructions is the real one.
    expect(DB::table('mst_doctor_branch_locks')->count())->toBe(0);
});

it('refuses a JSON matrix file that is a list, so array position cannot become a doctor id', function () {
    $branch = bulkAssignBranch('SPN4');
    bulkAssignDoctor('drg Listed', $branch);

    $path = sys_get_temp_dir().'/fleet-matrix-'.bin2hex(random_bytes(6)).'.json';
    file_put_contents($path, json_encode(['SPN4', 'SPN4']));

    try {
        $result = bulkAssignRun([
            '--maker' => bulkAssignMaker()->id,
            '--checker' => bulkAssignChecker()->id,
            '--matrix-file' => $path,
        ]);

        expect($result['exit'])->toBe(1);
        expect($result['output'])->toContain('bukan daftar');
        expect(DB::table('mst_doctor_branch_locks')->count())->toBe(0);
    } finally {
        @unlink($path);
    }
});

it('refuses an empty matrix segment instead of silently dropping it', function () {
    $branch = bulkAssignBranch('SPN4');
    [, $doctor] = bulkAssignDoctor('drg Blanked', $branch);

    $result = bulkAssignRun([
        '--maker' => bulkAssignMaker()->id,
        '--checker' => bulkAssignChecker()->id,
        '--matrix' => $doctor->id.'=SPN4,,',
    ]);

    // A blanked position vanishing without a row shows the operator a clean plan
    // for a matrix that is not the one they approved.
    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('Entri matriks kosong');
});

it('leaves no orphan PENDING request behind when an approval refuses', function () {
    $branch = bulkAssignBranch('SPN4');
    [, $doctor] = bulkAssignDoctor('drg Orphan', $branch);

    $maker = bulkAssignMaker();

    // A checker who is the subject doctor's own account is refused by the
    // service at approval time, AFTER the request has been filed.
    $service = app(DoctorBranchLockBulkAssignmentService::class);
    $plan = $service->plan([[(int) $doctor->id, 'SPN4']]);
    $subjectChecker = User::query()->whereKey($doctor->user_id)->first();
    $subjectChecker->givePermissionTo('approve_doctor_branch_locks');

    $outcome = $service->apply($plan, $maker, $subjectChecker, 'fleet readiness');

    expect($outcome['rows'][0]['classification'])->toBe(Outcome::FAILED);
    expect(DB::table('mst_doctor_branch_locks')->count())->toBe(0);

    // One PENDING row per doctor is all the schema allows, so an orphan would
    // block the correct request somebody files next.
    expect(DB::table('trx_doctor_branch_lock_requests')->where('status', 'pending')->count())->toBe(0);
});
