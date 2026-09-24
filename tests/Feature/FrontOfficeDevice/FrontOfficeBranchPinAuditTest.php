<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\FrontOfficeDevice\Services\FrontOfficeBranchDeviceLockService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Support\AccessControl\FrontOfficeBranchPinAuditor;
use App\Support\AccessControl\FrontOfficeBranchPinResolver;
use App\Support\AccessControl\FrontOfficeRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| SUNU-GO-LIVE-READINESS-1 — the Front Office branch pin AUDITOR
|--------------------------------------------------------------------------
|
| The capability itself is proven by FrontOfficeBranchContextLockTest. What is
| under test here is the INSTRUMENT: before this auditor existed, the armed state
| of a critical-risk lock could only be established by reading an environment
| string and reasoning about it, or by opening a REPL on production.
|
| An instrument earns nothing by reporting GO. Half of the cases below therefore
| force a BROKEN state and assert the auditor says so — an audit whose failure
| path is dead would have certified the Sunu go-live just as cheerfully.
*/

beforeEach(function () {
    seedAccessControl();
});

function fbpaFlags(bool $context, bool $device): void
{
    $flags = config('feature_flags.flags', []);
    $flags[FrontOfficeBranchPinResolver::FLAG]['default'] = $context;
    $flags[FrontOfficeBranchDeviceLockService::FLAG]['default'] = $device;
    config()->set('feature_flags.flags', $flags);
}

function fbpaCohort(string $cohort): void
{
    config()->set('front_office_device_lock.scope.cohort', $cohort);
}

function fbpaBranch(string $code): Branch
{
    return Branch::factory()->create([
        'code' => $code,
        'name' => 'Cabang '.$code,
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
}

function fbpaUser(string $email): User
{
    return User::factory()
        ->create(['email' => $email, 'password' => Hash::make('password')])
        ->assignRole(FrontOfficeRole::NAME);
}

function fbpaAudit(): array
{
    return app(FrontOfficeBranchPinAuditor::class)->audit();
}

/** @param array<string, mixed> $report */
function fbpaAccount(array $report, int $userId): array
{
    foreach ($report['accounts'] as $account) {
        if ($account['user_id'] === $userId) {
            return $account;
        }
    }

    throw new RuntimeException("user {$userId} missing from the audit");
}

it('reports nobody pinned while the committed cohort is empty', function () {
    fbpaBranch('SPN4');
    $user = fbpaUser('fo-empty@example.test');

    fbpaFlags(context: true, device: false);

    $report = fbpaAudit();

    expect($report['decision'])->toBe('GO')
        ->and($report['cohort']['empty'])->toBeTrue()
        ->and($report['cohort']['armed_count'])->toBe(0)
        ->and(fbpaAccount($report, $user->id)['armed'])->toBeFalse()
        ->and(fbpaAccount($report, $user->id)['pinned_branch_id'])->toBeNull();
});

it('names exactly the armed account and leaves its siblings unpinned', function () {
    $sunu = fbpaBranch('SPN4');
    fbpaBranch('LDK2');

    $armed = fbpaUser('fo-sunu@example.test');
    $sibling = fbpaUser('fo-landak@example.test');

    fbpaCohort($armed->id.':SPN4');
    fbpaFlags(context: true, device: false);

    $report = fbpaAudit();

    expect($report['decision'])->toBe('GO')
        ->and($report['cohort']['armed_count'])->toBe(1);

    $armedRow = fbpaAccount($report, $armed->id);
    expect($armedRow['armed'])->toBeTrue()
        ->and($armedRow['pinned_branch_id'])->toBe($sunu->id)
        ->and($armedRow['pinned_branch_code'])->toBe('SPN4');

    // The sibling is untouched because it is ABSENT from the cohort.
    $siblingRow = fbpaAccount($report, $sibling->id);
    expect($siblingRow['armed'])->toBeFalse()
        ->and($siblingRow['pinned_branch_id'])->toBeNull();
});

it('reports the device-proof requirement separately from the pin', function () {
    fbpaBranch('SPN4');
    $user = fbpaUser('fo-separation@example.test');
    fbpaCohort($user->id.':SPN4');

    // BRANCH CONTEXT LOCK != DEVICE PROOF REQUIREMENT.
    fbpaFlags(context: true, device: false);
    $contextOnly = fbpaAudit();

    expect($contextOnly['pinning']['pinning_enabled'])->toBeTrue()
        ->and($contextOnly['pinning']['device_proof_required_for_armed'])->toBeFalse()
        ->and(fbpaAccount($contextOnly, $user->id)['device_proof_required'])->toBeFalse();

    // DEVICE LOCK IMPLIES BRANCH PIN — pinned even with the context flag off.
    fbpaFlags(context: false, device: true);
    $deviceOnly = fbpaAudit();

    expect($deviceOnly['pinning']['pinning_enabled'])->toBeTrue()
        ->and($deviceOnly['pinning']['device_proof_required_for_armed'])->toBeTrue()
        ->and(fbpaAccount($deviceOnly, $user->id)['pinned_branch_id'])->not->toBeNull();
});

it('fails an oversized cohort instead of reporting a working lock', function () {
    fbpaBranch('SPN4');
    fbpaBranch('LDK2');
    fbpaBranch('ATG3');
    fbpaBranch('TLK1');

    $users = collect(range(1, 5))->map(fn ($i) => fbpaUser("fo-over{$i}@example.test"));
    $codes = ['SPN4', 'LDK2', 'ATG3', 'TLK1', 'SPN4'];

    fbpaCohort($users->values()->map(fn ($u, $i) => $u->id.':'.$codes[$i])->implode(','));
    fbpaFlags(context: true, device: false);

    $report = fbpaAudit();

    /*
     * The oversized anomaly is asserted BY NAME.
     *
     * An earlier version of this test only checked `decision === 'FAIL'`, and a
     * mutant that deleted the oversize check survived it: five armed accounts
     * are each individually undecidable, so the per-account anomalies already
     * forced FAIL and the assertion passed for the wrong reason. A verdict that
     * cannot say WHICH rule fired cannot prove that rule still exists.
     */
    expect($report['decision'])->toBe('FAIL')
        ->and($report['cohort']['oversized'])->toBeTrue()
        ->and(implode(' | ', $report['anomalies']))->toContain('OVERSIZED');

    // Fails CLOSED: an oversized cohort pins nobody.
    foreach ($users as $user) {
        expect(fbpaAccount($report, $user->id)['pinned_branch_id'])->toBeNull();
    }
});

it('fails an armed account whose branch code is outside the approved allowlist', function () {
    fbpaBranch('SPN4');
    fbpaBranch('MAIN');
    $user = fbpaUser('fo-badbranch@example.test');

    fbpaCohort($user->id.':MAIN');
    fbpaFlags(context: true, device: false);

    $report = fbpaAudit();

    expect($report['decision'])->toBe('FAIL');

    $row = fbpaAccount($report, $user->id);
    expect($row['misconfigured'])->toBeTrue()
        ->and($row['pinned_branch_id'])->toBeNull();
});

it('surfaces an armed id that is not a Front Office account rather than omitting it', function () {
    fbpaBranch('SPN4');

    // A mistyped id that lands on a doctor must not vanish from the report:
    // "nothing listed" would otherwise read as "nothing wrong".
    $doctorUser = User::factory()->create(['email' => 'not-fo@example.test'])->assignRole('Doctor');

    fbpaCohort($doctorUser->id.':SPN4');
    fbpaFlags(context: true, device: false);

    $report = fbpaAudit();

    expect($report['decision'])->toBe('FAIL')
        ->and(implode(' ', $report['anomalies']))->toContain((string) $doctorUser->id)
        ->and(array_column($report['accounts'], 'user_id'))->not->toContain($doctorUser->id);
});

it('shows a stale context resolving to NO registration branch, never to the wider one', function () {
    $sunu = fbpaBranch('SPN4');
    $stale = fbpaBranch('LDK2');
    $user = fbpaUser('fo-stale@example.test');

    // A context selected BEFORE the account was armed, pointing elsewhere.
    fbpaFlags(context: false, device: false);
    app(UserOnlineContextService::class)->startAdminClinicSession($user, $stale->id);

    fbpaCohort($user->id.':SPN4');
    fbpaFlags(context: true, device: false);

    $report = fbpaAudit();
    $row = fbpaAccount($report, $user->id);

    /*
     * FAIL CLOSED MEANS "NO BRANCH", NOT "THE PINNED BRANCH".
     *
     * The stale row is refused rather than silently rewritten, so registration
     * has NO working branch until the operator re-selects. The value that must
     * never appear here is the STALE one: that would be the split brain
     * REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 closed, where BranchContext
     * read as narrowed while clinical records were still being created wide.
     */
    expect($row['pinned_branch_id'])->toBe($sunu->id)
        ->and($row['branch_context_branch_id'])->toBe($sunu->id)
        ->and($row['operational_branch_id'])->not->toBe($stale->id)
        ->and($row['operational_branch_id'])->toBeNull()
        ->and($report['decision'])->toBe('GO');
});

it('narrows the session row itself when no daily context covers the day', function () {
    $sunu = fbpaBranch('SPN4');
    $stale = fbpaBranch('LDK2');
    $user = fbpaUser('fo-nodaily@example.test');

    fbpaFlags(context: false, device: false);
    app(UserOnlineContextService::class)->startAdminClinicSession($user, $stale->id);

    /*
     * FOUND BY MUTATION, NOT BY READING.
     *
     * `activeContextBranchId()` narrows at TWO sites: the daily-context branch
     * and the raw session row. For a Front Office account `admin_clinic` is a
     * DAILY-LOCKED role, so every other test in this module reaches only the
     * first one — deleting the narrowing from the second changed no result
     * anywhere in the suite.
     *
     * The second site is still reachable in production: a session row that
     * survives into a new clinical day has no daily context covering it yet,
     * and until the operator selects again that raw row is the only thing
     * registration has to go on. Without this case, the guard protecting that
     * window could be deleted and every test would stay green.
     */
    DB::table('trx_daily_branch_contexts')->where('user_id', $user->id)->delete();

    fbpaCohort($user->id.':SPN4');
    fbpaFlags(context: true, device: false);

    $row = fbpaAccount(fbpaAudit(), $user->id);

    expect($row['pinned_branch_id'])->toBe($sunu->id)
        ->and($row['operational_branch_id'])->not->toBe($stale->id)
        ->and($row['operational_branch_id'])->toBeNull();
});

it('reports every branch authority agreeing once the context is on the pinned branch', function () {
    $sunu = fbpaBranch('SPN4');
    fbpaBranch('LDK2');
    $user = fbpaUser('fo-aligned@example.test');

    fbpaCohort($user->id.':SPN4');
    fbpaFlags(context: true, device: false);
    app(UserOnlineContextService::class)->startAdminClinicSession($user, $sunu->id);

    $report = fbpaAudit();
    $row = fbpaAccount($report, $user->id);

    // The production-meaningful state: pin, BranchContext and the branch a NEW
    // VISIT registers at are ONE answer. This is what Admin Sunu looks like.
    expect($row['pinned_branch_id'])->toBe($sunu->id)
        ->and($row['branch_context_branch_id'])->toBe($sunu->id)
        ->and($row['operational_branch_id'])->toBe($sunu->id)
        ->and($row['branch_context_agrees'])->toBeTrue()
        ->and($row['operational_branch_agrees'])->toBeTrue()
        ->and($report['decision'])->toBe('GO');
});

it('exits 2 under --strict only when an anomaly remains', function () {
    fbpaBranch('SPN4');
    $user = fbpaUser('fo-strict@example.test');

    fbpaCohort($user->id.':SPN4');
    fbpaFlags(context: true, device: false);
    $this->artisan('rbac:front-office-branch-pin-audit --strict')->assertExitCode(0);

    fbpaCohort($user->id.':NOPE');
    $this->artisan('rbac:front-office-branch-pin-audit --strict')->assertExitCode(2);

    // Without --strict the report is informational and never breaks a shell.
    $this->artisan('rbac:front-office-branch-pin-audit')->assertExitCode(0);
});

it('leaves an EXPIRED online context exactly as it found it', function () {
    $sunu = fbpaBranch('SPN4');
    $user = fbpaUser('fo-expired@example.test');
    app(UserOnlineContextService::class)->startAdminClinicSession($user, $sunu->id);

    /*
     * THE CASE THE FIRST VERSION OF THIS SUITE MISSED.
     *
     * Every other fixture here creates a FRESH context, which is never expired,
     * so the lazy garbage-collection branch inside `currentContextFor()` never
     * ran and the "writes nothing" assertion passed against an auditor that
     * did, in fact, write. It was caught only by running the command against
     * production, where it flipped a real front-desk session from `online` to
     * `inactive`.
     *
     * Age the row past its TTL so the expiry path is the one under test.
     */
    DB::table('trx_user_online_contexts')
        ->where('user_id', $user->id)
        ->update([
            'last_seen_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

    $before = DB::table('trx_user_online_contexts')->where('user_id', $user->id)->first();

    fbpaCohort($user->id.':SPN4');
    fbpaFlags(context: true, device: false);

    $this->artisan('rbac:front-office-branch-pin-audit --json')->assertExitCode(0);

    $after = DB::table('trx_user_online_contexts')->where('user_id', $user->id)->first();

    expect($after->status)->toBe($before->status)
        ->and($after->branch_id)->toBe($before->branch_id)
        ->and($after->updated_at)->toBe($before->updated_at);
});

it('writes nothing at all', function () {
    $sunu = fbpaBranch('SPN4');
    $user = fbpaUser('fo-readonly@example.test');
    app(UserOnlineContextService::class)->startAdminClinicSession($user, $sunu->id);

    fbpaCohort($user->id.':SPN4');
    fbpaFlags(context: true, device: false);

    /*
     * EVERY STATEMENT IS INSPECTED, NOT JUST THE ROW COUNTS.
     *
     * An earlier version of this test compared table counts before and after,
     * and a mutant that made the auditor call `$user->save()` on every account
     * survived it: an UPDATE to an existing row changes no count. "Read-only"
     * has to mean no write statement was issued at all, so the assertion is on
     * the SQL the auditor actually emits.
     */
    $writes = [];

    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|truncate|alter|drop)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $this->artisan('rbac:front-office-branch-pin-audit --json')->assertExitCode(0);

    expect($writes)->toBe([]);
});
