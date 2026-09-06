<?php

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1 — the boundary, end to end.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM THE TWO THAT ALREADY COVER THIS
 *
 * The pilot-scoping mechanism arrived with the preparation sprint and is
 * already covered twice over, but neither cover reaches the property the
 * activation runbook actually stakes the pilot on:
 *
 *   - `DoctorDeviceEnforcementGateTest` exercises the real HTTP login path,
 *     but its `enforceDeviceLock()` helper declares the FLEET-WIDE scope for
 *     every assertion in the file. Nothing there is pilot-scoped.
 *
 *   - `Phase4aPilotPreparationTest` owns the pilot scope, but tests it as a
 *     predicate — `inEnforcementScope($otherDoctor)` is false. A predicate
 *     returning false is not the same claim as a second doctor completing a
 *     login.
 *
 * So the state the runbook calls F8 — enforcement ARMED, scope PILOT, and a
 * different doctor still able to reach their patients — had no end-to-end
 * proof. That is the check whose failure means a fleet-wide clinical lockout,
 * and the runbook's answer to it failing is "disarm immediately per G1". A
 * property with that blast radius should be pinned in CI before it is armed in
 * production, not discovered on a tablet in a clinic.
 *
 * THE DIRECTION EVERY TEST HERE PUSHES
 *
 * `AndroidDoctorEnforcementScope` only ever narrows: an unusable or
 * unrecognised configuration covers NOBODY. That is unusual enough to be worth
 * asserting through the front door, because the failure mode it prevents is not
 * an attacker gaining access — it is every doctor in every branch losing access
 * to their patients at once.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Support\DeviceKeyMaterial;
use App\Support\Android\AndroidDoctorEnforcementScope;
use Database\Factories\DoctorDeviceEnrollmentFactory;

/**
 * Arm the enforcement flag and declare a scope in one call.
 *
 * The flag key contains a dot, so the whole `feature_flags.flags` array is
 * rewritten rather than reached with dot notation — a
 * `config()->set('feature_flags.flags.doctor.trusted_device_enforcement...')`
 * builds a nested structure FeatureFlagService never reads, and the test then
 * passes while enforcement is quietly off. Same trap tests/Pest.php documents
 * for the legacy archive flag.
 *
 * `$scope` is merged over the committed scope rather than replacing it, so a
 * key this sprint does not care about keeps its shipped value.
 */
function phase4aActivationArm(bool $armed, array $scope = [], bool $globalPermitted = false): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $armed;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $armed;
    config()->set('feature_flags.flags', $flags);

    config()->set('doctor_device_enforcement.scope', array_replace_recursive(
        (array) config('doctor_device_enforcement.scope'),
        $scope,
    ));

    config()->set('android_release.enforcement.scope', array_merge(
        (array) config('android_release.enforcement.scope'),
        ['global_permitted' => $globalPermitted],
    ));
}

/** A doctor who can actually complete a browser login: user, record, branch, room. */
function phase4aDoctor(): array
{
    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $room = ClinicRoom::factory()->create([
        'branch_id' => $branch->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);

    $doctor = Doctor::factory()->withAllowedBranches([$branch])->create(['is_active' => true]);
    $user = rmeMakeDoctorOnline($doctor, $branch, $room);
    $user->forceFill(['password' => bcrypt('password123')])->save();
    $doctor->forceFill(['user_id' => $user->id])->save();

    return compact('branch', 'room', 'doctor', 'user');
}

/** An approved device for that doctor — so denial cannot be blamed on an unapproved pair. */
function phase4aApprovedDevice(array $d): DoctorDevice
{
    [$pub] = DoctorDeviceEnrollmentFactory::generateKeyPair();

    $device = DoctorDevice::factory()->create([
        'branch_id' => $d['branch']->id,
        'public_key' => $pub,
        'public_key_fingerprint' => DeviceKeyMaterial::fingerprint($pub),
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED,
    ]);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $d['doctor']->id,
        'doctor_device_id' => $device->id,
    ]);

    return $device;
}

/** Attempt an ordinary browser login and report whether it authenticated. */
function phase4aBrowserLoginSucceeds(User $user): bool
{
    test()->post(route('login'), ['email' => $user->email, 'password' => 'password123']);

    $ok = auth()->check() && auth()->id() === $user->id;

    if ($ok) {
        test()->post(route('logout'));
    }

    return $ok;
}

// ---------------------------------------------------------------------------
// F7 / F8 — the two halves that have to be true at the same time
// ---------------------------------------------------------------------------

it('denies the pilot doctor a browser login while leaving another doctor untouched', function () {
    seedAccessControl();

    $pilot = phase4aDoctor();
    $other = phase4aDoctor();

    // An approved device, so the denial below is about the browser carrying no
    // device-bound session — not about an unapproved pair.
    phase4aApprovedDevice($pilot);

    phase4aActivationArm(true, [
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => $pilot['user']->id],
    ]);

    // F7: the pilot doctor cannot reach production through a browser.
    expect(phase4aBrowserLoginSucceeds($pilot['user']))->toBeFalse();

    // F8: and every other doctor is exactly as they were. If this flips, the
    // pilot has become a fleet-wide lockout and the runbook says disarm.
    expect(phase4aBrowserLoginSucceeds($other['user']))->toBeTrue();
});

// ---------------------------------------------------------------------------
// The direction an unusable scope has to fail in
// ---------------------------------------------------------------------------

it('enforces nobody when the flag is armed but the pilot scope names no doctor', function () {
    seedAccessControl();

    $a = phase4aDoctor();
    $b = phase4aDoctor();

    // The state the runbook calls out at E4: armed, and covering nobody. It
    // "looks like protection and is not" — but the direction it fails in is
    // what matters here. Nobody is enforced, so nobody is locked out.
    phase4aActivationArm(true, [
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => null],
    ]);

    expect(app(AndroidDoctorEnforcementScope::class)->isUsable())->toBeFalse();
    expect(phase4aBrowserLoginSucceeds($a['user']))->toBeTrue();
    expect(phase4aBrowserLoginSucceeds($b['user']))->toBeTrue();
});

it('enforces nobody when the scope mode is a typo rather than a known mode', function () {
    seedAccessControl();

    $a = phase4aDoctor();

    phase4aActivationArm(true, [
        'mode' => 'piolt',
        'pilot' => ['doctor_user_id' => $a['user']->id],
    ]);

    // A mistyped mode must not be normalised into the nearest known one, and
    // must not widen to the fleet. It covers nobody.
    expect(app(AndroidDoctorEnforcementScope::class)->isUsable())->toBeFalse();
    expect(phase4aBrowserLoginSucceeds($a['user']))->toBeTrue();
});

it('refuses to widen to the fleet from a host value alone', function () {
    seedAccessControl();

    $a = phase4aDoctor();

    // `unscoped` is the fleet-wide mode, and the mode IS settable on a host.
    // Fleet-wide permission is not: it lives in the governance record, which
    // does not read the environment. So this configuration — everything a host
    // can reach, asking for fleet-wide denial — still enforces nobody.
    phase4aActivationArm(true, ['mode' => AndroidDoctorEnforcementScope::MODE_UNSCOPED], globalPermitted: false);

    expect(app(AndroidDoctorEnforcementScope::class)->isUsable())->toBeFalse();
    expect(phase4aBrowserLoginSucceeds($a['user']))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Pilot scope is not a substitute for the flag
// ---------------------------------------------------------------------------

it('leaves the pilot doctor working normally while the flag is still off', function () {
    seedAccessControl();

    $pilot = phase4aDoctor();

    // Exactly the scope activation will set, with the flag not yet armed. This
    // is the state production is in between step E4.1 and E4.4, and a doctor
    // must be able to work throughout it.
    phase4aActivationArm(false, [
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => $pilot['user']->id],
    ]);

    expect(app(AndroidDoctorEnforcementScope::class)->coversUser($pilot['user']->id))->toBeTrue();
    expect(phase4aBrowserLoginSucceeds($pilot['user']))->toBeTrue();
});
