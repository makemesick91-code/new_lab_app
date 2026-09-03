<?php

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 — Phase 2 authorization,
 * admin surface, and the non-negotiable no-enforcement guarantee.
 *
 * Device management is Super Admin only. The sidebar is never the boundary:
 * every assertion below hits the real route.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;

use function Pest\Laravel\actingAs;

function deviceAdminFixture(): array
{
    seedAccessControl();

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $device = DoctorDevice::factory()->create(['branch_id' => $branch->id]);

    return ['admin' => superAdmin(), 'branch' => $branch, 'device' => $device];
}

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('lets a super admin open the device dokter list', function () {
    $f = deviceAdminFixture();

    actingAs($f['admin'])
        ->get(route('settings.doctor-devices.index'))
        ->assertOk()
        ->assertSee($f['device']->device_name);
});

it('denies every non-super-admin role the device dokter list', function (string $role) {
    seedAccessControl();
    Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    $user = User::factory()->create();
    $user->assignRole($role);

    actingAs($user)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('settings.doctor-devices.index'))
        ->assertForbidden();
})->with(['Doctor', 'Kasir', 'Admin Klinik', 'Perawat', 'Owner', 'Admin Lab']);

it('denies a non-super-admin the device write routes', function () {
    $f = deviceAdminFixture();

    $user = User::factory()->create();
    $user->assignRole('Admin Klinik');

    $actor = actingAs($user)->withoutMiddleware(EnsureRmeOnlineContext::class);

    $actor->post(route('settings.doctor-devices.store'), [
        'device_name' => 'Tablet Selundupan',
        'branch_id' => $f['branch']->id,
    ])->assertForbidden();

    $actor->post(route('settings.doctor-devices.revoke', $f['device']), ['reason' => 'iseng'])
        ->assertForbidden();

    $actor->post(route('settings.doctor-devices.disable', $f['device']), ['reason' => 'iseng'])
        ->assertForbidden();
});

it('lets a read-only device permission see the list but never write', function () {
    $f = deviceAdminFixture();

    // Read and write authority are separate. The route middleware admits either
    // permission, so `view_doctor_devices` alone gets past it — the POLICY is
    // what must still refuse every write.
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view_doctor_devices');

    $actor = actingAs($viewer)->withoutMiddleware(EnsureRmeOnlineContext::class);

    $actor->get(route('settings.doctor-devices.index'))->assertOk();

    $actor->get(route('settings.doctor-devices.create'))->assertForbidden();
    $actor->post(route('settings.doctor-devices.store'), [
        'device_name' => 'Tablet Curian',
        'branch_id' => $f['branch']->id,
    ])->assertForbidden();
    $actor->post(route('settings.doctor-devices.disable', $f['device']), ['reason' => 'iseng'])->assertForbidden();
    $actor->post(route('settings.doctor-devices.reactivate', $f['device']))->assertForbidden();
    $actor->post(route('settings.doctor-devices.revoke', $f['device']), ['reason' => 'iseng'])->assertForbidden();

    expect($f['device']->fresh()->status)->toBe(DoctorDevice::STATUS_ACTIVE);
});

it('exposes the device dokter menu only to an authorized manager', function () {
    $f = deviceAdminFixture();

    actingAs($f['admin'])->get(route('dashboard'))->assertSee('Device Dokter');

    $kasir = User::factory()->create();
    $kasir->assignRole('Kasir');
    actingAs($kasir)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('profile.edit'))
        ->assertDontSee('Device Dokter');
});

// ---------------------------------------------------------------------------
// Admin surface
// ---------------------------------------------------------------------------

it('creates a device through the admin surface', function () {
    $f = deviceAdminFixture();

    actingAs($f['admin'])->post(route('settings.doctor-devices.store'), [
        'device_name' => 'Tablet Ruang Baru',
        'branch_id' => $f['branch']->id,
        'platform' => 'android',
        'device_model' => 'Galaxy Tab',
    ])->assertRedirect();

    expect(DoctorDevice::query()->where('device_name', 'Tablet Ruang Baru')->exists())->toBeTrue();
});

it('drives the full lifecycle through the admin surface', function () {
    $f = deviceAdminFixture();
    $actor = actingAs($f['admin']);

    $actor->post(route('settings.doctor-devices.disable', $f['device']), ['reason' => 'Servis layar pecah'])
        ->assertRedirect();
    expect($f['device']->fresh()->status)->toBe(DoctorDevice::STATUS_DISABLED);

    $actor->post(route('settings.doctor-devices.reactivate', $f['device']))->assertRedirect();
    expect($f['device']->fresh()->status)->toBe(DoctorDevice::STATUS_ACTIVE);

    $actor->post(route('settings.doctor-devices.revoke', $f['device']), ['reason' => 'Perangkat hilang total'])
        ->assertRedirect();
    expect($f['device']->fresh()->status)->toBe(DoctorDevice::STATUS_REVOKED);
});

it('rejects a revoke with no reason at the request boundary', function () {
    $f = deviceAdminFixture();

    actingAs($f['admin'])
        ->post(route('settings.doctor-devices.revoke', $f['device']), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($f['device']->fresh()->status)->toBe(DoctorDevice::STATUS_ACTIVE);
});

it('refuses to reactivate a revoked device through the admin surface', function () {
    $f = deviceAdminFixture();
    $revoked = DoctorDevice::factory()->revoked()->create(['branch_id' => $f['branch']->id]);

    actingAs($f['admin'])
        ->post(route('settings.doctor-devices.reactivate', $revoked))
        ->assertSessionHasErrors();

    expect($revoked->fresh()->status)->toBe(DoctorDevice::STATUS_REVOKED);
});

it('offers no destructive delete route for a device', function () {
    expect(app('router')->getRoutes()->hasNamedRoute('settings.doctor-devices.destroy'))->toBeFalse();
});

it('shows the device detail with a safe identity summary only', function () {
    $f = deviceAdminFixture();
    $f['device']->forceFill(['public_key_fingerprint' => str_repeat('b', 64)])->save();

    $response = actingAs($f['admin'])->get(route('settings.doctor-devices.show', $f['device']));

    $response->assertOk();
    $response->assertDontSee(str_repeat('b', 64));
});

it('filters the device list by status', function () {
    $f = deviceAdminFixture();
    $revoked = DoctorDevice::factory()->revoked()->create(['branch_id' => $f['branch']->id]);

    $response = actingAs($f['admin'])
        ->get(route('settings.doctor-devices.index', ['status' => DoctorDevice::STATUS_REVOKED]));

    $response->assertOk();
    $response->assertSee($revoked->device_name);
    $response->assertDontSee($f['device']->device_name);
});

// ---------------------------------------------------------------------------
// NON-NEGOTIABLE — enforcement stays OFF and Phase 1 stays intact
// ---------------------------------------------------------------------------

it('couples authentication to the device registry only through the sanctioned gate', function () {
    // PHASE 2 asserted a total absence, because in Phase 2 nothing in the auth
    // path had any business knowing the registry existed.
    // REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 ships the app-only
    // gate, which cannot exist unless the login path can ask it a question.
    //
    // Grepping the source is still the honest assertion — a passing login test
    // would not prove the absence of a coupling that has simply not fired yet —
    // but what it asserts is now the rule that was actually load-bearing: ONE
    // gate, by name. A proof service, a direct authorization query or a second
    // flag read in an auth surface is still a failure.
    $authSurfaces = array_merge(
        glob(base_path('app/Http/Controllers/Auth/*.php')) ?: [],
        glob(base_path('app/Http/Middleware/*.php')) ?: [],
        glob(base_path('app/Services/Auth/*.php')) ?: [],
        [base_path('bootstrap/app.php'), base_path('app/Http/Requests/Auth/LoginRequest.php')],
    );

    $allowed = [
        // The module namespace segment in a `use` statement is not a decision.
        'DoctorDevice',
        'DoctorAppLoginGate',
        'DoctorDeviceSessionService',
        'EnsureDoctorDeviceSession',
    ];

    foreach ($authSurfaces as $file) {
        if (! is_file($file)) {
            continue;
        }

        $contents = file_get_contents($file);
        $relative = str_replace(base_path().'/', '', $file);

        expect($contents)
            ->not->toContain('DoctorDeviceProofService', "{$relative} must not verify device proofs")
            ->not->toContain('DoctorDeviceAuthorization::', "{$relative} must not query authorizations directly")
            ->not->toContain("'doctor.trusted_device_enforcement'", "{$relative} must not read the flag itself");

        preg_match_all('/[A-Za-z_]*DoctorDevice[A-Za-z_]*/', $contents, $matches);

        foreach (array_unique($matches[0]) as $symbol) {
            expect($symbol)->toBeIn($allowed, "{$relative} references {$symbol}");
        }
    }
});

it('lets a doctor log in exactly as before, with an empty device registry', function () {
    seedAccessControl();
    expect(DoctorDevice::query()->count())->toBe(0);

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $doctor = Doctor::factory()->withAllowedBranches([$branch])->create();
    $user = rmeMakeDoctorOnline($doctor, $branch);
    $user->forceFill(['password' => bcrypt('password123')])->save();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user->fresh());
});

it('keeps the phase 1 room isolation and print denial intact', function () {
    seedAccessControl();

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $roomA = ClinicRoom::factory()->create(['branch_id' => $branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $roomB = ClinicRoom::factory()->create(['branch_id' => $branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);

    $doctorRecord = Doctor::factory()->withAllowedBranches([$branch])->create();
    $doctorUser = rmeMakeDoctorOnline($doctorRecord, $branch, $roomA);

    $mine = ClinicVisit::factory()->create([
        'branch_id' => $branch->id, 'clinic_room_id' => $roomA->id,
        'doctor_id' => $doctorRecord->id, 'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);
    $other = ClinicVisit::factory()->create([
        'branch_id' => $branch->id, 'clinic_room_id' => $roomB->id,
        'doctor_id' => $doctorRecord->id, 'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    // Phase 1 §16 — other room denied, own room allowed.
    actingAs($doctorUser)->get(route('rme.visits.show', $other))->assertForbidden();
    actingAs($doctorUser)->get(route('rme.visits.show', $mine))->assertOk();

    // Phase 1 §18/§19 — print stays denied.
    actingAs($doctorUser)->get(route('rme.visits.print', $mine))->assertForbidden();
    actingAs($doctorUser)->get(route('rme.visits.pdf', $mine))->assertForbidden();
});
