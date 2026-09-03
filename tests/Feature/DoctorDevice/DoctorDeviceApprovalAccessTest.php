<?php

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — Approval Device
 * Dokter: who may see the inbox, who may decide, and what the screen leaks.
 *
 * Sidebar visibility is tested here too, but only as a courtesy check. The
 * boundary is asserted the way it is enforced: by hitting the routes directly
 * as each role.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Policies\DoctorDeviceAuthorizationPolicy;
use App\Modules\DoctorDevice\Services\DoctorDeviceAuthorizationService;
use App\Modules\DoctorDevice\Support\DeviceKeyMaterial;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Database\Factories\DoctorDeviceEnrollmentFactory;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

function approvalFixture(): array
{
    seedAccessControl();

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $doctor = Doctor::factory()->withAllowedBranches([$branch])->create([
        'is_active' => true, 'name' => 'dr. Ahmad Uji',
    ]);

    [$pub] = DoctorDeviceEnrollmentFactory::generateKeyPair();
    $device = DoctorDevice::factory()->create([
        'branch_id' => $branch->id,
        'device_name' => 'Tablet Landak 01',
        'public_key' => $pub,
        'public_key_fingerprint' => DeviceKeyMaterial::fingerprint($pub),
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
    ]);

    $authorization = DoctorDeviceAuthorization::factory()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    return compact('branch', 'doctor', 'device', 'authorization');
}

/** A role user that is not blocked by the RME online-context middleware. */
function approvalActor(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

// ---------------------------------------------------------------------------
// Who gets in
// ---------------------------------------------------------------------------

it('lets a super admin review and decide', function () {
    $f = approvalFixture();
    $admin = superAdmin();

    actingAs($admin)->get(route('doctor-device-authorizations.index'))->assertOk();
    actingAs($admin)->get(route('doctor-device-authorizations.show', $f['authorization']))->assertOk();

    actingAs($admin)->post(route('doctor-device-authorizations.approve', $f['authorization']))
        ->assertRedirect();

    expect($f['authorization']->fresh()->status)->toBe(DoctorDeviceAuthorization::STATUS_ACTIVE);
});

it('lets a supervisor rme review and decide through the permission, not a role name', function () {
    $f = approvalFixture();
    $supervisor = approvalActor('Supervisor RME');

    // The seeded role really holds both permissions.
    expect($supervisor->can('view_doctor_device_authorizations'))->toBeTrue()
        ->and($supervisor->can('manage_doctor_device_authorizations'))->toBeTrue();

    actingAs($supervisor)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('doctor-device-authorizations.index'))->assertOk();

    actingAs($supervisor)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('doctor-device-authorizations.reject', $f['authorization']), [
            'reason' => 'Perangkat pribadi dokter.',
        ])->assertRedirect();

    expect($f['authorization']->fresh()->status)->toBe(DoctorDeviceAuthorization::STATUS_REJECTED);
});

it('refuses a supervisor rme whose approval permission has been withdrawn', function () {
    $f = approvalFixture();

    Role::findByName('Supervisor RME')->revokePermissionTo('manage_doctor_device_authorizations');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $supervisor = approvalActor('Supervisor RME');

    // Still allowed to LOOK — the two permissions are genuinely separate.
    actingAs($supervisor)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('doctor-device-authorizations.index'))->assertOk();

    actingAs($supervisor)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('doctor-device-authorizations.approve', $f['authorization']))
        ->assertForbidden();

    expect($f['authorization']->fresh()->status)->toBe(DoctorDeviceAuthorization::STATUS_PENDING);
});

it('refuses every other role at the route, not merely in the sidebar', function () {
    $f = approvalFixture();

    foreach (['Doctor', 'Kasir', 'Admin Klinik', 'Perawat', 'Owner', 'Admin Lab'] as $role) {
        $user = approvalActor($role);

        actingAs($user)->withoutMiddleware(EnsureRmeOnlineContext::class)
            ->get(route('doctor-device-authorizations.index'))
            ->assertForbidden("{$role} must not reach the approval inbox");

        actingAs($user)->withoutMiddleware(EnsureRmeOnlineContext::class)
            ->get(route('doctor-device-authorizations.show', $f['authorization']))
            ->assertForbidden("{$role} must not read an approval request");

        actingAs($user)->withoutMiddleware(EnsureRmeOnlineContext::class)
            ->post(route('doctor-device-authorizations.approve', $f['authorization']))
            ->assertForbidden("{$role} must not approve");
    }

    expect($f['authorization']->fresh()->status)->toBe(DoctorDeviceAuthorization::STATUS_PENDING);
});

it('refuses a guest', function () {
    $f = approvalFixture();

    test()->get(route('doctor-device-authorizations.index'))->assertRedirect(route('login'));
    test()->post(route('doctor-device-authorizations.approve', $f['authorization']))
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// The policy carries its own weight
// ---------------------------------------------------------------------------

it('denies at the policy, not only at the route middleware', function () {
    // MUTATION FINDING. Deleting the permission check from
    // DoctorDeviceAuthorizationPolicy survived every HTTP test above, because
    // the route's `permission:` middleware refuses first and the policy never
    // runs. Defence in depth is why that is safe — and exactly why the inner
    // layer needs coverage of its own, or it can rot unnoticed until the day
    // someone reaches it from a console command, a job, or a new route that
    // forgets the middleware.
    $f = approvalFixture();
    $policy = app(DoctorDeviceAuthorizationPolicy::class);

    foreach (['Doctor', 'Kasir', 'Admin Klinik', 'Perawat', 'Owner', 'Admin Lab'] as $role) {
        $user = approvalActor($role);

        expect($policy->viewAny($user))->toBeFalse("{$role} must not read the inbox")
            ->and($policy->view($user, $f['authorization']))->toBeFalse("{$role} must not read a request")
            ->and($policy->decide($user, $f['authorization']))->toBeFalse("{$role} must not decide");
    }

    $supervisor = approvalActor('Supervisor RME');
    expect($policy->viewAny($supervisor))->toBeTrue()
        ->and($policy->decide($supervisor, $f['authorization']))->toBeTrue();

    // Read and decide are genuinely separate authorities.
    Role::findByName('Supervisor RME')->revokePermissionTo('manage_doctor_device_authorizations');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $readOnly = approvalActor('Supervisor RME');

    expect($policy->viewAny($readOnly))->toBeTrue()
        ->and($policy->decide($readOnly, $f['authorization']))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Supervisor RME gained this capability and nothing else
// ---------------------------------------------------------------------------

it('does not hand supervisor rme the device registry or any enforcement authority', function () {
    approvalFixture();
    $supervisor = approvalActor('Supervisor RME');

    // Approving doctor access is not the same authority as administering the
    // physical device estate, and this revision must not have merged them.
    expect($supervisor->can('view_doctor_devices'))->toBeFalse()
        ->and($supervisor->can('manage_doctor_devices'))->toBeFalse();

    actingAs($supervisor)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('settings.doctor-devices.index'))->assertForbidden();
});

// ---------------------------------------------------------------------------
// The screen itself
// ---------------------------------------------------------------------------

it('defaults the inbox to the requests that need a decision', function () {
    $f = approvalFixture();

    $decided = DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => Doctor::factory()->withAllowedBranches([$f['branch']])->create()->id,
        'doctor_device_id' => $f['device']->id,
    ]);

    actingAs(superAdmin())->get(route('doctor-device-authorizations.index'))
        ->assertOk()
        ->assertSee('dr. Ahmad Uji')
        ->assertDontSee($decided->doctor->name);
});

it('shows what a decision needs and nothing a decision does not', function () {
    $f = approvalFixture();

    $response = actingAs(superAdmin())
        ->get(route('doctor-device-authorizations.show', $f['authorization']))
        ->assertOk()
        ->assertSee('dr. Ahmad Uji')
        ->assertSee('Tablet Landak 01');

    // Never the key material, never a full fingerprint sprayed across a page.
    expect($response->getContent())
        ->not->toContain((string) $f['device']->public_key)
        ->not->toContain((string) $f['device']->public_key_fingerprint);
});

it('offers re-request only on a refused pair, and revoke only on an approved one', function () {
    $f = approvalFixture();
    $admin = superAdmin();

    actingAs($admin)->get(route('doctor-device-authorizations.show', $f['authorization']))
        ->assertOk()
        ->assertDontSee('Izinkan Ajukan Ulang')
        ->assertDontSee('Cabut Akses');

    actingAs($admin)->post(route('doctor-device-authorizations.reject', $f['authorization']), [
        'reason' => 'Perangkat pribadi.',
    ])->assertRedirect();

    actingAs($admin)->get(route('doctor-device-authorizations.show', $f['authorization']))
        ->assertOk()
        ->assertSee('Izinkan Ajukan Ulang');
});

it('requires a reason on the reject and revoke forms', function () {
    $f = approvalFixture();

    actingAs(superAdmin())->post(route('doctor-device-authorizations.reject', $f['authorization']), [])
        ->assertSessionHasErrors('reason');

    expect($f['authorization']->fresh()->status)->toBe(DoctorDeviceAuthorization::STATUS_PENDING);
});

// ---------------------------------------------------------------------------
// The sidebar badge
// ---------------------------------------------------------------------------

it('shows the approval group with a pending count to an approver and to nobody else', function () {
    approvalFixture();

    actingAs(superAdmin())->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Approval Device Dokter')
        ->assertSee(route('doctor-device-authorizations.index'));

    $kasir = approvalActor('Kasir');

    actingAs($kasir)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.cashier.index'))
        ->assertOk()
        ->assertDontSee('Approval Device Dokter');
});

it('counts only actionable pending requests in the badge', function () {
    $f = approvalFixture();

    // A decided request is not work. Putting it in the badge would show a
    // number no action can clear.
    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => Doctor::factory()->withAllowedBranches([$f['branch']])->create()->id,
        'doctor_device_id' => $f['device']->id,
    ]);
    DoctorDeviceAuthorization::factory()->rejected()->create([
        'doctor_id' => Doctor::factory()->withAllowedBranches([$f['branch']])->create()->id,
        'doctor_device_id' => $f['device']->id,
    ]);

    expect(app(DoctorDeviceAuthorizationService::class)->countPending())
        ->toBe(1);
});
