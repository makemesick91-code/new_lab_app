<?php

/**
 * REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1 Stage 2 — break-glass.
 *
 * The rule under test: an authorized approver may admit ONE named doctor
 * account to a session without a trusted device, for a bounded window, with a
 * written reason, revocably — and nothing about that widens access for anybody
 * else or disables enforcement.
 *
 * Driven through the service and the gate rather than through HTTP, so a
 * failure names the rule that broke rather than the screen that showed it.
 */

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Models\DoctorBreakGlassGrant;
use App\Modules\DoctorAccess\Services\DoctorBreakGlassService;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Arm enforcement exactly as the existing gate suite does. */
function bgEnforce(bool $on = true): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $on;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $on;
    config()->set('feature_flags.flags', $flags);

    config()->set('doctor_device_enforcement.scope', array_merge(
        (array) config('doctor_device_enforcement.scope'),
        ['mode' => 'unscoped'],
    ));
    config()->set('android_release.enforcement.scope', array_merge(
        (array) config('android_release.enforcement.scope'),
        ['global_permitted' => true],
    ));
}

/** A doctor ACCOUNT — the id space the gate actually narrows on. */
function bgDoctorAccount(string $name = 'drg BreakGlass'): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->assignRole('Doctor');
    Doctor::factory()->create(['user_id' => $user->id, 'is_active' => true]);

    return $user->fresh();
}

function bgApprover(): User
{
    $user = User::factory()->create(['name' => 'Supervisor']);
    $user->givePermissionTo('grant_doctor_break_glass_access');

    return $user->fresh();
}

function bgService(): DoctorBreakGlassService
{
    return app(DoctorBreakGlassService::class);
}

/** A request carrying a real session and NO device binding — a plain browser. */
function bgBrowserRequest(): Request
{
    $request = Request::create('/dashboard');
    $request->setLaravelSession(app('session.store'));

    return $request;
}

function bgDenyReason(User $user): ?string
{
    return app(DoctorAppLoginGate::class)->denyBrowserSessionReason($user, bgBrowserRequest());
}

beforeEach(function () {
    seedAccessControl();
    bgEnforce(true);
});

/*
|--------------------------------------------------------------------------
| Creation — who may file a grant, and on what terms
|--------------------------------------------------------------------------
*/

it('lets an authorized approver file a bounded grant', function () {
    $doctor = bgDoctorAccount();
    $approver = bgApprover();

    $grant = bgService()->grant($doctor, $approver, 'Tablet SPN4 mati total saat jam praktek.', 4);

    expect($grant->user_id)->toBe($doctor->id)
        ->and($grant->granted_by)->toBe($approver->id)
        ->and($grant->isActive())->toBeTrue();

    // Asserted as an instant rather than a signed diff: diffInHours() is
    // directional and returns a float, so it invites exactly the off-by-sign
    // this line originally had.
    expect($grant->expires_at->equalTo($grant->granted_at->copy()->addHours(4)))->toBeTrue();
});

it('refuses an actor who does not hold the permission', function () {
    $doctor = bgDoctorAccount();
    $outsider = User::factory()->create();

    expect(fn () => bgService()->grant($doctor, $outsider, 'Alasan yang cukup panjang.', 2))
        ->toThrow(ValidationException::class);

    expect(DoctorBreakGlassGrant::query()->count())->toBe(0);
});

it('refuses a grant with no usable written reason', function () {
    $doctor = bgDoctorAccount();
    $approver = bgApprover();

    // 'asdf' passes a non-empty check and explains nothing.
    expect(fn () => bgService()->grant($doctor, $approver, 'asdf', 2))
        ->toThrow(ValidationException::class);

    expect(DoctorBreakGlassGrant::query()->count())->toBe(0);
});

it('refuses a window outside the configured bound', function () {
    $doctor = bgDoctorAccount();
    $approver = bgApprover();
    $max = (int) config('doctor_access.break_glass.max_hours');

    expect(fn () => bgService()->grant($doctor, $approver, 'Alasan darurat yang jelas.', $max + 1))
        ->toThrow(ValidationException::class);

    expect(fn () => bgService()->grant($doctor, $approver, 'Alasan darurat yang jelas.', 0))
        ->toThrow(ValidationException::class);

    expect(DoctorBreakGlassGrant::query()->count())->toBe(0);
});

it('refuses a grant aimed at an account that is not a doctor', function () {
    $kasir = User::factory()->create();
    $kasir->assignRole('Kasir');
    $approver = bgApprover();

    expect(fn () => bgService()->grant($kasir, $approver, 'Alasan darurat yang jelas.', 2))
        ->toThrow(ValidationException::class);
});

it('refuses a second overlapping grant rather than stacking the window', function () {
    $doctor = bgDoctorAccount();
    $approver = bgApprover();

    bgService()->grant($doctor, $approver, 'Tablet rusak, sesi pertama.', 2);

    expect(fn () => bgService()->grant($doctor, $approver, 'Perpanjang diam-diam.', 12))
        ->toThrow(ValidationException::class);

    expect(DoctorBreakGlassGrant::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The gate — what a grant actually admits
|--------------------------------------------------------------------------
*/

it('denies a doctor with no grant, which is the unchanged baseline', function () {
    $doctor = bgDoctorAccount();

    expect(bgDenyReason($doctor))->toBe(DoctorAppLoginGate::DENY_NO_DEVICE_SESSION);
});

it('admits the named doctor while the window is open', function () {
    $doctor = bgDoctorAccount();
    bgService()->grant($doctor, bgApprover(), 'Tablet cabang mati, pasien menunggu.', 4);

    expect(bgDenyReason($doctor))->toBeNull();
});

it('does not admit a different doctor on someone elses grant', function () {
    $granted = bgDoctorAccount('drg Granted');
    $other = bgDoctorAccount('drg Other');

    bgService()->grant($granted, bgApprover(), 'Hanya untuk dokter ini.', 4);

    expect(bgDenyReason($granted))->toBeNull();
    expect(bgDenyReason($other))->toBe(DoctorAppLoginGate::DENY_NO_DEVICE_SESSION);
});

it('stops admitting once the window has closed', function () {
    $doctor = bgDoctorAccount();
    bgService()->grant($doctor, bgApprover(), 'Jendela darurat dua jam.', 2);

    expect(bgDenyReason($doctor))->toBeNull();

    // Nothing has to RUN for this: expiry is resolved from the clock.
    Carbon::setTestNow(Carbon::now()->addHours(3));

    expect(bgDenyReason($doctor))->toBe(DoctorAppLoginGate::DENY_NO_DEVICE_SESSION);

    Carbon::setTestNow();
});

it('stops admitting the moment the grant is revoked', function () {
    $doctor = bgDoctorAccount();
    $approver = bgApprover();
    $grant = bgService()->grant($doctor, $approver, 'Diberikan lalu dicabut.', 12);

    expect(bgDenyReason($doctor))->toBeNull();

    bgService()->revoke($grant, $approver, 'Tablet pengganti sudah tiba.');

    // Revocation beats the remaining eleven hours; they are not a grace period.
    expect(bgDenyReason($doctor))->toBe(DoctorAppLoginGate::DENY_NO_DEVICE_SESSION);
});

it('ends an already established session, not just the next login', function () {
    $doctor = bgDoctorAccount();
    $approver = bgApprover();
    $grant = bgService()->grant($doctor, $approver, 'Sesi berjalan lalu dicabut.', 12);

    $request = bgBrowserRequest();
    $gate = app(DoctorAppLoginGate::class);

    expect($gate->denySessionReason($doctor, $request))->toBeNull();

    bgService()->revoke($grant->fresh(), $approver, 'Insiden selesai.');

    expect($gate->denySessionReason($doctor, $request))
        ->toBe(DoctorAppLoginGate::DENY_NO_DEVICE_SESSION);
});

/*
|--------------------------------------------------------------------------
| Blast radius — what a grant must NOT change
|--------------------------------------------------------------------------
*/

it('leaves every other doctor under normal enforcement', function () {
    $granted = bgDoctorAccount('drg Granted');
    $untouched = bgDoctorAccount('drg Untouched');

    bgService()->grant($granted, bgApprover(), 'Satu dokter saja.', 4);

    expect(bgDenyReason($untouched))->toBe(DoctorAppLoginGate::DENY_NO_DEVICE_SESSION);

    // GLOBAL_ENFORCEMENT_DISABLED = NO.
    expect(app(DoctorAppLoginGate::class)->enforcementEnabled())->toBeTrue();
});

it('leaves a non-doctor account completely unaffected', function () {
    $kasir = User::factory()->create();
    $kasir->assignRole('Kasir');

    // The gate narrows on the Doctor role, so this is null with or without any
    // grant in the table — asserted so break-glass cannot quietly widen it.
    expect(bgDenyReason($kasir))->toBeNull();

    bgService()->grant(bgDoctorAccount(), bgApprover(), 'Tidak ada hubungannya.', 4);

    expect(bgDenyReason($kasir))->toBeNull();
});

it('confers no permission beyond the session admission', function () {
    $doctor = bgDoctorAccount();
    bgService()->grant($doctor, bgApprover(), 'Hanya membuka sesi.', 4);

    $doctor->refresh();

    expect($doctor->can('grant_doctor_break_glass_access'))->toBeFalse()
        ->and($doctor->can('manage_doctor_device_authorizations'))->toBeFalse()
        ->and($doctor->can('manage_doctor_devices'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Evidence
|--------------------------------------------------------------------------
*/

it('audits the grant, the first use and the revocation', function () {
    $doctor = bgDoctorAccount();
    $approver = bgApprover();

    $grant = bgService()->grant($doctor, $approver, 'Tablet mati, butuh akses darurat.', 4);

    // First use is stamped by the admitting login, not by every later request.
    bgDenyReason($doctor);

    bgService()->revoke($grant->fresh(), $approver, 'Sudah selesai.');

    $actions = AuditLog::query()
        ->where('entity_type', DoctorBreakGlassService::ENTITY)
        ->where('entity_id', $grant->id)
        ->pluck('action')
        ->all();

    expect($actions)->toContain(
        DoctorBreakGlassService::ACTION_GRANTED,
        DoctorBreakGlassService::ACTION_FIRST_USED,
        DoctorBreakGlassService::ACTION_REVOKED,
    );

    expect($grant->fresh()->first_used_at)->not->toBeNull();
});

it('is idempotent on revoke and keeps the first revocation', function () {
    $doctor = bgDoctorAccount();
    $approver = bgApprover();
    $grant = bgService()->grant($doctor, $approver, 'Sekali cabut saja.', 4);

    $first = bgService()->revoke($grant, $approver, 'Alasan pencabutan pertama.');
    $second = bgService()->revoke($first->fresh(), $approver, 'Alasan pencabutan kedua.');

    expect($second->revoked_reason)->toBe('Alasan pencabutan pertama.')
        ->and($second->revoked_at->equalTo($first->revoked_at))->toBeTrue();
});

it('gives the break-glass permission to Supervisor RME and to nobody else by default', function () {
    $supervisor = User::factory()->create();
    $supervisor->assignRole('Supervisor RME');

    expect($supervisor->can('grant_doctor_break_glass_access'))->toBeTrue();

    foreach (['Doctor', 'Kasir', 'Perawat', 'Admin Klinik'] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role);

        expect($user->can('grant_doctor_break_glass_access'))
            ->toBeFalse("role {$role} must not hold break-glass authority");
    }
});

/*
|--------------------------------------------------------------------------
| The console surface — authorization is server-side, not a hidden button
|--------------------------------------------------------------------------
*/

it('keeps the console off limits to everyone without the permission', function () {
    $index = route('rme.doctor-break-glass.index');

    $this->get($index)->assertRedirect(route('login'));

    // Enforcement OFF for this one: it is about the PERMISSION boundary, and
    // an armed device gate would bounce a Doctor-role account with a 302
    // before the permission middleware ever ran — which would leave this test
    // passing for a reason it does not name.
    bgEnforce(false);

    foreach (['Doctor', 'Kasir', 'Perawat', 'Admin Klinik'] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)
            ->withoutMiddleware(EnsureRmeOnlineContext::class)
            ->get($index)
            ->assertForbidden("role {$role} must not reach the break-glass console");
    }
});

it('bounces a doctor whose own device path is unmet before any permission check', function () {
    // The complement of the test above, stated rather than left implicit:
    // under enforcement a doctor without a device never reaches the console,
    // and that denial comes from the device gate, not from the permission.
    $doctor = bgDoctorAccount();

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.doctor-break-glass.index'))
        ->assertStatus(302);
});

it('refuses a crafted grant POST from an account without the permission', function () {
    $doctor = bgDoctorAccount();
    $outsider = User::factory()->create();
    $outsider->assignRole('Kasir');

    $this->actingAs($outsider)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.doctor-break-glass.store'), [
            'user_id' => $doctor->id,
            'reason' => 'Mencoba lewat jalur belakang.',
            'hours' => 4,
        ])
        ->assertForbidden();

    expect(DoctorBreakGlassGrant::query()->count())->toBe(0);
});

it('lets an authorized approver open the console and see the window', function () {
    $doctor = bgDoctorAccount('drg Terlihat');
    $approver = bgApprover();

    bgService()->grant($doctor, $approver, 'Tablet mati, tercatat di konsol.', 4);

    $this->actingAs($approver)
        ->get(route('rme.doctor-break-glass.index'))
        ->assertOk()
        ->assertSee('Akses Darurat Dokter')
        ->assertSee('drg Terlihat')
        ->assertSee('Aktif');
});

it('renders the console through the canonical authenticated shell', function () {
    // Sidebar presence is UX, never authorization — but a device-workflow page
    // dropping out of the app shell is the regression this asserts against.
    $view = file_get_contents(base_path('resources/views/rme/doctor-break-glass/index.blade.php'));

    expect($view)->toContain('<x-settings-shell')
        ->and($view)->not->toContain('<x-guest-layout');
});
