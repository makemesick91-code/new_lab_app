<?php

/*
|--------------------------------------------------------------------------
| HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1 — active-account authority
|--------------------------------------------------------------------------
|
| `users.is_active` is the ONLY account-status column this schema has (plus
| `deleted_at` from SoftDeletes). There is no "suspended" state, and none is
| invented here. Reproduced on the unmodified base (b1e6d3c6):
|
|   - the password form logged a deactivated account straight in
|     (LoginRequest called Auth::attempt with email + password only);
|   - a session opened BEFORE the account was deactivated kept working on
|     every policy-only surface (rule 198 §30a, rule 199 §24/§27c);
|   - a remember-me cookie restored a deactivated account;
|   - the Android device login minted a ticket for a deactivated account
|     whose doctor record was still active.
|
| Soft-deleted accounts were already safe at the session layer: the Eloquent
| provider's SoftDeletes scope resolves them to null. That is asserted here so
| a future custom provider cannot quietly lose it.
*/

use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Middleware\EnsureDoctorSessionLease;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\RmeOnlineContext\Middleware\TouchOnlineContextLastSeen;
use App\Modules\RmeOnlineContext\Models\UserOnlineContext;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Support\AccessControl\ActiveAccountResolver;
use Database\Seeders\BranchSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../DoctorAccess/helpers.php';

beforeEach(function () {
    seedAccessControl();
});

function aasLoginPayload(User $user, string $password = 'password'): array
{
    return ['email' => $user->email, 'password' => $password];
}

function aasAuditRows(): Collection
{
    return DB::table('sys_audit_logs')->where('action', EnsureAccountIsActive::AUDIT_ACTION)->get();
}

/** A remember-me cookie exactly as the session guard writes it. */
function aasRecaller(User $user): array
{
    $token = 'aas-remember-'.$user->id;
    $user->forceFill(['remember_token' => $token])->saveQuietly();

    return [
        Auth::guard('web')->getRecallerName(),
        $user->id.'|'.$token.'|'.Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword()),
    ];
}

/*
|--------------------------------------------------------------------------
| A. The persisted row is the authority
|--------------------------------------------------------------------------
*/

it('treats only a persisted, active, non-deleted account as usable', function (string $case, bool $usable) {
    $user = User::factory()->create();

    $subject = match ($case) {
        'active' => $user,
        'inactive' => tap($user, fn (User $u) => $u->forceFill(['is_active' => false])->save()),
        'soft-deleted' => tap($user, fn (User $u) => $u->delete()),
        'unsaved object carrying a real id' => (new User)->forceFill(['id' => $user->id, 'is_active' => true]),
        // Loaded while active (exists, is_active=true in memory), deactivated
        // underneath it afterwards — exactly what a long-lived session holds.
        'stale object of a deactivated account' => tap(User::query()->find($user->id), function () use ($user) {
            User::query()->whereKey($user->id)->update(['is_active' => false]);
        }),
        'null' => null,
    };

    expect(app(ActiveAccountResolver::class)->isUsable($subject))->toBe($usable)
        ->and(app(ActiveAccountResolver::class)->resolveUsable($subject) !== null)->toBe($usable);
})->with([
    ['active', true],
    ['inactive', false],
    ['soft-deleted', false],
    ['unsaved object carrying a real id', false],
    ['stale object of a deactivated account', false],
    ['null', false],
]);

/*
|--------------------------------------------------------------------------
| B. Password login
|--------------------------------------------------------------------------
*/

it('logs an active account in exactly as before', function () {
    $user = User::factory()->create();

    $this->post('/login', aasLoginPayload($user))->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('refuses a deactivated account at the login form and says why only once the password is proven', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->post('/login', aasLoginPayload($user))
        ->assertSessionHasErrors(['email' => ActiveAccountResolver::INACTIVE_MESSAGE]);

    $this->assertGuest();
});

it('gives a deactivated account with a wrong password the generic failure, never the inactive one', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->post('/login', aasLoginPayload($user, 'wrong-password'))
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
});

it('refuses a soft-deleted account at the login form with the generic failure', function () {
    $user = User::factory()->create();
    $user->delete();

    $this->post('/login', aasLoginPayload($user))
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
});

it('counts a refused inactive login against the rate limiter', function () {
    $user = User::factory()->create(['is_active' => false]);

    foreach (range(1, 5) as $_) {
        $this->post('/login', aasLoginPayload($user));
    }

    $this->post('/login', aasLoginPayload($user))
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->not->toBe(ActiveAccountResolver::INACTIVE_MESSAGE);
});

it('keeps every operational role able to log in', function (string $role) {
    $user = userInRole($role);

    $this->post('/login', aasLoginPayload($user))->assertRedirect();

    $this->assertAuthenticatedAs($user);
})->with(['Super Admin', 'Doctor', 'Front Office', 'Kasir', 'Admin Klinik', 'Supervisor RME', 'Owner']);

/*
|--------------------------------------------------------------------------
| C. A session opened before deactivation (stale session)
|--------------------------------------------------------------------------
*/

it('ends a session whose account was deactivated after it logged in', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    // An administrator deactivates the account while the session is alive.
    User::query()->whereKey($user->id)->update(['is_active' => false]);

    $this->get(route('profile.edit'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => ActiveAccountResolver::INACTIVE_MESSAGE]);

    $this->assertGuest();
});

it('answers a stale JSON request with 401 rather than a redirect', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    User::query()->whereKey($user->id)->update(['is_active' => false]);

    $this->getJson(route('profile.edit'))->assertUnauthorized();
    $this->assertGuest();
});

it('stops a deactivated account before any controller runs, on a POST as well', function () {
    $user = User::factory()->create(['name' => 'Nama Asli']);
    $this->actingAs($user);

    User::query()->whereKey($user->id)->update(['is_active' => false]);

    $this->patch(route('profile.update'), ['name' => 'Diubah', 'email' => $user->email])
        ->assertRedirect(route('login'));

    expect(User::query()->find($user->id)->name)->toBe('Nama Asli');
});

it('writes exactly one PII-free audit row per terminated session', function () {
    $user = User::factory()->create(['email' => 'pii-probe@example.test', 'name' => 'Nama Rahasia']);
    $this->actingAs($user);

    User::query()->whereKey($user->id)->update(['is_active' => false]);

    $this->get(route('profile.edit'));
    $this->get(route('profile.edit'));
    $this->get(route('profile.edit'));

    $rows = aasAuditRows();

    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    $payload = (string) $row->new_values;

    expect((int) $row->entity_id)->toBe((int) $user->id)
        ->and($payload)->toContain('account_inactive')
        ->and($payload)->not->toContain('pii-probe@example.test')
        ->and($payload)->not->toContain('Nama Rahasia')
        ->and($payload)->not->toContain((string) session()->getId());
});

it('lets an active account through on every request without writing anything', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (range(1, 3) as $_) {
        $this->get(route('profile.edit'))->assertOk();
    }

    $this->assertAuthenticatedAs($user);
    expect(aasAuditRows())->toHaveCount(0);
});

it('drops the presence of a deactivated doctor so the clinic room is freed', function () {
    test()->seed(BranchSeeder::class);
    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $room = ClinicRoom::factory()->create(['branch_id' => $branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $doctor = Doctor::factory()->withAllowedBranches([$branch])->create(['is_active' => true]);
    $user = rmeMakeDoctorOnline($doctor, $branch, $room);

    $this->actingAs($user);
    User::query()->whereKey($user->id)->update(['is_active' => false]);

    $this->get(route('profile.edit'))->assertRedirect(route('login'));

    $context = UserOnlineContext::query()->where('user_id', $user->id)->firstOrFail();

    expect($context->status)->toBe(UserOnlineContext::STATUS_OFFLINE)
        ->and($context->clinic_room_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| D. Remember-me cookie
|--------------------------------------------------------------------------
*/

it('restores an active account from its remember-me cookie (positive control)', function () {
    $user = User::factory()->create();
    [$name, $value] = aasRecaller($user);

    $this->withCookie($name, $value)->get(route('profile.edit'))->assertOk();

    $this->assertAuthenticatedAs($user);
});

it('does not let a remember-me cookie restore a deactivated account, and burns the token', function () {
    $user = User::factory()->create();
    [$name, $value] = aasRecaller($user);
    $user->forceFill(['is_active' => false])->saveQuietly();

    $this->withCookie($name, $value)->get(route('profile.edit'))->assertRedirect(route('login'));

    $this->assertGuest();
    expect(User::query()->find($user->id)->remember_token)->not->toBe('aas-remember-'.$user->id);
});

it('does not let a remember-me cookie restore a soft-deleted account', function () {
    $user = User::factory()->create();
    [$name, $value] = aasRecaller($user);
    $user->delete();

    $this->withCookie($name, $value)->get(route('profile.edit'))->assertRedirect(route('login'));

    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| E. Authority revoked while the account stays active
|--------------------------------------------------------------------------
*/

function aasRegistrar(): array
{
    test()->seed(BranchSeeder::class);
    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $user = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($user, $branch);

    return [$user, $branch];
}

it('honours a revoked role on the very next request', function () {
    [$user] = aasRegistrar();
    $this->actingAs($user)->get(route('rme.visits.index'))->assertOk();

    $user->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->get(route('rme.visits.index'))->assertForbidden();
});

it('honours a permission revoked from the role on the very next request', function () {
    [$user] = aasRegistrar();
    $this->actingAs($user)->get(route('rme.visits.index'))->assertOk();

    Role::findByName('Admin Klinik')->revokePermissionTo(['view_clinic_visits', 'manage_clinic_visits']);

    $this->get(route('rme.visits.index'))->assertForbidden();
});

it('judges service authority on the persisted roles, not the ones carried by a stale object', function () {
    [$user, $branch] = aasRegistrar();
    $stale = User::query()->with('roles')->find($user->id);

    $user->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $patient = Patient::factory()->create(['branch_id' => $branch->id]);

    expect(fn () => app(ClinicVisitService::class)->create([
        'patient_mode' => 'existing',
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
    ], $stale))->toThrow(AuthorizationException::class);

    expect(ClinicVisit::query()->count())->toBe(0);
});

it('empties a context-bound account\'s scope when its working branch is deactivated', function () {
    [$user, $branch] = aasRegistrar();

    expect(app(RmeWorkingBranchScope::class)->branchIdsFor($user))->toBe([(int) $branch->id]);

    $branch->forceFill(['is_active' => false])->save();

    expect(app(RmeWorkingBranchScope::class)->branchIdsFor($user))->toBe([])
        ->and(app(ClinicVisitService::class)->scopedBranchIdsFor($user))->toBe([]);
});

it('empties a context-bound account\'s scope when its working context has ended', function () {
    [$user] = aasRegistrar();

    UserOnlineContext::query()->where('user_id', $user->id)->update(['status' => UserOnlineContext::STATUS_OFFLINE]);

    expect(app(ClinicVisitService::class)->scopedBranchIdsFor($user))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| F. Surfaces that must stay untouched
|--------------------------------------------------------------------------
*/

it('leaves guest pages and health endpoints untouched', function () {
    $this->get('/login')->assertOk();
    $this->get(route('health.live'))->assertOk();
    $this->assertGuest();
    expect(aasAuditRows())->toHaveCount(0);
});

it('registers the active-account middleware once, after the session starts and before presence is touched', function () {
    app(Kernel::class);

    $group = array_values(array_map(
        static fn ($entry) => is_string($entry) ? $entry : $entry::class,
        app('router')->getMiddlewareGroups()['web'] ?? [],
    ));

    $active = array_keys($group, EnsureAccountIsActive::class, true);

    expect($active)->toHaveCount(1);

    $position = $active[0];

    expect($position)->toBeGreaterThan(array_search(StartSession::class, $group, true))
        ->and($position)->toBeGreaterThan(array_search(EnsureDoctorSessionLease::class, $group, true))
        ->and($position)->toBeLessThan(array_search(TouchOnlineContextLastSeen::class, $group, true))
        ->and($position)->toBeLessThan(array_search(EnsureRmeOnlineContext::class, $group, true));
});

it('keeps a single definition of a usable account behind both actor guards', function () {
    $sources = [
        file_get_contents(app_path('Modules/ClinicVisit/Services/ClinicVisitActorGuard.php')),
        file_get_contents(app_path('Modules/RmeInvoice/Services/RmeBillingActorGuard.php')),
        file_get_contents(app_path('Http/Middleware/EnsureAccountIsActive.php')),
        // FIX-RME-INVOICE-CORRECTION-ZERO-ORIGINAL-GUARD-1 (rule 201) — the
        // correction service decides every mutation on the persisted account.
        file_get_contents(app_path('Modules/RmeInvoice/Services/RmeInvoiceCorrectionService.php')),
    ];

    foreach ($sources as $source) {
        expect($source)->toContain('ActiveAccountResolver')
            ->and($source)->not->toContain("where('is_active', true)");
    }
});

it('releases a deactivated doctor\'s single-session lease with its own reason', function () {
    daArmDoctorAccess();
    $user = daDoctorUser([daBranch()]);

    daLoginPost($user)->assertRedirect();
    $lease = daCurrentLease($user);

    expect($lease)->not->toBeNull('fixture failed: no lease was claimed');

    User::query()->whereKey($user->id)->update(['is_active' => false]);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('profile.edit'))
        ->assertRedirect(route('login'));

    $this->assertGuest();

    $released = DoctorSessionLease::query()->findOrFail($lease->id);

    expect($released->released_at)->not->toBeNull()
        ->and($released->released_reason)->toBe(DoctorSessionLease::RELEASE_ACCOUNT_INACTIVE);
    daAssertActiveLeaseCount(0, $user);
});

it('starts a fresh, unauthenticated session after a deactivated account is stopped', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    $sessionBefore = session()->getId();
    session()->put('aas-marker', 'held-by-the-old-session');

    User::query()->whereKey($user->id)->update(['is_active' => false]);

    $this->get(route('profile.edit'))->assertRedirect(route('login'));

    expect(session()->getId())->not->toBe($sessionBefore)
        ->and(session()->has('aas-marker'))->toBeFalse();
});
