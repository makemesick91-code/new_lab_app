<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\FrontOfficeDevice\Services\FrontOfficeBranchDeviceLockService;
use App\Modules\FrontOfficeDevice\Support\FrontOfficeDeviceLockDecision;
use App\Modules\RmeOnlineContext\Services\DailyBranchContextService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Support\AccessControl\FrontOfficeBranchDeviceCohort;
use App\Support\AccessControl\FrontOfficeRole;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1
|--------------------------------------------------------------------------
|
| The property under test is NARROWNESS as much as it is strictness. Production
| carries eight Front Office accounts and the owner approved four, so a suite
| that only proved "armed accounts are locked" would miss the more dangerous
| regression: locking one of the other four, or any other role.
|
| So every denial case has a mirror that asserts somebody ELSE was untouched.
*/

beforeEach(function () {
    seedAccessControl();
});

/** Flip the enforcement flag. The key contains a dot, so the whole array is rewritten. */
function foLockFlag(bool $enabled): void
{
    $flags = config('feature_flags.flags', []);
    $flags[FrontOfficeBranchDeviceLockService::FLAG]['default'] = $enabled;

    config()->set('feature_flags.flags', $flags);
}

/** A real production branch code — the policy allowlist accepts only these four. */
function foBranch(string $code): Branch
{
    return Branch::factory()->create([
        'code' => $code,
        'name' => 'Cabang '.$code,
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
}

/** Hardware as the lock requires it: active, key-proved, enrolment complete. */
function foApprovedDevice(?Branch $branch, array $overrides = []): DoctorDevice
{
    return DoctorDevice::factory()->create(array_merge([
        'branch_id' => $branch?->id,
        'status' => DoctorDevice::STATUS_ACTIVE,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED,
    ], $overrides));
}

function foUser(string $email = 'fo@example.test'): User
{
    return User::factory()
        ->create(['email' => $email, 'password' => Hash::make('password')])
        ->assignRole(FrontOfficeRole::NAME);
}

/** Arm exactly this account, for exactly this branch. */
function foArm(User $user, string $branchCode): void
{
    foLockFlag(true);
    config()->set('front_office_device_lock.scope.cohort', $user->id.':'.$branchCode);
}

/** Arm with a raw cohort string, for the malformed/duplicate/oversized cases. */
function foArmRaw(string $cohort): void
{
    foLockFlag(true);
    config()->set('front_office_device_lock.scope.cohort', $cohort);
}

function foDecide(User $user, ?int $deviceId): FrontOfficeDeviceLockDecision
{
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));

    if ($deviceId !== null) {
        $request->session()->put(FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID, $deviceId);
    }

    return app(FrontOfficeBranchDeviceLockService::class)->evaluate($user, $request);
}

function foDenialAuditCount(User $user): int
{
    return DB::table('sys_audit_logs')
        ->where('action', 'FRONT_OFFICE_DEVICE_BRANCH_LOGIN_DENIED')
        ->where('entity_id', $user->id)
        ->count();
}

// ---------------------------------------------------------------------------
// 1-8 — ADMIN SUNU (SPN4)
// ---------------------------------------------------------------------------

it('1. allows Admin Sunu on an approved Sunu device', function () {
    $sunu = foBranch('SPN4');
    $user = foUser();
    foArm($user, 'SPN4');

    $decision = foDecide($user, foApprovedDevice($sunu)->id);

    expect($decision->outcome)->toBe(FrontOfficeDeviceLockDecision::ALLOW)
        ->and($decision->isDenial())->toBeFalse();
});

it('2. denies Admin Sunu on a Landak device', function () {
    foBranch('SPN4');
    $landak = foBranch('LDK2');
    $user = foUser();
    foArm($user, 'SPN4');

    $decision = foDecide($user, foApprovedDevice($landak)->id);

    expect($decision->outcome)->toBe(FrontOfficeDeviceLockDecision::DENY_DEVICE_BRANCH_MISMATCH)
        ->and($decision->message())->toContain('Cabang SPN4');
});

it('3. denies Admin Sunu on an Antang device', function () {
    foBranch('SPN4');
    $antang = foBranch('ATG3');
    $user = foUser();
    foArm($user, 'SPN4');

    expect(foDecide($user, foApprovedDevice($antang)->id)->outcome)
        ->toBe(FrontOfficeDeviceLockDecision::DENY_DEVICE_BRANCH_MISMATCH);
});

it('4. denies Admin Sunu on a Telkomas device', function () {
    foBranch('SPN4');
    $telkomas = foBranch('TLK1');
    $user = foUser();
    foArm($user, 'SPN4');

    expect(foDecide($user, foApprovedDevice($telkomas)->id)->outcome)
        ->toBe(FrontOfficeDeviceLockDecision::DENY_DEVICE_BRANCH_MISMATCH);
});

it('5. denies Admin Sunu on an unknown device', function () {
    foBranch('SPN4');
    $user = foUser();
    foArm($user, 'SPN4');

    // No binding at all, and a binding naming a device row that does not exist.
    expect(foDecide($user, null)->outcome)
        ->toBe(FrontOfficeDeviceLockDecision::DENY_UNKNOWN_DEVICE)
        ->and(foDecide($user, 987654)->outcome)
        ->toBe(FrontOfficeDeviceLockDecision::DENY_UNKNOWN_DEVICE);
});

it('6. denies Admin Sunu on a revoked device', function () {
    $sunu = foBranch('SPN4');
    $user = foUser();
    foArm($user, 'SPN4');

    $device = foApprovedDevice($sunu, [
        'status' => DoctorDevice::STATUS_REVOKED,
        'revoked_at' => now(),
    ]);

    expect(foDecide($user, $device->id)->outcome)
        ->toBe(FrontOfficeDeviceLockDecision::DENY_DEVICE_NOT_APPROVED);
});

it('7. denies Admin Sunu on a disabled device', function () {
    $sunu = foBranch('SPN4');
    $user = foUser();
    foArm($user, 'SPN4');

    $device = foApprovedDevice($sunu, [
        'status' => DoctorDevice::STATUS_DISABLED,
        'disabled_at' => now(),
    ]);

    expect(foDecide($user, $device->id)->outcome)
        ->toBe(FrontOfficeDeviceLockDecision::DENY_DEVICE_NOT_APPROVED);
});

it('7b. denies a pending-approval or merely hand-entered device', function () {
    $sunu = foBranch('SPN4');
    $user = foUser();
    foArm($user, 'SPN4');

    $pending = foApprovedDevice($sunu, ['status' => DoctorDevice::STATUS_PENDING_APPROVAL]);
    $unverified = foApprovedDevice($sunu, ['identity_state' => DoctorDevice::IDENTITY_UNVERIFIED]);
    $notEnrolled = foApprovedDevice($sunu, ['enrollment_status' => DoctorDevice::ENROLLMENT_NOT_ENROLLED]);

    expect(foDecide($user, $pending->id)->outcome)->toBe(FrontOfficeDeviceLockDecision::DENY_DEVICE_NOT_APPROVED)
        ->and(foDecide($user, $unverified->id)->outcome)->toBe(FrontOfficeDeviceLockDecision::DENY_DEVICE_NOT_APPROVED)
        ->and(foDecide($user, $notEnrolled->id)->outcome)->toBe(FrontOfficeDeviceLockDecision::DENY_DEVICE_NOT_APPROVED);
});

it('8. cannot even register a branchless device, so the case is unreachable', function () {
    foBranch('SPN4');
    $user = foUser();
    foArm($user, 'SPN4');

    /*
     * MEASURED, NOT ASSUMED. `mst_doctor_devices.branch_id` is a NOT NULL
     * `foreignId(...)->constrained()`, so a device with no branch cannot be
     * stored at all — the database refuses it before the lock is ever asked.
     *
     * The requirement "device with no canonical branch -> DENY" is therefore
     * satisfied one layer lower than expected, and the null branch is kept
     * handled in the decision service as a defensive belt rather than as a
     * reachable path. This test pins the schema guarantee that makes it
     * unreachable: if a later migration ever relaxes that column, this fails
     * and the defensive branch stops being dead code.
     */
    /*
     * ORDER MATTERS ON POSTGRESQL. The schema is read FIRST and the failing
     * insert is LAST, because PostgreSQL aborts the entire transaction on any
     * failed statement — a query after the rejected insert dies with
     * "current transaction is aborted" rather than answering. SQLite tolerates
     * it, so a test written the other way round passes locally and fails on the
     * canonical database.
     */
    expect(Schema::hasColumn('mst_doctor_devices', 'branch_id'))->toBeTrue();

    expect(fn () => foApprovedDevice(null, ['branch_id' => null]))
        ->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// 9-14 — the other three accounts, symmetrically
// ---------------------------------------------------------------------------

it('9-14. locks Landak, Antang and Telkomas to their own branch device', function (string $own, string $other) {
    $ownBranch = foBranch($own);
    $otherBranch = foBranch($other);
    $user = foUser($own.'@example.test');
    foArm($user, $own);

    expect(foDecide($user, foApprovedDevice($ownBranch)->id)->outcome)
        ->toBe(FrontOfficeDeviceLockDecision::ALLOW)
        ->and(foDecide($user, foApprovedDevice($otherBranch)->id)->outcome)
        ->toBe(FrontOfficeDeviceLockDecision::DENY_DEVICE_BRANCH_MISMATCH);
})->with([
    ['LDK2', 'SPN4'],
    ['ATG3', 'TLK1'],
    ['TLK1', 'ATG3'],
]);

// ---------------------------------------------------------------------------
// 15 — the four OTHER Front Office accounts. The regression that matters most.
// ---------------------------------------------------------------------------

it('15. leaves a Front Office account outside the cohort completely unchanged', function () {
    $sunu = foBranch('SPN4');
    $armed = foUser('armed@example.test');
    $other = foUser('other@example.test');

    foArm($armed, 'SPN4');

    // Not in scope on any device, and on no device at all.
    expect(foDecide($other, null)->outcome)->toBe(FrontOfficeDeviceLockDecision::NOT_IN_SCOPE)
        ->and(foDecide($other, foApprovedDevice(foBranch('LDK2'))->id)->outcome)
        ->toBe(FrontOfficeDeviceLockDecision::NOT_IN_SCOPE)
        ->and(app(FrontOfficeBranchDeviceLockService::class)->appliesTo($other))->toBeFalse();

    // And it still logs in with nothing but a password, from no device.
    $this->post('/login', ['email' => $other->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($other);
    expect(foDenialAuditCount($other))->toBe(0);
    expect($sunu->fresh())->not->toBeNull();
});

it('15b. still locks the armed account while the other one is free', function () {
    foBranch('SPN4');
    $landak = foBranch('LDK2');
    $armed = foUser('armed@example.test');
    foUser('other@example.test');

    foArm($armed, 'SPN4');

    $this->withSession([
        FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID => foApprovedDevice($landak)->id,
    ])->post('/login', ['email' => $armed->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

// ---------------------------------------------------------------------------
// 16-19 — other roles are untouched
// ---------------------------------------------------------------------------

it('16-19. never applies to any other role', function (string $role) {
    foBranch('SPN4');
    $user = User::factory()->create(['password' => Hash::make('password')])->assignRole($role);

    // Armed by id — which must NOT be enough, because the role is wrong. This is
    // the mistyped-id case: it must not device-lock a doctor.
    foArm($user, 'SPN4');

    expect(foDecide($user, null)->outcome)->toBe(FrontOfficeDeviceLockDecision::NOT_IN_SCOPE)
        ->and(app(FrontOfficeBranchDeviceLockService::class)->appliesTo($user))->toBeFalse();
})->with(['Doctor', 'Super Admin', 'Supervisor RME', 'Admin Klinik', 'Kasir', 'Perawat', 'Owner']);

// ---------------------------------------------------------------------------
// 20-22 — the branch context cannot be widened
// ---------------------------------------------------------------------------

it('20. refuses a branch switch for an armed account at the service', function () {
    $sunu = foBranch('SPN4');
    $landak = foBranch('LDK2');
    $user = foUser();
    foArm($user, 'SPN4');

    // Its own branch is accepted.
    app(UserOnlineContextService::class)->startAdminClinicSession($user, (int) $sunu->id);

    // Any other branch is refused by the SERVICE, not by a hidden menu.
    expect(fn () => app(UserOnlineContextService::class)
        ->startAdminClinicSession($user, (int) $landak->id))
        ->toThrow(ValidationException::class);
});

it('21. denies a crafted branch-switch request server-side', function () {
    $sunu = foBranch('SPN4');
    $landak = foBranch('LDK2');
    $user = foUser();
    foArm($user, 'SPN4');

    $this->actingAs($user)
        ->withSession([
            FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID => foApprovedDevice($sunu)->id,
        ])
        ->post(route('rme.online-context.admin-clinic'), [
            // The D5 daily-lock confirmation is satisfied deliberately, so the
            // request reaches THIS sprint's guard instead of being stopped one
            // layer earlier. Otherwise the test would pass without ever
            // exercising the branch lock.
            'branch_id' => $landak->id,
            DailyBranchContextService::CONFIRMATION_FIELD => $landak->id,
        ])
        ->assertSessionHasErrors('branch_id');

    expect(DB::table('trx_user_online_contexts')
        ->where('user_id', $user->id)
        ->where('branch_id', $landak->id)
        ->count())->toBe(0);
});

it('22. pins the branch context so a stale selection cannot widen it', function () {
    $sunu = foBranch('SPN4');
    $landak = foBranch('LDK2');
    $user = foUser();

    // A context row selected BEFORE the account was armed, pointing elsewhere.
    rmeMakeFrontOfficeActive($user, $landak);
    expect(app(BranchContext::class)->forUser($user))->toBe((int) $landak->id);

    foArm($user, 'SPN4');

    // Armed: the pinned branch wins over the stale online context.
    expect(app(BranchContext::class)->forUser($user))->toBe((int) $sunu->id);
});

it('22c. still lets an armed operator reach the selector and pick its OWN branch', function () {
    /*
     * THE ACTIVATION HAPPY PATH, pinned because breaking it would make the
     * capability impossible to arm.
     *
     * A newly armed account holds no online context, so `EnsureRmeOnlineContext`
     * (which runs BEFORE this sprint's middleware) sends it to the selector. If
     * this sprint's middleware refused the selector for a VALID device session,
     * the operator could never establish a context and arming would brick the
     * account — a lock that denies the very branch it approved.
     */
    $sunu = foBranch('SPN4');
    $user = foUser();
    foArm($user, 'SPN4');

    $this->actingAs($user)
        ->withSession([
            FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID => foApprovedDevice($sunu)->id,
        ])
        ->get(route('rme.online-context.select'))
        ->assertOk();

    // And its own branch is accepted at the selector.
    app(UserOnlineContextService::class)->startAdminClinicSession($user, (int) $sunu->id);

    expect(app(BranchContext::class)->forUser($user->fresh()))->toBe((int) $sunu->id);
});

it('22d. denies the selector itself when the bound device has gone bad', function () {
    // The mirror of 22c: a session whose device is no longer usable must not be
    // able to sit on the selector either. Fail closed everywhere.
    $sunu = foBranch('SPN4');
    $user = foUser();
    foArm($user, 'SPN4');

    $revoked = foApprovedDevice($sunu, [
        'status' => DoctorDevice::STATUS_REVOKED,
        'revoked_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession([FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID => $revoked->id])
        ->get(route('rme.online-context.select'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('22b. resolves the ordinary way again once the account is disarmed', function () {
    foBranch('SPN4');
    $landak = foBranch('LDK2');
    $user = foUser();

    rmeMakeFrontOfficeActive($user, $landak);
    foArm($user, 'SPN4');
    expect(app(BranchContext::class)->forUser($user))->not->toBe((int) $landak->id);

    foLockFlag(false);
    expect(app(BranchContext::class)->forUser($user))->toBe((int) $landak->id);
});

// ---------------------------------------------------------------------------
// 23-24 — a live session stops being valid
// ---------------------------------------------------------------------------

it('23. ends the session when the device is revoked after login', function () {
    $sunu = foBranch('SPN4');
    $user = foUser();
    $device = foApprovedDevice($sunu);

    rmeMakeFrontOfficeActive($user, $sunu);
    foArm($user, 'SPN4');

    $device->forceFill([
        'status' => DoctorDevice::STATUS_REVOKED,
        'revoked_at' => now(),
    ])->save();

    $this->actingAs($user)
        ->withSession([FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID => $device->id])
        ->get('/dashboard')
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect(foDenialAuditCount($user))->toBeGreaterThan(0);
});

it('24. ends the session when the device branch stops matching', function () {
    $sunu = foBranch('SPN4');
    $landak = foBranch('LDK2');
    $user = foUser();
    $device = foApprovedDevice($sunu);

    rmeMakeFrontOfficeActive($user, $sunu);
    foArm($user, 'SPN4');

    // The hardware is corrected to another branch mid-session.
    $device->forceFill(['branch_id' => $landak->id])->save();

    $this->actingAs($user)
        ->withSession([FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID => $device->id])
        ->get('/dashboard')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('24b. leaves a non-cohort Front Office session alone on every request', function () {
    $sunu = foBranch('SPN4');
    $armed = foUser('armed@example.test');
    $other = foUser('other@example.test');

    rmeMakeFrontOfficeActive($other, $sunu);
    foArm($armed, 'SPN4');

    // No device binding at all, and it is simply not the middleware's business.
    $this->actingAs($other)->get('/dashboard')->assertDontSee('terkunci');

    $this->assertAuthenticatedAs($other);
});

// ---------------------------------------------------------------------------
// 25-28 — configuration that cannot be trusted fails CLOSED
// ---------------------------------------------------------------------------

it('25. fails closed for an armed account whose branch code is off-policy', function () {
    foBranch('SPN4');
    $user = foUser();

    // MAIN is deliberately absent from the policy allowlist.
    foArmRaw($user->id.':MAIN');

    expect(foDecide($user, null)->outcome)
        ->toBe(FrontOfficeDeviceLockDecision::DENY_ACCOUNT_BRANCH_INVALID);
});

it('25b. fails closed when the required branch is missing, inactive or non-RME', function () {
    $user = foUser();
    foArm($user, 'SPN4');

    // Branch code armed but no such branch exists.
    expect(foDecide($user, null)->outcome)
        ->toBe(FrontOfficeDeviceLockDecision::DENY_ACCOUNT_BRANCH_INVALID);

    $sunu = foBranch('SPN4');
    $sunu->forceFill(['is_active' => false])->save();
    expect(foDecide($user, null)->outcome)
        ->toBe(FrontOfficeDeviceLockDecision::DENY_BRANCH_INACTIVE);

    $sunu->forceFill(['is_active' => true, 'is_rme_enabled' => false])->save();
    expect(foDecide($user, null)->outcome)
        ->toBe(FrontOfficeDeviceLockDecision::DENY_BRANCH_INACTIVE);
});

it('26. fails closed on a duplicated or ambiguous mapping', function () {
    foBranch('SPN4');
    foBranch('LDK2');
    $user = foUser();

    // The same id twice, disagreeing. Guessing which was meant is not allowed.
    foArmRaw($user->id.':SPN4,'.$user->id.':LDK2');

    expect(foDecide($user, null)->outcome)
        ->toBe(FrontOfficeDeviceLockDecision::DENY_ACCOUNT_BRANCH_INVALID);

    // Even agreeing duplicates are ambiguous rather than idempotent.
    foArmRaw($user->id.':SPN4,'.$user->id.':SPN4');
    expect(foDecide($user, null)->outcome)
        ->toBe(FrontOfficeDeviceLockDecision::DENY_ACCOUNT_BRANCH_INVALID);
});

it('26b. fails closed for everyone armed once the cohort exceeds the approved size', function () {
    foBranch('SPN4');
    $users = collect(range(1, 5))->map(fn (int $i) => foUser("fo{$i}@example.test"));

    foArmRaw($users->map(fn (User $u) => $u->id.':SPN4')->implode(','));

    // Five is past the ceiling, so none of the five is trusted.
    $users->each(function (User $user) {
        expect(foDecide($user, null)->outcome)
            ->toBe(FrontOfficeDeviceLockDecision::DENY_ACCOUNT_BRANCH_INVALID);
    });
});

it('27. leaves every Front Office account unchanged when the cohort is empty', function () {
    $sunu = foBranch('SPN4');
    $user = foUser();

    foLockFlag(true);
    config()->set('front_office_device_lock.scope.cohort', '');

    expect(foDecide($user, null)->outcome)->toBe(FrontOfficeDeviceLockDecision::NOT_IN_SCOPE);

    // Including a branch selection, which stays free.
    app(UserOnlineContextService::class)->startAdminClinicSession($user, (int) $sunu->id);
    expect(app(BranchContext::class)->forUser($user))->toBe((int) $sunu->id);
});

it('27b. is entirely inert while the flag is off, even with a full cohort', function () {
    $sunu = foBranch('SPN4');
    $landak = foBranch('LDK2');
    $user = foUser();

    foLockFlag(false);
    config()->set('front_office_device_lock.scope.cohort', $user->id.':SPN4');

    expect(foDecide($user, null)->outcome)->toBe(FrontOfficeDeviceLockDecision::NOT_IN_SCOPE)
        ->and(app(FrontOfficeBranchDeviceLockService::class)->enforcementEnabled())->toBeFalse();

    // A wrong-branch selection is permitted again, and login needs no device.
    app(UserOnlineContextService::class)->startAdminClinicSession($user, (int) $landak->id);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

it('28. matches the exact user id and never a similar name or shared role', function () {
    $sunu = foBranch('SPN4');

    $armed = User::factory()->create([
        'name' => 'Admin Sunu',
        'email' => 'adminsunu@example.test',
        'password' => Hash::make('password'),
    ])->assignRole(FrontOfficeRole::NAME);

    // Same display name, same role, different account. A name is not a key.
    $impostor = User::factory()->create([
        'name' => 'Admin Sunu',
        'email' => 'adminsunu2@example.test',
        'password' => Hash::make('password'),
    ])->assignRole(FrontOfficeRole::NAME);

    foArm($armed, 'SPN4');

    expect(foDecide($armed, null)->outcome)->toBe(FrontOfficeDeviceLockDecision::DENY_UNKNOWN_DEVICE)
        ->and(foDecide($impostor, null)->outcome)->toBe(FrontOfficeDeviceLockDecision::NOT_IN_SCOPE);

    $this->post('/login', ['email' => $impostor->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($impostor);
    expect($sunu->code)->toBe('SPN4');
});

// ---------------------------------------------------------------------------
// 29-30 — the audit trail
// ---------------------------------------------------------------------------

it('29. audits a denied armed login with a safe, structured reason', function () {
    foBranch('SPN4');
    $landak = foBranch('LDK2');
    $user = foUser();
    foArm($user, 'SPN4');

    $this->withSession([
        FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID => foApprovedDevice($landak)->id,
    ])->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $row = DB::table('sys_audit_logs')
        ->where('action', 'FRONT_OFFICE_DEVICE_BRANCH_LOGIN_DENIED')
        ->where('entity_id', $user->id)
        ->first();

    expect($row)->not->toBeNull();

    $payload = json_decode((string) $row->new_values, true);

    expect($payload['reason'])->toBe(FrontOfficeDeviceLockDecision::DENY_DEVICE_BRANCH_MISMATCH)
        ->and($payload['required_branch_code'])->toBe('SPN4')
        ->and($payload['actual_branch_code'])->toBe('LDK2');

    // Nothing sensitive is recorded: no password, credential, token or cookie.
    $raw = strtolower((string) $row->new_values);
    foreach (['password', 'credential', 'token', 'cookie', 'secret', 'session_id'] as $forbidden) {
        expect($raw)->not->toContain($forbidden);
    }
});

it('30. writes no denial event for a successful armed login', function () {
    $sunu = foBranch('SPN4');
    $user = foUser();
    foArm($user, 'SPN4');

    $this->withSession([
        FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID => foApprovedDevice($sunu)->id,
    ])->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
    expect(foDenialAuditCount($user))->toBe(0);
});

// ---------------------------------------------------------------------------
// Governance — the committed policy is the scope ceiling
// ---------------------------------------------------------------------------

it('commits an empty cohort, so a deploy arms nobody', function () {
    // Read the file rather than the runtime value, which tests mutate freely.
    $committed = require base_path('config/front_office_device_lock.php');

    expect($committed['scope']['cohort'])->toBe('')
        ->and($committed['policy']['max_cohort_size'])->toBe(4)
        ->and($committed['policy']['role_wide_permitted'])->toBeFalse()
        ->and($committed['policy']['required_role'])->toBe(FrontOfficeRole::NAME)
        ->and($committed['policy']['allowed_branch_codes'])
        ->toEqualCanonicalizing(['TLK1', 'LDK2', 'ATG3', 'SPN4']);
});

it('ships the enforcement flag off and registered as critical', function () {
    $flag = config('feature_flags.flags')[FrontOfficeBranchDeviceLockService::FLAG];

    expect($flag['default'])->toBeFalse()
        ->and($flag['risk_level'])->toBe('critical')
        ->and($flag['env_key'])->toBe('FEATURE_FRONT_OFFICE_BRANCH_DEVICE_LOCK')
        ->and($flag['rollback_action'])->not->toBeEmpty();
});

it('surfaces configuration problems instead of hiding them', function () {
    foBranch('SPN4');
    $user = foUser();

    // A usable pair, a duplicate, an off-policy code, and a token naming no id.
    foArmRaw($user->id.':SPN4,'.$user->id.':LDK2,77:MAIN,abc:SPN4');

    $cohort = app(FrontOfficeBranchDeviceCohort::class);

    // The unattributable token puts NOBODY in scope — it cannot deny an account
    // it cannot name, and must never widen scope to one it was not pointed at.
    expect($cohort->covers($user->id))->toBeTrue()
        ->and($cohort->covers(77))->toBeTrue()
        ->and($cohort->requiredBranchCodeFor(77))->toBeNull()
        ->and($cohort->ambiguousUserIds())->toEqualCanonicalizing([$user->id, 77])
        ->and($cohort->armed())->toBe([])
        ->and($cohort->isEmpty())->toBeFalse()
        ->and($cohort->isOversized())->toBeFalse();

    $errors = implode(' | ', $cohort->configErrors());

    expect($errors)->toContain('duplicate cohort entry')
        ->and($errors)->toContain('outside the approved policy')
        ->and($errors)->toContain('no usable user id');
});

it('reports an empty cohort as empty, which arms nobody', function () {
    foLockFlag(true);
    config()->set('front_office_device_lock.scope.cohort', '');

    $cohort = app(FrontOfficeBranchDeviceCohort::class);

    expect($cohort->isEmpty())->toBeTrue()
        ->and($cohort->armed())->toBe([])
        ->and($cohort->configErrors())->toBe([])
        ->and($cohort->covers(29))->toBeFalse();
});

it('reads no environment value outside config', function () {
    $service = file_get_contents(base_path('app/Modules/FrontOfficeDevice/Services/FrontOfficeBranchDeviceLockService.php'));
    $cohort = file_get_contents(base_path('app/Support/AccessControl/FrontOfficeBranchDeviceCohort.php'));

    // Deterministic under config:cache — env() belongs only in config files.
    expect($service)->not->toContain('env(')
        ->and($cohort)->not->toContain('env(');
});
