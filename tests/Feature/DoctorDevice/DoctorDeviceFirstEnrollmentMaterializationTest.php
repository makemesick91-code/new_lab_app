<?php

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1 — the first real device could not
 * be approved at all.
 *
 * WHAT WENT WRONG ON THE PILOT TABLET
 *
 * A genuine Android install completed enrolment: the request reached the
 * server, passed key validation, and produced a PENDING
 * `DoctorDeviceEnrollment` carrying a real EC P-256 public key. The approval
 * screen then offered nothing to approve it into. `approveEnrollment()`
 * requires a `doctor_device_id`, the dropdown is built from the existing
 * device registry, and on a clean deployment that registry is empty. Zero
 * options, and no way forward.
 *
 * So the first device on any new deployment is unapprovable. Not the first
 * device of a doctor — the first device, full stop.
 *
 * THE MISSING HALF WAS ALREADY DESCRIBED
 *
 * `DoctorDeviceEnrollmentService::approve()` documents its device argument as
 * "an existing ACTIVE device awaiting hardware, OR ONE CREATED HERE FOR THIS
 * PAIRING". The second branch was never implemented and no caller creates one.
 * This closes exactly that gap and nothing wider.
 *
 * WHAT MUST NOT MOVE
 *
 * The crypto identity still comes only from the verified enrolment. The
 * operator supplies the two administrative facts a key cannot carry — a
 * human-readable name and the owning branch — and supplies nothing else. A
 * device is still created UNVERIFIED and still has to prove possession of the
 * private key before it is trusted. `DoctorDevice` and
 * `DoctorDeviceAuthorization` stay separate models, and none of this arms
 * enforcement for anybody.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceEnrollment;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorDeviceEnrollmentService;
use App\Modules\DoctorDevice\Support\DeviceKeyMaterial;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Database\Factories\DoctorDeviceEnrollmentFactory;
use Illuminate\Validation\ValidationException;

/** A pending enrolment created the way a real tablet creates one. */
function firstEnrollment(?array $keyPair = null): array
{
    [$pub, $priv] = $keyPair ?? DoctorDeviceEnrollmentFactory::generateKeyPair();

    $result = app(DoctorDeviceEnrollmentService::class)->request([
        'public_key' => $pub,
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
        'platform' => 'android',
        'device_model' => 'SM-X236B',
        'os_version' => '16',
        'app_version' => '0.3.0-phase3',
    ]);

    return ['enrollment' => $result['enrollment'], 'public_key' => $pub, 'private_key' => $priv];
}

function approverUser(): User
{
    seedAccessControl();

    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

function pilotBranch(): Branch
{
    return Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
}

// ---------------------------------------------------------------------------
// A. The defect itself
// ---------------------------------------------------------------------------

it('materialises the first device from a verified enrolment, taking count from zero to one', function () {
    $actor = approverUser();
    $branch = pilotBranch();
    $f = firstEnrollment();

    expect(DoctorDevice::query()->count())->toBe(0);

    $approved = app(DoctorDeviceEnrollmentService::class)->approveIntoNewDevice(
        $f['enrollment'],
        ['device_name' => 'PHASE4A_PILOT_TABLET_01', 'branch_id' => $branch->id],
        $actor,
    );

    expect(DoctorDevice::query()->count())->toBe(1);

    $device = DoctorDevice::query()->firstOrFail();

    // C — the enrolment points at that exact device, not at some other row.
    expect($approved->doctor_device_id)->toBe($device->id);
    expect($approved->status)->toBe(DoctorDeviceEnrollment::STATUS_APPROVED);
});

it('takes the crypto identity from the enrolment and the labels from the operator', function () {
    $actor = approverUser();
    $branch = pilotBranch();
    $f = firstEnrollment();

    app(DoctorDeviceEnrollmentService::class)->approveIntoNewDevice(
        $f['enrollment'],
        ['device_name' => 'PHASE4A_PILOT_TABLET_01', 'branch_id' => $branch->id],
        $actor,
    );

    $device = DoctorDevice::query()->firstOrFail();

    // Trust comes from the verified request and only from there.
    expect($device->public_key)->toBe($f['enrollment']->public_key);
    expect($device->public_key_fingerprint)->toBe(DeviceKeyMaterial::fingerprint($f['public_key']));

    // The device-reported facts are copied from the request, not typed by an
    // operator who could mistype them into looking like different hardware.
    expect($device->platform)->toBe('android');
    expect($device->device_model)->toBe('SM-X236B');

    // Only these two came from the operator, and neither is a trust input.
    expect($device->device_name)->toBe('PHASE4A_PILOT_TABLET_01');
    expect($device->branch_id)->toBe($branch->id);
});

it('leaves the new device unverified until it proves possession of the private key', function () {
    $actor = approverUser();
    $branch = pilotBranch();
    $f = firstEnrollment();

    app(DoctorDeviceEnrollmentService::class)->approveIntoNewDevice(
        $f['enrollment'],
        ['device_name' => 'Tablet', 'branch_id' => $branch->id],
        $actor,
    );

    $device = DoctorDevice::query()->firstOrFail();

    // Approval binds a key. It does not certify that the holder has it.
    expect($device->identity_state)->toBe(DoctorDevice::IDENTITY_UNVERIFIED);
    expect($device->enrollment_status)->toBe(DoctorDevice::ENROLLMENT_PENDING);
});

// ---------------------------------------------------------------------------
// D / E. What the approval screen may offer
// ---------------------------------------------------------------------------

it('puts the materialised device on the approval surface and nothing else', function () {
    $actor = approverUser();
    $branch = pilotBranch();
    $f = firstEnrollment();

    app(DoctorDeviceEnrollmentService::class)->approveIntoNewDevice(
        $f['enrollment'],
        ['device_name' => 'PHASE4A_PILOT_TABLET_01', 'branch_id' => $branch->id],
        $actor,
    );

    $response = test()->actingAs($actor)->get(route('settings.doctor-devices.index'));

    $response->assertOk();
    $response->assertSee('PHASE4A_PILOT_TABLET_01');

    // E — exactly one device exists, so no unrelated row can be offered.
    expect(DoctorDevice::query()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// B / H. The same physical device, again
// ---------------------------------------------------------------------------

it('refuses to enrol the same key twice and never creates a second device for it', function () {
    $actor = approverUser();
    $branch = pilotBranch();
    $pair = DoctorDeviceEnrollmentFactory::generateKeyPair();
    $f = firstEnrollment($pair);

    app(DoctorDeviceEnrollmentService::class)->approveIntoNewDevice(
        $f['enrollment'],
        ['device_name' => 'Tablet', 'branch_id' => $branch->id],
        $actor,
    );

    // The same Keystore identity asking again. Already bound to a live device,
    // so this is a device-impersonation path and is refused outright.
    expect(fn () => firstEnrollment($pair))->toThrow(ValidationException::class);

    expect(DoctorDevice::query()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// I. No orphan on either side
// ---------------------------------------------------------------------------

it('creates no device when the enrolment is expired', function () {
    $actor = approverUser();
    $branch = pilotBranch();
    $f = firstEnrollment();

    $f['enrollment']->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(fn () => app(DoctorDeviceEnrollmentService::class)->approveIntoNewDevice(
        $f['enrollment'],
        ['device_name' => 'Tablet', 'branch_id' => $branch->id],
        $actor,
    ))->toThrow(ValidationException::class);

    // The stale request must not leave a registry row behind it.
    expect(DoctorDevice::query()->count())->toBe(0);
});

it('creates no device when the enrolment is no longer pending', function () {
    $actor = approverUser();
    $branch = pilotBranch();
    $f = firstEnrollment();

    app(DoctorDeviceEnrollmentService::class)->reject($f['enrollment'], 'tidak dikenal', $actor);

    expect(fn () => app(DoctorDeviceEnrollmentService::class)->approveIntoNewDevice(
        $f['enrollment']->refresh(),
        ['device_name' => 'Tablet', 'branch_id' => $branch->id],
        $actor,
    ))->toThrow(ValidationException::class);

    expect(DoctorDevice::query()->count())->toBe(0);
});

it('refuses to build a second registry row for a key another device already holds', function () {
    $actor = approverUser();
    $branch = pilotBranch();
    $f = firstEnrollment();

    // The window this guard exists for: the enrolment was accepted while the
    // registry was empty, and by the time an operator approves it a device
    // holding that same key exists — a second approval of the same request, or
    // a race between two administrators. `approve()` cannot catch this: it only
    // asks whether the TARGET device is already bound, and the target here is a
    // fresh row with no key at all.
    DoctorDevice::factory()->create([
        'branch_id' => $branch->id,
        'status' => DoctorDevice::STATUS_ACTIVE,
        'public_key' => $f['enrollment']->public_key,
        'public_key_fingerprint' => $f['enrollment']->public_key_fingerprint,
    ]);

    expect(fn () => app(DoctorDeviceEnrollmentService::class)->approveIntoNewDevice(
        $f['enrollment'],
        ['device_name' => 'Duplicate', 'branch_id' => $branch->id],
        $actor,
    ))->toThrow(ValidationException::class);

    // One key, one registry row. The pre-existing device is untouched.
    expect(DoctorDevice::query()->count())->toBe(1);
    expect(DoctorDevice::query()->where('device_name', 'Duplicate')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// G. Forged input cannot become a trusted device
// ---------------------------------------------------------------------------

it('never accepts a key that OpenSSL cannot read, so no device can be built on one', function () {
    seedAccessControl();

    expect(fn () => app(DoctorDeviceEnrollmentService::class)->request([
        'public_key' => 'not-a-key',
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
        'platform' => 'android',
    ]))->toThrow(ValidationException::class);

    expect(DoctorDevice::query()->count())->toBe(0);
    expect(DoctorDeviceEnrollment::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// K. Authorization to approve is still a separate predicate
// ---------------------------------------------------------------------------

it('refuses the approval route to an account without device-management authority', function () {
    seedAccessControl();

    $branch = pilotBranch();
    $f = firstEnrollment();

    $doctor = User::factory()->create();
    $doctor->assignRole('Doctor');

    // A Doctor-role account with no selected online context is redirected by
    // EnsureRmeOnlineContext before any policy runs, which would make this pass
    // for the wrong reason — a 302 is not a refusal to approve. Bypassing that
    // one middleware puts the request against the authorisation boundary this
    // test is actually about.
    test()->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('settings.doctor-device-enrollments.approve', $f['enrollment']), [
            'device_name' => 'Tablet',
            'branch_id' => $branch->id,
        ])
        ->assertForbidden();

    expect(DoctorDevice::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// L. None of this arms anything
// ---------------------------------------------------------------------------

it('arms no enforcement for anybody', function () {
    $actor = approverUser();
    $branch = pilotBranch();
    $f = firstEnrollment();

    app(DoctorDeviceEnrollmentService::class)->approveIntoNewDevice(
        $f['enrollment'],
        ['device_name' => 'Tablet', 'branch_id' => $branch->id],
        $actor,
    );

    // Registering hardware is not a rollout decision.
    expect(app(DoctorAppLoginGate::class)->enforcementEnabled())->toBeFalse();
});

// ---------------------------------------------------------------------------
// The route, end to end, the way the operator will actually use it
// ---------------------------------------------------------------------------

it('approves through the HTTP route with new-device details and keeps the existing-device path working', function () {
    $actor = approverUser();
    $branch = pilotBranch();

    // New-device path: nothing in the registry yet.
    $first = firstEnrollment();

    test()->actingAs($actor)
        ->post(route('settings.doctor-device-enrollments.approve', $first['enrollment']), [
            'device_name' => 'PHASE4A_PILOT_TABLET_01',
            'branch_id' => $branch->id,
        ])
        ->assertRedirect();

    expect(DoctorDevice::query()->count())->toBe(1);

    // Existing-device path: a pre-registered ACTIVE row still works unchanged.
    $spare = DoctorDevice::factory()->create([
        'branch_id' => $branch->id,
        'status' => DoctorDevice::STATUS_ACTIVE,
        'public_key' => null,
        'public_key_fingerprint' => null,
    ]);

    $second = firstEnrollment();

    test()->actingAs($actor)
        ->post(route('settings.doctor-device-enrollments.approve', $second['enrollment']), [
            'doctor_device_id' => $spare->id,
        ])
        ->assertRedirect();

    expect(DoctorDevice::query()->count())->toBe(2);
    expect($spare->refresh()->public_key_fingerprint)->not->toBeNull();
});

it('rejects an approval that names neither an existing device nor a new one', function () {
    $actor = approverUser();
    $f = firstEnrollment();

    test()->actingAs($actor)
        ->post(route('settings.doctor-device-enrollments.approve', $f['enrollment']), [])
        ->assertSessionHasErrors();

    expect(DoctorDevice::query()->count())->toBe(0);
});
