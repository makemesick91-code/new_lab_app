<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicVisit\Requests\StoreClinicVisitRequest;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\FrontOfficeDevice\Services\FrontOfficeBranchDeviceLockService;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\RmeOnlineContext\Services\BranchChangeApprovalService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Support\AccessControl\FrontOfficeBranchPinResolver;
use App\Support\AccessControl\FrontOfficeRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1
|--------------------------------------------------------------------------
|
| THE PROPERTY UNDER TEST IS THE SEPARATION OF TWO PREDICATES:
|
|     BRANCH CONTEXT LOCK != DEVICE PROOF REQUIREMENT
|     DEVICE LOCK IMPLIES BRANCH PIN
|
| A suite that only proved "an armed account is pinned" would pass just as
| happily against the defect this sprint removed, where the only way to obtain a
| pin was to arm the device lock. So the truth table below asserts BOTH halves at
| every cell: what the pin does, AND what the device layer does NOT do.
|
| The second property is NARROWNESS. Production carries eight Front Office
| accounts and the owner approved four, so every pinning assertion has a mirror
| proving somebody else was untouched — and the untouched accounts are untouched
| because they are ABSENT from the cohort, never because a test or the code names
| them.
*/

beforeEach(function () {
    seedAccessControl();
});

/** Flip either enforcement flag. The keys contain dots, so the array is rewritten whole. */
function fbcFlags(bool $context, bool $device): void
{
    $flags = config('feature_flags.flags', []);
    $flags[FrontOfficeBranchPinResolver::FLAG]['default'] = $context;
    $flags[FrontOfficeBranchDeviceLockService::FLAG]['default'] = $device;

    config()->set('feature_flags.flags', $flags);
}

/** A real production branch code — the committed policy allowlist accepts only these four. */
function fbcBranch(string $code): Branch
{
    return Branch::factory()->create([
        'code' => $code,
        'name' => 'Cabang '.$code,
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
}

/**
 * The MAIN fallback branch, so a fall-through resolves somewhere OTHER than the
 * pin. Without it the pinned branch is also the only active branch, and
 * `defaultBranchId()` returns it anyway — an assertion that cannot tell "pinned"
 * apart from "fell through" is not evidence of either.
 */
function fbcMainBranch(): Branch
{
    return Branch::factory()->create([
        'code' => Branch::MAIN_CODE,
        'name' => 'Main Fallback Branch',
        'is_active' => true,
        'is_rme_enabled' => false,
    ]);
}

function fbcUser(string $email = 'fo-context@example.test'): User
{
    return User::factory()
        ->create(['email' => $email, 'password' => Hash::make('password')])
        ->assignRole(FrontOfficeRole::NAME);
}

/** Put a raw cohort string in place, for the malformed / duplicate / oversized cases. */
function fbcCohort(string $cohort): void
{
    config()->set('front_office_device_lock.scope.cohort', $cohort);
}

function fbcPin(): FrontOfficeBranchPinResolver
{
    return app(FrontOfficeBranchPinResolver::class);
}

function fbcDeviceLock(): FrontOfficeBranchDeviceLockService
{
    return app(FrontOfficeBranchDeviceLockService::class);
}

/*
|--------------------------------------------------------------------------
| THE MANDATORY TRUTH TABLE — both flags, both predicates, every cell
|--------------------------------------------------------------------------
*/

it('A. context OFF device OFF — a cohort account is not pinned and needs no proof', function () {
    $main = fbcMainBranch();
    $branch = fbcBranch('SPN4');
    $user = fbcUser();
    fbcCohort($user->id.':SPN4');
    fbcFlags(context: false, device: false);

    expect(fbcPin()->appliesTo($user))->toBeFalse()
        ->and(fbcPin()->requiredBranchIdFor($user))->toBeNull()
        ->and(fbcDeviceLock()->appliesTo($user))->toBeFalse();

    // Untouched: ordinary resolution still runs. With no online context and no
    // users.branch_id this account falls through to MAIN, exactly as users 30,
    // 31 and 32 do on production today — and NOT to the branch its dormant
    // cohort entry names.
    expect(app(BranchContext::class)->forUser($user))
        ->toBe($main->id)
        ->not->toBe($branch->id);
});

it('B. context ON device OFF — pinned, and NO device proof is required', function () {
    $branch = fbcBranch('SPN4');
    $user = fbcUser();
    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    // The pin half.
    expect(fbcPin()->appliesTo($user))->toBeTrue()
        ->and(fbcPin()->requiredBranchIdFor($user))->toBe($branch->id)
        ->and(app(BranchContext::class)->forUser($user))->toBe($branch->id);

    // The decoupling half — this is the cell the whole sprint exists for.
    expect(fbcDeviceLock()->enforcementEnabled())->toBeFalse()
        ->and(fbcDeviceLock()->appliesTo($user))->toBeFalse();

    // No session binding exists, and the device layer still does not deny.
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));
    expect(fbcDeviceLock()->evaluate($user, $request)->isDenial())->toBeFalse();
});

it('C. context OFF device ON — pinned anyway, because DEVICE LOCK IMPLIES BRANCH PIN', function () {
    $branch = fbcBranch('LDK2');
    $user = fbcUser();
    fbcCohort($user->id.':LDK2');
    fbcFlags(context: false, device: true);

    expect(fbcPin()->appliesTo($user))->toBeTrue()
        ->and(fbcPin()->requiredBranchIdFor($user))->toBe($branch->id)
        ->and(app(BranchContext::class)->forUser($user))->toBe($branch->id);

    // And the device layer is armed, so proof IS required here.
    expect(fbcDeviceLock()->appliesTo($user))->toBeTrue();

    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));
    expect(fbcDeviceLock()->evaluate($user, $request)->isDenial())->toBeTrue();
});

it('D. context ON device ON — pinned, and proof required', function () {
    $branch = fbcBranch('ATG3');
    $user = fbcUser();
    fbcCohort($user->id.':ATG3');
    fbcFlags(context: true, device: true);

    expect(fbcPin()->requiredBranchIdFor($user))->toBe($branch->id)
        ->and(app(BranchContext::class)->forUser($user))->toBe($branch->id)
        ->and(fbcDeviceLock()->appliesTo($user))->toBeTrue();
});

it('E. a Front Office account OUTSIDE the cohort is unchanged in all four cells', function () {
    fbcMainBranch();
    fbcBranch('SPN4');

    $armed = fbcUser('armed@example.test');
    $other = fbcUser('other@example.test');

    // Only the armed account is named. Nothing anywhere names $other.
    fbcCohort($armed->id.':SPN4');

    foreach ([[false, false], [true, false], [false, true], [true, true]] as [$ctx, $dev]) {
        fbcFlags(context: $ctx, device: $dev);

        expect(fbcPin()->appliesTo($other))->toBeFalse()
            ->and(fbcPin()->requiredBranchIdFor($other))->toBeNull()
            ->and(fbcPin()->isMisconfiguredFor($other))->toBeFalse()
            ->and(fbcDeviceLock()->appliesTo($other))->toBeFalse();
    }
});

/*
|--------------------------------------------------------------------------
| CONTRACT 12 — the decoupling regression, stated structurally
|--------------------------------------------------------------------------
*/

it('12. arming ONLY branch_context_lock never reads the device or credential tables', function () {
    $branch = fbcBranch('SPN4');
    $user = fbcUser();
    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    $touched = [];
    DB::listen(function ($query) use (&$touched) {
        foreach (['mst_doctor_devices', 'trx_doctor_device_webauthn_credentials', 'mst_doctor_device_authorizations'] as $table) {
            if (str_contains($query->sql, $table)) {
                $touched[] = $table;
            }
        }
    });

    // The full branch-pin path: resolve, then select a branch.
    expect(app(BranchContext::class)->forUser($user))->toBe($branch->id);
    app(UserOnlineContextService::class)->startAdminClinicSession($user, $branch->id);

    expect($touched)->toBe([], 'the branch-context layer queried a device/credential table');
});

it('12b. a pinned account with NO credential logs in normally when only the context flag is on', function () {
    $branch = fbcBranch('SPN4');
    $user = fbcUser();
    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    // No device row, no credential, no session binding anywhere.
    $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect($this->isAuthenticated())->toBeTrue();
    $response->assertRedirect();
    // Not diverted into the WebAuthn ceremony.
    expect($response->headers->get('Location'))->not->toContain('front-office-device-webauthn');
});

/*
|--------------------------------------------------------------------------
| CONTRACT 13 — the already-GO device capability is not weakened
|--------------------------------------------------------------------------
*/

it('13. branch_device_lock alone still denies an armed account with no bound device', function () {
    fbcBranch('SPN4');
    $user = fbcUser();
    fbcCohort($user->id.':SPN4');
    fbcFlags(context: false, device: true);

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    // The device layer tore the session down, exactly as it did before this sprint.
    expect($this->isAuthenticated())->toBeFalse();
    $response->assertRedirect();
});

/*
|--------------------------------------------------------------------------
| CONTRACT 14 — cohort safety, and what "fail closed" means with no login gate
|--------------------------------------------------------------------------
*/

it('14a. a duplicated cohort entry leaves the account armed but resolving to NO branch', function () {
    fbcBranch('SPN4');
    fbcBranch('LDK2');
    $user = fbcUser();
    fbcCohort($user->id.':SPN4,'.$user->id.':LDK2');
    fbcFlags(context: true, device: false);

    expect(fbcPin()->appliesTo($user))->toBeTrue()
        ->and(fbcPin()->requiredBranchIdFor($user))->toBeNull()
        ->and(fbcPin()->isMisconfiguredFor($user))->toBeTrue()
        // Fail CLOSED: no branch at all, rather than falling through to a wider one.
        ->and(app(BranchContext::class)->forUser($user))->toBeNull();
});

it('14b. a branch code outside the approved allowlist fails closed', function () {
    fbcBranch('SPN4');
    $user = fbcUser();
    fbcCohort($user->id.':MAIN');
    fbcFlags(context: true, device: false);

    expect(fbcPin()->isMisconfiguredFor($user))->toBeTrue()
        ->and(app(BranchContext::class)->forUser($user))->toBeNull();
});

it('14c. a fifth armed account fails closed for EVERY armed account', function () {
    foreach (['TLK1', 'LDK2', 'ATG3', 'SPN4'] as $code) {
        fbcBranch($code);
    }
    $users = collect(['TLK1', 'LDK2', 'ATG3', 'SPN4', 'SPN4'])
        ->map(fn ($code, $i) => [fbcUser('fo'.$i.'@example.test'), $code]);

    fbcCohort($users->map(fn ($pair) => $pair[0]->id.':'.$pair[1])->implode(','));
    fbcFlags(context: true, device: false);

    foreach ($users as [$user, $code]) {
        expect(fbcPin()->requiredBranchIdFor($user))->toBeNull()
            ->and(fbcPin()->isMisconfiguredFor($user))->toBeTrue();
    }
});

it('14d. a malformed entry puts NOBODY in scope and locks nobody out', function () {
    $main = fbcMainBranch();
    fbcBranch('SPN4');
    $user = fbcUser();
    fbcCohort('abc:SPN4,SPN4');
    fbcFlags(context: true, device: false);

    // Unattributable tokens are a configuration error, not a denial: they name
    // no user id, so they must not fail anybody closed.
    expect(fbcPin()->appliesTo($user))->toBeFalse()
        ->and(fbcPin()->isMisconfiguredFor($user))->toBeFalse()
        ->and(app(BranchContext::class)->forUser($user))->toBe($main->id);
});

it('14e. a pinned branch that loses is_rme_enabled fails closed rather than widening', function () {
    $branch = fbcBranch('SPN4');
    $user = fbcUser();
    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    $branch->update(['is_rme_enabled' => false]);

    expect(fbcPin()->isMisconfiguredFor($user))->toBeTrue()
        ->and(app(BranchContext::class)->forUser($user))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| SWITCHING AND STALE SESSIONS — the server-side boundary
|--------------------------------------------------------------------------
*/

it('refuses a selection of any branch other than the pinned one', function () {
    $pinned = fbcBranch('SPN4');
    $other = fbcBranch('LDK2');
    $user = fbcUser();
    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    app(UserOnlineContextService::class)->startAdminClinicSession($user, $other->id);
})->throws(ValidationException::class);

it('accepts a selection of the pinned branch idempotently', function () {
    $pinned = fbcBranch('SPN4');
    $user = fbcUser();
    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    $context = app(UserOnlineContextService::class)->startAdminClinicSession($user, $pinned->id);

    expect((int) $context->branch_id)->toBe($pinned->id);
});

it('a stale online context on another branch cannot widen the effective branch', function () {
    $pinned = fbcBranch('SPN4');
    $stale = fbcBranch('LDK2');
    $user = fbcUser();

    // A context selected BEFORE the account was armed, pointing somewhere else.
    fbcFlags(context: false, device: false);
    app(UserOnlineContextService::class)->startAdminClinicSession($user, $stale->id);
    expect(app(BranchContext::class)->forUser($user))->toBe($stale->id);

    // Arming must narrow it on the very next resolution, not on the next selection.
    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    expect(app(BranchContext::class)->forUser($user))->toBe($pinned->id);
});

it('a static users.branch_id cannot widen the effective branch either', function () {
    $pinned = fbcBranch('SPN4');
    $other = fbcBranch('LDK2');
    $user = fbcUser();
    $user->forceFill(['branch_id' => $other->id])->save();

    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    expect(app(BranchContext::class)->forUser($user->fresh()))->toBe($pinned->id);
});

/*
|--------------------------------------------------------------------------
| OTHER ROLES — the pin is a front-desk rule, not an estate-wide one
|--------------------------------------------------------------------------
*/

it('never pins an account that lacks the required role, even if its id is armed', function () {
    $branch = fbcBranch('SPN4');

    foreach (['Doctor', 'Super Admin', 'Supervisor RME', 'Kasir'] as $role) {
        $user = User::factory()->create()->assignRole($role);
        fbcCohort($user->id.':SPN4');
        fbcFlags(context: true, device: false);

        expect(fbcPin()->appliesTo($user))->toBeFalse()
            ->and(fbcPin()->requiredBranchIdFor($user))->toBeNull()
            ->and(fbcPin()->isMisconfiguredFor($user))->toBeFalse();
    }
});

/*
|--------------------------------------------------------------------------
| SELECTOR — presentation, mirroring the server
|--------------------------------------------------------------------------
*/

it('offers a pinned account only its own branch on the selector', function () {
    $pinned = fbcBranch('SPN4');
    $other = fbcBranch('LDK2');
    $user = fbcUser();
    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    $response = $this->actingAs($user)->get(route('rme.online-context.select'));

    $response->assertOk()
        ->assertSee('Cabang terkunci')
        ->assertSee($pinned->name)
        ->assertDontSee('value="'.$other->id.'"', false);
});

it('leaves the selector untouched for a Front Office account outside the cohort', function () {
    $a = fbcBranch('SPN4');
    $b = fbcBranch('LDK2');
    $armed = fbcUser('armed2@example.test');
    $other = fbcUser('other2@example.test');
    fbcCohort($armed->id.':SPN4');
    fbcFlags(context: true, device: false);

    $response = $this->actingAs($other)->get(route('rme.online-context.select'));

    $response->assertOk()
        ->assertDontSee('Cabang terkunci')
        ->assertSee('value="'.$a->id.'"', false)
        ->assertSee('value="'.$b->id.'"', false);
});

/*
|--------------------------------------------------------------------------
| COST — this runs inside BranchContext::forUser(), on ordinary requests,
| for every role in the estate. The counts are pinned, not bounded: a
| duplicate read is a constant and a ceiling would not notice it.
|--------------------------------------------------------------------------
*/

/** @return list<string> SQL of every query issued while running $work. */
function fbcCapture(callable $work): array
{
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    $work();

    return $sql;
}

it('never consults the role registry for an account outside the cohort', function () {
    fbcMainBranch();
    fbcBranch('SPN4');
    $armed = fbcUser('armed3@example.test');
    $other = fbcUser('other3@example.test');
    fbcCohort($armed->id.':SPN4');
    fbcFlags(context: true, device: false);

    // Fresh instances so no roles relation is already loaded.
    $otherFresh = User::find($other->id);
    $armedFresh = User::find($armed->id);

    $roleQueries = fn (array $sql) => count(array_filter(
        $sql,
        fn ($q) => str_contains($q, 'model_has_roles') || str_contains($q, 'roles'),
    ));

    // The cohort answers "no" from memory, so the registry is never reached.
    expect($roleQueries(fbcCapture(fn () => app(FrontOfficeBranchPinResolver::class)->appliesTo($otherFresh))))
        ->toBe(0);

    // An armed account does pay for the role check — that is the guard that
    // keeps a mistyped id from pinning a doctor.
    expect($roleQueries(fbcCapture(fn () => app(FrontOfficeBranchPinResolver::class)->appliesTo($armedFresh))))
        ->toBeGreaterThan(0);
});

it('resolves the pinned branch exactly once per user per resolution', function () {
    $branch = fbcBranch('SPN4');
    fbcMainBranch();
    $user = fbcUser();
    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    // The retired-branch case makes forUser() ask BOTH questions: the pin
    // (which misses) and then the misconfiguration check.
    $branch->update(['is_rme_enabled' => false]);
    $fresh = User::find($user->id);

    $sql = fbcCapture(fn () => app(BranchContext::class)->forUser($fresh));

    $branchLookups = count(array_filter(
        $sql,
        fn ($q) => str_contains($q, 'mst_branches') && str_contains($q, 'code'),
    ));

    expect($branchLookups)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| INTERACTION WITH THE DAILY BRANCH LOCK
|--------------------------------------------------------------------------
|
| Two independent narrowing rules. The pin must never become a way AROUND the
| daily lock — if it did, arming an account would hand it a same-day branch move
| that FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 exists to require approval for.
*/

it('does not let the pin bypass a daily branch lock committed elsewhere', function () {
    $pinned = fbcBranch('SPN4');
    $elsewhere = fbcBranch('LDK2');
    $user = fbcUser();

    // The operator committed today to LDK2 BEFORE being armed.
    fbcFlags(context: false, device: false);
    app(UserOnlineContextService::class)->startAdminClinicSession($user, $elsewhere->id);

    // Now arm them to SPN4.
    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    // The effective branch narrows to the pin immediately...
    expect(app(BranchContext::class)->forUser($user))->toBe($pinned->id);

    // ...but selecting the pinned branch is still refused by the DAILY lock,
    // which the pin does not outrank. The operator waits for the clinical day to
    // roll over, or files a Super Admin branch-change request. Arming mid-day
    // over a different committed branch is therefore a runbook pre-flight check,
    // not something the pin silently resolves.
    expect(fn () => app(UserOnlineContextService::class)->startAdminClinicSession($user, $pinned->id))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| EVERY BRANCH AUTHORITY, NOT JUST BranchContext
|--------------------------------------------------------------------------
|
| Found by adversarial review and reproduced before it was fixed.
|
| `BranchContext::forUser()` is NOT the only authority on an operator's working
| branch. `resolveActiveBranchForAdmin()` decides the branch a NEW CLINIC VISIT
| is registered at, and `RmeWorkingBranchScope` decides what the workspace lists
| — and both read `activeContextBranchId()` directly. Pinning only BranchContext
| produced a split brain: the pin read as "narrowing" while clinical records were
| still being created on the wider branch.
|
| These assert the property the sprint actually claims, at every authority.
*/

it('narrows registration and workspace scope to the pin, not just BranchContext', function () {
    $pinned = fbcBranch('SPN4');
    $stale = fbcBranch('LDK2');
    $user = fbcUser();

    // A context selected BEFORE the account was armed, pointing elsewhere.
    fbcFlags(context: false, device: false);
    app(UserOnlineContextService::class)->startAdminClinicSession($user, $stale->id);

    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    $contexts = app(UserOnlineContextService::class);

    // BranchContext narrows to the pin...
    expect(app(BranchContext::class)->forUser($user))->toBe($pinned->id);

    // ...and so does every other authority. NULL rather than the pin, because
    // resurrecting a working context here would hand the account a same-day
    // branch move the daily lock requires approval for.
    expect($contexts->resolveActiveBranchForAdmin($user))->toBeNull()
        ->and(app(RmeWorkingBranchScope::class)->branchIdsFor($user))->toBe([])
        // ...so the operator is returned to the selector rather than silently
        // carrying a context that resolves to nothing.
        ->and($contexts->hasSatisfiedContext($user))->toBeFalse();
});

it('lets a pinned account work normally once its context is ON the pinned branch', function () {
    $pinned = fbcBranch('SPN4');
    fbcBranch('LDK2');
    $user = fbcUser();
    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    app(UserOnlineContextService::class)->startAdminClinicSession($user, $pinned->id);

    $contexts = app(UserOnlineContextService::class);

    expect(app(BranchContext::class)->forUser($user))->toBe($pinned->id)
        ->and($contexts->resolveActiveBranchForAdmin($user))->toBe($pinned->id)
        ->and(app(RmeWorkingBranchScope::class)->branchIdsFor($user))->toBe([$pinned->id])
        ->and($contexts->hasSatisfiedContext($user))->toBeTrue();
});

it('leaves registration and workspace scope untouched for an account outside the cohort', function () {
    fbcBranch('SPN4');
    $elsewhere = fbcBranch('LDK2');
    $armed = fbcUser('armed4@example.test');
    $other = fbcUser('other4@example.test');
    fbcCohort($armed->id.':SPN4');
    fbcFlags(context: true, device: false);

    app(UserOnlineContextService::class)->startAdminClinicSession($other, $elsewhere->id);

    $contexts = app(UserOnlineContextService::class);

    expect($contexts->resolveActiveBranchForAdmin($other))->toBe($elsewhere->id)
        ->and(app(RmeWorkingBranchScope::class)->branchIdsFor($other))->toBe([$elsewhere->id])
        ->and($contexts->hasSatisfiedContext($other))->toBeTrue();
});

it('refuses a branch-change request that would move a pinned account off its branch', function () {
    fbcBranch('SPN4');
    $elsewhere = fbcBranch('LDK2');
    $user = fbcUser();
    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    // The only writer of an online context branch_id outside start*Session().
    expect(fn () => app(BranchChangeApprovalService::class)
        ->request($user, $elsewhere->id, 'Menutup cabang lain hari ini'))
        ->toThrow(ValidationException::class);
});

it('shows an armed account with an undecidable mapping no selectable branch at all', function () {
    fbcBranch('SPN4');
    fbcBranch('LDK2');
    $user = fbcUser();
    fbcCohort($user->id.':SPN4,'.$user->id.':LDK2');   // duplicate -> undecidable
    fbcFlags(context: true, device: false);

    $response = $this->actingAs($user)->get(route('rme.online-context.select'));

    // Not the full RME list: the server refuses every branch for this account,
    // so offering all of them would be a silent dead end.
    $response->assertOk()
        ->assertDontSee('value="1"', false)
        ->assertDontSee('value="2"', false);
});

it('sends a pinned operator with a conflicting context back to the selector, not to a form it can steer', function () {
    $pinned = fbcBranch('SPN4');
    $stale = fbcBranch('LDK2');
    $user = fbcUser();

    fbcFlags(context: false, device: false);
    app(UserOnlineContextService::class)->startAdminClinicSession($user, $stale->id);

    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    /*
     * This matters because ClinicVisitController::create() and onlineDoctors()
     * both fall back to a REQUEST-supplied branch_id when the working branch is
     * null (`$adminBranchId ?? $request->integer('branch_id')`). Narrowing the
     * chokepoint to null without also making hasSatisfiedContext() pin-aware
     * would have converted a stale-branch leak into a request-steerable one.
     *
     * EnsureRmeOnlineContext now intercepts first, so the crafted branch_id
     * never reaches either fallback.
     */
    $this->actingAs($user)
        ->get(route('rme.visits.create', ['branch_id' => $stale->id]))
        ->assertRedirect(route('rme.online-context.select'));

    $this->actingAs($user)
        ->getJson(route('rme.visits.online-doctors', ['branch_id' => $stale->id]))
        ->assertForbidden();
});

it('refuses the visit-store WRITE itself for a pinned account with no working branch', function () {
    $pinned = fbcBranch('SPN4');
    $stale = fbcBranch('LDK2');
    $user = fbcUser();

    fbcFlags(context: false, device: false);
    app(UserOnlineContextService::class)->startAdminClinicSession($user, $stale->id);

    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    /*
     * The middleware is REMOVED on purpose. `applyAdminClinicBranchContext()`
     * early-returns when the working branch is null, leaving the form's own
     * branch_id to validate against any RME-enabled branch — wider than the bug
     * this sprint fixed. The route guard is not allowed to be the only thing
     * between a crafted POST and that write, so the FormRequest refuses too.
     *
     * authorize() runs before validation, so a 403 here (rather than a 422)
     * is the proof: the request never reached the rules.
     */
    $this->actingAs($user)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.visits.store'), ['branch_id' => $stale->id])
        ->assertForbidden();
});

it('does not refuse the visit-store write for an account outside the cohort', function () {
    fbcBranch('SPN4');
    $elsewhere = fbcBranch('LDK2');
    $armed = fbcUser('armed5@example.test');
    $other = fbcUser('other5@example.test');
    fbcCohort($armed->id.':SPN4');
    fbcFlags(context: true, device: false);

    app(UserOnlineContextService::class)->startAdminClinicSession($other, $elsewhere->id);

    // 422, not 403: authorization passed and the request reached VALIDATION.
    // postJson so a validation failure surfaces as 422 rather than a redirect.
    $this->actingAs($other)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->postJson(route('rme.visits.store'), [])
        ->assertStatus(422);
});

it('offers an armed-but-undecidable account nothing even when a daily context exists', function () {
    $a = fbcBranch('SPN4');
    $b = fbcBranch('LDK2');
    $user = fbcUser();

    // Commit the day FIRST, then arm with a broken mapping.
    fbcFlags(context: false, device: false);
    app(UserOnlineContextService::class)->startAdminClinicSession($user, $b->id);

    fbcCohort($user->id.':SPN4,'.$user->id.':LDK2');   // duplicate -> undecidable
    fbcFlags(context: true, device: false);

    $response = $this->actingAs($user)->get(route('rme.online-context.select'));

    // The daily-locked branch must NOT be offered: the server refuses it.
    $response->assertOk()
        ->assertSee('tidak dapat dibaca')
        ->assertDontSee('value="'.$a->id.'"', false)
        ->assertDontSee('value="'.$b->id.'"', false);
});

it('never pins a context-exempt governance account, even one armed and holding Front Office', function () {
    $pinned = fbcBranch('SPN4');
    fbcMainBranch();

    /*
     * A dual-role account: Front Office AND a governance role that is exempt
     * from the online context. Pinning it would resolve every branch read to
     * null, bounce it to the selector, and 403 it there — a total lockout.
     *
     * The owner's scope also says these roles must remain unchanged, and the
     * doctor branch lock already treats an exempt governance account as
     * non-locking.
     */
    foreach (['Owner', 'Super Admin', 'Supervisor RME'] as $i => $role) {
        $user = fbcUser('dual'.$i.'@example.test')->assignRole($role);
        fbcCohort($user->id.':SPN4');
        fbcFlags(context: true, device: false);

        expect(fbcPin()->appliesTo($user))->toBeFalse()
            ->and(fbcPin()->requiredBranchIdFor($user))->toBeNull()
            ->and(fbcPin()->isMisconfiguredFor($user))->toBeFalse()
            // and therefore no new 403 on registration
            ->and(app(UserOnlineContextService::class)->hasSatisfiedContext($user))->toBeTrue();
    }
});

it('does not 403 a dual-role cohort account that is online at its own pinned branch', function () {
    $pinned = fbcBranch('SPN4');
    fbcBranch('LDK2');
    fbcMainBranch();

    /*
     * FINDING C. `resolveActiveBranchForAdmin()` answers for the admin_clinic and
     * perawat contexts ONLY — it returns null for a DOCTOR context even when the
     * working branch is perfectly resolvable and equals the pin. An account
     * holding both Front Office and Doctor, online as a doctor at its own pinned
     * branch, therefore passes the middleware and then hits authorize() === false.
     *
     * A false 403 on visit registration is a clinical-scale outage, so the guard
     * must key off the pin-narrowed working branch, not off one role context.
     */
    $doctor = Doctor::factory()->create();
    $user = rmeMakeDoctorOnline($doctor, $pinned);
    $user->assignRole(FrontOfficeRole::NAME);

    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    // 422, not 403: authorization must pass and the request must reach validation.
    $this->actingAs($user->fresh())
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->postJson(route('rme.visits.store'), [])
        ->assertStatus(422);
});

it('overwrites a crafted branch_id for a pinned account whose context is not admin-clinic', function () {
    $pinned = fbcBranch('SPN4');
    $other = fbcBranch('LDK2');
    fbcMainBranch();

    /*
     * THE ADMIN-CLINIC CASE PROVES NOTHING HERE — `applyAdminClinicBranchContext()`
     * already overwrites a crafted branch_id for an admin_clinic context, so a
     * test using one passes with or WITHOUT the pinned-branch merge (confirmed by
     * mutation: the merge survived removal).
     *
     * The load-bearing case is a context that helper does not answer for. With a
     * DOCTOR context `resolveActiveBranchForAdmin()` is null, it early-returns,
     * and the form's branch_id survives to validate against ANY RME-enabled
     * branch. `applyFrontOfficePinnedBranchContext()` is the only thing that
     * overwrites it.
     *
     * Asserted on the FormRequest itself rather than through a full registration:
     * a Doctor-role account is additionally narrowed by the clinical patient
     * scope, which would fail the request for an unrelated reason and prove
     * nothing about the branch.
     */
    $doctor = Doctor::factory()->create();
    $user = rmeMakeDoctorOnline($doctor, $pinned);
    $user->assignRole(FrontOfficeRole::NAME);

    fbcCohort($user->id.':SPN4');
    fbcFlags(context: true, device: false);

    $fresh = $user->fresh();

    // Sanity: this really is the path where the admin-clinic helper gives up.
    expect(app(UserOnlineContextService::class)->resolveActiveBranchForAdmin($fresh))->toBeNull()
        ->and(app(UserOnlineContextService::class)->activeContextBranchId($fresh))->toBe($pinned->id);

    $request = StoreClinicVisitRequest::create(
        route('rme.visits.store'),
        'POST',
        ['patient_mode' => 'existing', 'branch_id' => $other->id],
    );
    $request->setUserResolver(fn () => $fresh);

    $prepare = new ReflectionMethod($request, 'prepareForValidation');
    $prepare->setAccessible(true);
    $prepare->invoke($request);

    expect((int) $request->input('branch_id'))->toBe($pinned->id)
        ->and((int) $request->input('branch_id'))->not->toBe($other->id);
});
