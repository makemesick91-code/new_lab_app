<?php

/**
 * DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 — is every doctor provisioned?
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM MultiDoctorPilotCohortTest
 *
 * That suite proves who enforcement APPLIES to. This one proves who could
 * survive it. They are different questions and the gap between them is the
 * lockout: a doctor inside the cohort with no trusted path keeps nothing —
 * their browser is denied and their device does not work.
 *
 * WHY IT IS SEPARATE FROM THE WebAuthn SUITES
 *
 * Those prove that ONE credential admits or is refused on ONE request. This
 * one never logs anybody in. It measures, across the whole fleet, whether the
 * five conditions of a trusted path hold — and it measures them per DOCTOR,
 * because a credential belongs to a device and counting usable credentials
 * tells you about hardware, not about people. Three usable credentials and
 * fifteen doctors is a perfectly consistent, completely unready fleet.
 *
 * THE SPECIFIC TRAP
 *
 * `backup_eligible` is nullable, and null means the authenticator declined to
 * say. Any predicate written as `!== true` admits that silence. The tests below
 * falsify each condition ONE at a time, because a readiness engine that passed
 * only when everything was wrong at once would still ship a fleet-wide lockout.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 *
 * Nothing about a real device. A row saying `cryptographically_verified` is a
 * row; the physical ceremony that earns it is not simulable and is not claimed.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorGlobalRolloutReadinessService;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // The target population is keyed on the Doctor ROLE, matching
    // DoctorAppLoginGate::appliesTo(). Without the real roles present, every
    // fixture below would be a user with no role and the engine would
    // correctly report an empty fleet — a green suite proving nothing.
    seedAccessControl();
});

/**
 * Helpers carry a file-unique `grr` prefix: Pest shares these across files and
 * a collision breaks whichever suite loads second.
 */
function grrReadiness(): array
{
    return app(DoctorGlobalRolloutReadinessService::class)->build();
}

function grrDoctor(string $name = 'drg Test', array $doctorAttributes = []): array
{
    $user = User::factory()->create(['name' => $name]);
    $user->assignRole('Doctor');

    $doctor = Doctor::factory()->create(array_merge([
        'user_id' => $user->id,
        'name' => $name,
        'is_active' => true,
    ], $doctorAttributes));

    return [$user, $doctor];
}

function grrDevice(array $attributes = [], ?Branch $branch = null): DoctorDevice
{
    return DoctorDevice::factory()->create(array_merge([
        'status' => DoctorDevice::STATUS_ACTIVE,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'branch_id' => $branch?->id ?? Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true])->id,
    ], $attributes));
}

/**
 * Credentials are inserted directly rather than minted through the real
 * registration route, and that shortcut is safe HERE and would not be on an
 * admission test.
 *
 * There is deliberately no credential factory: an admission test must prove a
 * real authenticator's output is accepted, so it drives the operator route with
 * FakeWebAuthnAuthenticator. This suite is doing the opposite job — it needs a
 * credential in each of several BROKEN states, several of which the real route
 * would rightly refuse to create. Inserting the row is how the engine gets to
 * be tested against data the login path would never produce but a drifting
 * migration or a loosened past policy could leave behind.
 */
function grrCredential(DoctorDevice $device, array $attributes = []): DoctorDeviceWebAuthnCredential
{
    return DoctorDeviceWebAuthnCredential::query()->create(array_merge([
        'uuid' => (string) Str::uuid(),
        'doctor_device_id' => $device->id,
        'credential_id' => 'cred-'.Str::random(20),
        'public_key' => 'pk-'.Str::random(24),
        'signature_counter' => 1,
        'user_verified' => true,
        'backup_eligible' => false,
        'backup_state' => false,
        'device_bound_verdict' => DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND,
        'attestation_format' => 'none',
        'registered_at' => now(),
    ], $attributes));
}

/** A doctor with every one of the five conditions satisfied. */
function grrReadyDoctor(string $name = 'drg Ready', ?Branch $branch = null): array
{
    [$user, $doctor] = grrDoctor($name);
    $device = grrDevice([], $branch);
    grrCredential($device);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    return [$user, $doctor, $device];
}

function grrRowFor(array $report, User $user): array
{
    $row = collect($report['doctors'])->firstWhere('user_id', (int) $user->id);

    expect($row)->not->toBeNull();

    return $row;
}

// ---------------------------------------------------------------------------
// The complete path
// ---------------------------------------------------------------------------

it('marks a doctor ready only when all five conditions hold at once', function () {
    [$user, $doctor, $device] = grrReadyDoctor();

    $report = grrReadiness();
    $row = grrRowFor($report, $user);

    expect($row['state'])->toBe(DoctorGlobalRolloutReadinessService::STATE_READY);
    expect($row['reasons'])->toBe([]);
    expect($row['path']['device_id'])->toBe((int) $device->id);
    expect($row['path']['doctor_id'])->toBe((int) $doctor->id);
    expect($report['ready_doctor_count'])->toBe(1);
    expect($report['not_ready_doctor_count'])->toBe(0);
});

it('always accounts for every target doctor exactly once', function () {
    grrReadyDoctor('drg Ready');
    grrDoctor('drg Bare');

    $report = grrReadiness();

    expect($report['target_doctor_count'])->toBe(2);
    expect($report['ready_doctor_count'] + $report['not_ready_doctor_count'])
        ->toBe($report['target_doctor_count']);
});

// ---------------------------------------------------------------------------
// Each condition, falsified on its own
// ---------------------------------------------------------------------------

it('refuses a doctor whose account is not linked to a doctor record', function () {
    $user = User::factory()->create(['name' => 'drg Unlinked']);
    $user->assignRole('Doctor');

    $row = grrRowFor(grrReadiness(), $user);

    expect($row['state'])->toBe(DoctorGlobalRolloutReadinessService::STATE_NOT_READY);
    expect($row['reasons'])->toContain(DoctorGlobalRolloutReadinessService::REASON_DOCTOR_NOT_LINKED);
});

it('refuses a doctor whose record is switched off, which the identity resolver does not filter', function () {
    // DoctorIdentityResolver::resolveForUser() does NOT filter is_active, so an
    // inactive doctor resolves perfectly well at login. An engine that leaned on
    // the resolver would report this clinician as ready to be enforced.
    [$user, $doctor] = grrDoctor('drg Inactive');
    $device = grrDevice();
    grrCredential($device);
    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    $doctor->forceFill(['is_active' => false])->save();

    $row = grrRowFor(grrReadiness(), $user);

    expect($row['state'])->toBe(DoctorGlobalRolloutReadinessService::STATE_NOT_READY);
    expect($row['reasons'])->toContain(DoctorGlobalRolloutReadinessService::REASON_DOCTOR_INACTIVE);
});

it('refuses a doctor with no device authorization at all', function () {
    [$user] = grrDoctor('drg NoAuth');

    $row = grrRowFor(grrReadiness(), $user);

    expect($row['state'])->toBe(DoctorGlobalRolloutReadinessService::STATE_NOT_READY);
    expect($row['reasons'])->toBe([DoctorGlobalRolloutReadinessService::REASON_NO_AUTHORIZATION]);
});

it('refuses a doctor whose only authorization is not active', function () {
    [$user, $doctor] = grrDoctor('drg Pending');
    $device = grrDevice();
    grrCredential($device);

    DoctorDeviceAuthorization::factory()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
        'status' => DoctorDeviceAuthorization::STATUS_PENDING,
    ]);

    $row = grrRowFor(grrReadiness(), $user);

    expect($row['state'])->toBe(DoctorGlobalRolloutReadinessService::STATE_NOT_READY);
    expect($row['reasons'])->toContain(DoctorGlobalRolloutReadinessService::REASON_AUTHORIZATION_NOT_ACTIVE);
});

it('refuses a doctor whose only device is revoked, and revocation stays terminal', function () {
    [$user, $doctor] = grrDoctor('drg Revoked');
    $device = grrDevice(['status' => DoctorDevice::STATUS_REVOKED, 'revoked_at' => now()]);
    grrCredential($device);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    $report = grrReadiness();
    $row = grrRowFor($report, $user);

    expect($row['state'])->toBe(DoctorGlobalRolloutReadinessService::STATE_NOT_READY);
    expect($row['reasons'])->toContain(DoctorGlobalRolloutReadinessService::REASON_DEVICE_NOT_ACTIVE);

    // Counted in the estate, never counted toward readiness.
    expect($report['devices']['revoked'])->toBe(1);
});

it('refuses a device whose identity was never cryptographically verified', function () {
    [$user, $doctor] = grrDoctor('drg Unverified');
    $device = grrDevice(['identity_state' => DoctorDevice::IDENTITY_UNVERIFIED]);
    grrCredential($device);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    $row = grrRowFor(grrReadiness(), $user);

    expect($row['reasons'])->toContain(DoctorGlobalRolloutReadinessService::REASON_DEVICE_IDENTITY_UNVERIFIED);
});

it('refuses a device that carries no credential at all', function () {
    [$user, $doctor] = grrDoctor('drg NoCredential');
    $device = grrDevice();

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    $row = grrRowFor(grrReadiness(), $user);

    expect($row['reasons'])->toContain(DoctorGlobalRolloutReadinessService::REASON_NO_CREDENTIAL);
});

it('refuses a revoked credential and does not let it stand in for a live one', function () {
    [$user, $doctor] = grrDoctor('drg RevokedCredential');
    $device = grrDevice();
    grrCredential($device, ['revoked_at' => now(), 'revoked_reason' => 'Diganti setelah tablet ditukar.']);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    $report = grrReadiness();
    $row = grrRowFor($report, $user);

    expect($row['reasons'])->toContain(DoctorGlobalRolloutReadinessService::REASON_CREDENTIAL_REVOKED);
    expect($report['devices']['usable_credentials'])->toBe(0);
    expect($report['devices']['revoked_credentials'])->toBe(1);
});

// ---------------------------------------------------------------------------
// The device-binding policy, which is where silence must not pass
// ---------------------------------------------------------------------------

it('refuses a credential the authenticator declined to describe', function () {
    // backup_eligible NULL. The column is nullable precisely so that "the
    // authenticator said nothing" is recorded honestly rather than invented as
    // false — so any predicate written `!== true` would admit this row.
    [$user, $doctor] = grrDoctor('drg Silent');
    $device = grrDevice();
    grrCredential($device, [
        'backup_eligible' => null,
        'backup_state' => null,
        'device_bound_verdict' => DoctorDeviceWebAuthnCredential::VERDICT_UNKNOWN,
    ]);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    $row = grrRowFor(grrReadiness(), $user);

    expect($row['state'])->toBe(DoctorGlobalRolloutReadinessService::STATE_NOT_READY);
    expect($row['reasons'])->toContain(DoctorGlobalRolloutReadinessService::REASON_CREDENTIAL_NOT_DEVICE_BOUND);
});

it('refuses a syncable passkey even though it is a perfectly valid credential', function () {
    [$user, $doctor] = grrDoctor('drg Syncable');
    $device = grrDevice();
    grrCredential($device, [
        'backup_eligible' => true,
        'backup_state' => true,
        'device_bound_verdict' => DoctorDeviceWebAuthnCredential::VERDICT_BACKUP_ELIGIBLE,
    ]);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    $row = grrRowFor(grrReadiness(), $user);

    expect($row['reasons'])->toContain(DoctorGlobalRolloutReadinessService::REASON_CREDENTIAL_NOT_DEVICE_BOUND);
});

it('refuses a credential registered without user verification', function () {
    [$user, $doctor] = grrDoctor('drg NoUv');
    $device = grrDevice();
    grrCredential($device, ['user_verified' => false]);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    $row = grrRowFor(grrReadiness(), $user);

    expect($row['reasons'])->toContain(DoctorGlobalRolloutReadinessService::REASON_CREDENTIAL_NOT_USER_VERIFIED);
});

it('refuses a credential whose verdict claims device-bound while its flags say nothing', function () {
    // The mirror of the drift below, and the case that proves the two checks are
    // genuinely independent rather than one of them carrying both.
    //
    // A mutation campaign found this gap: weakening the flag test alone left every
    // test green, because the verdict test happened to catch the same fixtures.
    // Here the verdict column says device_bound and the flag it should have been
    // derived from is NULL — so only the flag test can refuse it.
    [$user, $doctor] = grrDoctor('drg VerdictOverclaims');
    $device = grrDevice();
    grrCredential($device, [
        'backup_eligible' => null,
        'backup_state' => null,
        'device_bound_verdict' => DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND,
    ]);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    $row = grrRowFor(grrReadiness(), $user);

    expect($row['state'])->toBe(DoctorGlobalRolloutReadinessService::STATE_NOT_READY);
    expect($row['reasons'])->toContain(DoctorGlobalRolloutReadinessService::REASON_CREDENTIAL_NOT_DEVICE_BOUND);
});

it('refuses a stored verdict that has drifted from the flags it was derived from', function () {
    // The flags say device-bound; the verdict column says otherwise. The two
    // are checked separately on purpose — collapsing them would remove the only
    // place this drift is visible, and it is the one route by which a syncable
    // key could reach a clinical session.
    [$user, $doctor] = grrDoctor('drg Drifted');
    $device = grrDevice();
    grrCredential($device, [
        'backup_eligible' => false,
        'device_bound_verdict' => DoctorDeviceWebAuthnCredential::VERDICT_UNKNOWN,
    ]);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    $row = grrRowFor(grrReadiness(), $user);

    expect($row['state'])->toBe(DoctorGlobalRolloutReadinessService::STATE_NOT_READY);
    expect($row['reasons'])->toContain(DoctorGlobalRolloutReadinessService::REASON_CREDENTIAL_NOT_DEVICE_BOUND);
});

// ---------------------------------------------------------------------------
// One good path is enough
// ---------------------------------------------------------------------------

it('is satisfied by one working path even when another is broken', function () {
    [$user, $doctor] = grrDoctor('drg TwoDevices');

    $broken = grrDevice(['identity_state' => DoctorDevice::IDENTITY_UNVERIFIED]);
    grrCredential($broken);
    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $broken->id,
    ]);

    $working = grrDevice();
    grrCredential($working);
    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $working->id,
    ]);

    $row = grrRowFor(grrReadiness(), $user);

    expect($row['state'])->toBe(DoctorGlobalRolloutReadinessService::STATE_READY);
    expect($row['path']['device_id'])->toBe((int) $working->id);
});

it('lets two doctors share one tablet, but only through their own authorizations', function () {
    // A credential belongs to a device, so a shared tablet could look like
    // shared readiness. It is not: the authorization is per doctor, and the
    // doctor without one stays unready on exactly the same hardware.
    $device = grrDevice();
    grrCredential($device);

    [$authorized, $authorizedDoctor] = grrDoctor('drg Authorized');
    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $authorizedDoctor->id,
        'doctor_device_id' => $device->id,
    ]);

    [$bystander] = grrDoctor('drg Bystander');

    $report = grrReadiness();

    expect(grrRowFor($report, $authorized)['state'])
        ->toBe(DoctorGlobalRolloutReadinessService::STATE_READY);
    expect(grrRowFor($report, $bystander)['reasons'])
        ->toContain(DoctorGlobalRolloutReadinessService::REASON_NO_AUTHORIZATION);
});

// ---------------------------------------------------------------------------
// The verdict
// ---------------------------------------------------------------------------

it('reports NOT_READY when nobody is provisioned and PARTIAL once somebody is', function () {
    grrDoctor('drg One');
    grrDoctor('drg Two');

    expect(grrReadiness()['verdict'])->toBe(DoctorGlobalRolloutReadinessService::VERDICT_NOT_READY);

    grrReadyDoctor('drg Three');

    expect(grrReadiness()['verdict'])->toBe(DoctorGlobalRolloutReadinessService::VERDICT_PARTIAL);
});

it('reports GLOBAL_READY only when every single target doctor is ready', function () {
    grrReadyDoctor('drg A');
    grrReadyDoctor('drg B');

    expect(grrReadiness()['verdict'])->toBe(DoctorGlobalRolloutReadinessService::VERDICT_TRUSTED_PATHS_COMPLETE);

    // One more unprovisioned doctor is all it takes.
    grrDoctor('drg C');

    expect(grrReadiness()['verdict'])->toBe(DoctorGlobalRolloutReadinessService::VERDICT_PARTIAL);
});

it('excludes an account that is not a doctor from the target population', function () {
    grrReadyDoctor('drg Real');

    $admin = User::factory()->create(['name' => 'Admin Klinik']);
    $admin->assignRole('Admin Klinik');

    $report = grrReadiness();

    expect($report['target_doctor_count'])->toBe(1);
    expect(collect($report['doctors'])->firstWhere('user_id', (int) $admin->id))->toBeNull();
});
