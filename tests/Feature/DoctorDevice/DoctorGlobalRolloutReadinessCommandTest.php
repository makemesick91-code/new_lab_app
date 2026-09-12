<?php

/**
 * DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 — the command's exit contract.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM DoctorGlobalRolloutReadinessTest
 *
 * That suite proves the engine computes the right answer. This one proves the
 * command reports it honestly and exits on it correctly, which is a different
 * failure mode: an engine can be perfect while the command that wraps it
 * returns 0 on everything and quietly becomes decoration in a deploy chain.
 *
 * THE SPECIFIC TRAP
 *
 * PARTIAL exits 0 ON PURPOSE. A staged rollout is PARTIAL for its entire
 * duration, and a gate that reddens for weeks is a gate somebody removes from
 * the chain. So the exit codes are asymmetric and the asymmetry is asserted
 * here rather than assumed: NOT_READY always fails, PARTIAL fails only when
 * --strict was asked for, and GLOBAL_READY never fails.
 *
 * The second trap is Pest's own: expectsOutputToContain consumes ONE writeln
 * per expectation, so a multi-token assertion against a single line silently
 * checks only the first. Everything here goes through Artisan::call plus
 * Artisan::output.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 *
 * That any doctor can actually log in. This command reports provisioning, and
 * provisioning is not a ceremony.
 */

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorGlobalRolloutReadinessService;
use App\Support\Android\AndroidDoctorEnforcementScope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedAccessControl();
});

/**
 * File-unique `grrCmd` prefix, and the fixtures are duplicated rather than
 * borrowed from the sibling suite on purpose: Pest registers these as global
 * functions only for the files it actually loads, so a suite that reached
 * across to another file's helper would pass in a full run and fail the moment
 * anyone ran it alone.
 */
function grrCmdRun(array $options = []): array
{
    $exit = Artisan::call('doctor:rollout-readiness', $options);

    return ['exit' => $exit, 'output' => Artisan::output()];
}

function grrCmdDoctor(string $name): array
{
    $user = User::factory()->create(['name' => $name]);
    $user->assignRole('Doctor');

    $doctor = Doctor::factory()->create([
        'user_id' => $user->id,
        'name' => $name,
        'is_active' => true,
    ]);

    return [$user, $doctor];
}

function grrCmdReadyDoctor(string $name): array
{
    [$user, $doctor] = grrCmdDoctor($name);

    $device = DoctorDevice::factory()->create([
        'status' => DoctorDevice::STATUS_ACTIVE,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
    ]);

    DoctorDeviceWebAuthnCredential::query()->create([
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
    ]);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    return [$user, $doctor, $device];
}

function grrCmdScope(array $pilot): void
{
    config()->set('doctor_device_enforcement.scope', array_replace_recursive(
        (array) config('doctor_device_enforcement.scope'),
        ['mode' => AndroidDoctorEnforcementScope::MODE_PILOT, 'pilot' => $pilot],
    ));
}

function grrCmdArm(bool $armed): void
{
    // The flag keys contain dots, so a dotted config lookup traverses into
    // nothing. Setting the registry array is the only form that arms anything.
    $flags = config('feature_flags.flags', []);
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $armed;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $armed;
    config()->set('feature_flags.flags', $flags);
}

// ---------------------------------------------------------------------------
// Exit codes
// ---------------------------------------------------------------------------

it('fails when nobody is provisioned, with or without strict', function () {
    grrCmdDoctor('drg Nobody');

    expect(grrCmdRun()['exit'])->toBe(1);
    expect(grrCmdRun(['--strict' => true])['exit'])->toBe(1);
});

it('passes on a rollout in progress and fails the same state only under strict', function () {
    grrCmdReadyDoctor('drg Provisioned');
    grrCmdDoctor('drg Waiting');

    $plain = grrCmdRun();

    expect($plain['exit'])->toBe(0);
    expect($plain['output'])->toContain('READINESS_VERDICT='.DoctorGlobalRolloutReadinessService::VERDICT_PARTIAL);

    // Same fleet, same moment, different question: "is it safe to widen yet?"
    expect(grrCmdRun(['--strict' => true])['exit'])->toBe(1);
});

it('passes under strict only when every target doctor is ready', function () {
    grrCmdReadyDoctor('drg A');
    grrCmdReadyDoctor('drg B');

    $result = grrCmdRun(['--strict' => true]);

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toContain('READINESS_VERDICT='.DoctorGlobalRolloutReadinessService::VERDICT_TRUSTED_PATHS_COMPLETE);
});

// ---------------------------------------------------------------------------
// What it prints
// ---------------------------------------------------------------------------

it('emits every counted line an operator reads before widening a cohort', function () {
    grrCmdReadyDoctor('drg Provisioned');
    grrCmdDoctor('drg Waiting');

    $output = grrCmdRun()['output'];

    foreach ([
        'TARGET_DOCTOR_COUNT=2',
        'READY_DOCTOR_COUNT=1',
        'NOT_READY_DOCTOR_COUNT=1',
        'PILOT_COHORT_SIZE=',
        'PILOT_COHORT_MAXIMUM=',
        'PILOT_COHORT_ALL_READY=',
        'ACTIVE_DEVICE_COUNT=',
        'USABLE_CREDENTIAL_COUNT=',
        'ENFORCEMENT_FLAG_ARMED=',
        'ENFORCEMENT_SCOPE_MODE=',
        'ENFORCEMENT_POSTURE=',
        'GLOBAL_SCOPE_PERMITTED=false',
        'GLOBAL_ENFORCEMENT_ACTIVE=false',
        'READINESS_VERDICT=',
    ] as $token) {
        expect($output)->toContain($token);
    }
});

it('names what is blocking the doctors who are not ready', function () {
    grrCmdReadyDoctor('drg Provisioned');
    grrCmdDoctor('drg Waiting');

    expect(grrCmdRun()['output'])
        ->toContain(DoctorGlobalRolloutReadinessService::REASON_NO_AUTHORIZATION.'=1');
});

it('produces JSON whose counts match the text run exactly', function () {
    grrCmdReadyDoctor('drg Provisioned');
    grrCmdDoctor('drg Waiting');

    $decoded = json_decode(grrCmdRun(['--json' => true])['output'], true);

    expect($decoded)->toBeArray();
    expect($decoded['target_doctor_count'])->toBe(2);
    expect($decoded['ready_doctor_count'])->toBe(1);
    expect($decoded['verdict'])->toBe(DoctorGlobalRolloutReadinessService::VERDICT_PARTIAL);

    expect(grrCmdRun()['output'])->toContain('TARGET_DOCTOR_COUNT=2');
});

it('prints no credential material, no key and no identity number', function () {
    grrCmdReadyDoctor('drg Provisioned');

    $raw = strtolower(grrCmdRun(['--json' => true])['output']);

    // The engine loads credentials to judge them; nothing about them may reach
    // an operator's terminal or a captured evidence artifact.
    foreach (['public_key', 'credential_id"', 'ktp', 'nik', 'password', 'secret'] as $forbidden) {
        expect($raw)->not->toContain($forbidden);
    }
});

// ---------------------------------------------------------------------------
// Membership is not readiness
// ---------------------------------------------------------------------------

it('reports the enforced cohort from the scope, not from who happens to be ready', function () {
    [$ready] = grrCmdReadyDoctor('drg Ready');
    [$covered, $coveredDoctor] = grrCmdDoctor('drg CoveredButBare');

    // Cover the UNPROVISIONED doctor and leave the provisioned one out. That
    // pairing is exactly what a lockout looks like, and a report that conflated
    // the two lists would show it as fine.
    grrCmdScope(['doctor_user_ids' => (string) $covered->id]);
    grrCmdArm(true);

    $decoded = json_decode(grrCmdRun(['--json' => true])['output'], true);

    expect($decoded['cohort']['user_ids'])->toBe([(int) $covered->id]);
    expect($decoded['cohort']['all_ready'])->toBeFalse();
    expect($decoded['cohort']['covered_but_not_ready'])->toBe([(int) $covered->id]);

    // Ready, and deliberately not covered. That is a doctor waiting their turn,
    // not a problem.
    expect($decoded['ready_doctor_user_ids'])->toBe([(int) $ready->id]);

    expect($coveredDoctor->id)->not->toBeNull();
});

it('reports a cohort whose members are all provisioned as ready to widen', function () {
    [$user] = grrCmdReadyDoctor('drg Ready');

    grrCmdScope(['doctor_user_ids' => (string) $user->id]);
    grrCmdArm(true);

    $decoded = json_decode(grrCmdRun(['--json' => true])['output'], true);

    expect($decoded['cohort']['all_ready'])->toBeTrue();
    expect($decoded['cohort']['covered_but_not_ready'])->toBe([]);
});

it('never reports global enforcement as live while the scope is a bounded pilot', function () {
    [$user] = grrCmdReadyDoctor('drg Ready');

    grrCmdScope(['doctor_user_ids' => (string) $user->id]);
    grrCmdArm(true);

    $decoded = json_decode(grrCmdRun(['--json' => true])['output'], true);

    expect($decoded['runtime']['global_enforcement_active'])->toBeFalse();
    expect($decoded['runtime']['enforcement_scope_mode'])->toBe(AndroidDoctorEnforcementScope::MODE_PILOT);
});

it('counts a doctor authorized to a second doctor\'s device only through their own authorization', function () {
    [$owner, $ownerDoctor, $device] = grrCmdReadyDoctor('drg Owner');
    [$guest, $guestDoctor] = grrCmdDoctor('drg Guest');

    expect(json_decode(grrCmdRun(['--json' => true])['output'], true)['ready_doctor_count'])->toBe(1);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $guestDoctor->id,
        'doctor_device_id' => $device->id,
    ]);

    $decoded = json_decode(grrCmdRun(['--json' => true])['output'], true);

    expect($decoded['ready_doctor_count'])->toBe(2);
    expect($decoded['ready_doctor_user_ids'])->toContain((int) $owner->id, (int) $guest->id);
    expect($ownerDoctor->id)->not->toBeNull();
});
