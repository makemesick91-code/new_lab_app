<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Models\DoctorBranchLock;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use App\Modules\DoctorAccess\Services\DoctorEffectiveBranchResolver;
use App\Modules\DoctorAccess\Services\DoctorSessionLeaseService;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Support\Android\AndroidDoctorEnforcementScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses()->group('DoctorAccess', 'DoctorDevice', 'Security');

/**
 * DOCTOR-ACCESS-GLOBAL-ACTIVATION-BLOCKER-CLOSURE-1 (B1) — rollback, proven
 * rather than described.
 *
 * WHAT WAS ACTUALLY MISSING. `rollback_to_browser_login_proven` is one of five
 * strings in `android_release.enforcement.global_prerequisites`, and until this
 * sprint that list was read by no application code at all — a human checklist
 * with a string-membership test attached. Production carries zero audit rows
 * for a rollback because no producer exists, and the disarm paths were
 * described in two runbooks and asserted nowhere.
 *
 * A rollback that has never been performed under test is not a proven rollback.
 * These tests perform each one.
 *
 * WHAT THIS SUITE DOES NOT DO. It does not touch production, does not flip a
 * host flag, and does not run `config:cache` anywhere. The config-cache
 * roundtrip below reuses the in-process harness that already exists for exactly
 * this purpose rather than building a second one, and every enforcement state
 * here lives in an in-memory override inside a test.
 */
function rbDoctor(string $name = 'drg Rollback'): array
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

function rbArmEnforcement(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $on;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $on;
    config()->set('feature_flags.flags', $flags);
}

function rbArmFlag(string $flag, bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[$flag]['default'] = $on;
    $flags[$flag]['env_value'] = $on;
    config()->set('feature_flags.flags', $flags);
}

function rbScope(array $overrides): void
{
    config()->set('doctor_device_enforcement.scope', array_merge(
        (array) config('doctor_device_enforcement.scope'),
        $overrides,
    ));
}

/** Row counts for every foundation a rollback must never destroy. */
function rbFoundationCounts(): array
{
    return [
        'devices' => DB::table('mst_doctor_devices')->count(),
        'authorizations' => DB::table('mst_doctor_device_authorizations')->count(),
        'credentials' => DB::table('trx_doctor_device_webauthn_credentials')->count(),
        'leases' => DB::table('trx_doctor_session_leases')->count(),
        'locks' => DB::table('mst_doctor_branch_locks')->count(),
        'covers' => DB::table('trx_doctor_branch_covers')->count(),
        'audit' => DB::table('sys_audit_logs')->count(),
    ];
}

// ---------------------------------------------------------------------------
// A. HALF B — browser/device enforcement rolls back on the FLAG
// ---------------------------------------------------------------------------

it('restores browser login to an enforced doctor when the enforcement flag is disarmed', function () {
    seedAccessControl();

    [$pilot] = rbDoctor('drg Enforced');
    $before = rbFoundationCounts();

    rbArmEnforcement(true);
    rbScope([
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => $pilot->id],
    ]);

    $gate = app(DoctorAppLoginGate::class);
    $request = Request::create('/');

    // Armed: this doctor holds no device binding, so the browser is refused.
    expect($gate->denyBrowserSessionReason($pilot, $request))
        ->toBe(DoctorAppLoginGate::DENY_NO_DEVICE_SESSION);

    // The rollback under test is the FLAG, not the cohort. An incident is
    // resolved by disarming enforcement, and the sibling suite already covers
    // emptying the cohort.
    rbArmEnforcement(false);

    expect($gate->denyBrowserSessionReason($pilot, $request))->toBeNull();
    expect($gate->denySessionReason($pilot, $request))->toBeNull();

    // And nothing was destroyed on the way back.
    expect(rbFoundationCounts())->toBe($before);
});

it('leaves every device, authorization and credential intact across an arm and disarm cycle', function () {
    seedAccessControl();

    [, $doctor] = rbDoctor('drg Estate');

    $device = DoctorDevice::factory()->create([
        'status' => DoctorDevice::STATUS_ACTIVE,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
    ]);

    $authorization = DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    $credential = DoctorDeviceWebAuthnCredential::query()->create([
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

    rbArmEnforcement(true);
    rbArmEnforcement(false);

    // A rollback that revoked a credential or a device would make re-arming a
    // re-provisioning exercise. It must be a flag flip and nothing more.
    expect($device->fresh()->status)->toBe(DoctorDevice::STATUS_ACTIVE);
    expect($device->fresh()->revoked_at)->toBeNull();
    expect($authorization->fresh()->isActive())->toBeTrue();
    expect($authorization->fresh()->revoked_at)->toBeNull();
    expect($credential->fresh()->revoked_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// B. HALF A — the session lease ladder
// ---------------------------------------------------------------------------

it('claims no lease and denies no login while the single-session flag is off', function () {
    seedAccessControl();

    config()->set('session.driver', 'database');
    rbArmFlag(DoctorSessionLeaseService::FLAG, false);

    [$user] = rbDoctor('drg Disarmed');

    $leases = app(DoctorSessionLeaseService::class);

    expect($leases->enabled())->toBeFalse();
    expect($leases->subjectTo($user))->toBeTrue();
    expect(DoctorSessionLease::query()->count())->toBe(0);
});

it('preserves an existing lease row when the flag is rolled back', function () {
    seedAccessControl();

    config()->set('session.driver', 'database');

    [$user, $doctor] = rbDoctor('drg Leased');

    // A lease written while the engine was armed. Rollback must leave the row
    // exactly as it stands: re-arming needs no cleanup, and a stale lease whose
    // session row is gone is reclaimed by the next claim rather than blocking.
    $lease = new DoctorSessionLease;
    $lease->forceFill([
        'user_id' => $user->id,
        'doctor_id' => $doctor->id,
        'session_id' => 'sess-'.Str::random(20),
        'session_token_hash' => hash('sha256', Str::random(32)),
        'claimed_at' => now(),
        'last_seen_at' => now(),
    ])->save();

    rbArmFlag(DoctorSessionLeaseService::FLAG, false);

    $fresh = $lease->fresh();

    expect($fresh)->not->toBeNull();
    expect($fresh->released_at)->toBeNull();
    expect($fresh->released_reason)->toBeNull();
    expect(DoctorSessionLease::query()->count())->toBe(1);
});

it('drops branch lock back to ineffective the moment single session is disarmed', function () {
    seedAccessControl();

    config()->set('session.driver', 'database');
    rbArmFlag(DoctorEffectiveBranchResolver::FLAG_BRANCH_LOCK, true);
    rbArmFlag(DoctorEffectiveBranchResolver::FLAG_SINGLE_ACTIVE_SESSION, true);

    $resolver = app(DoctorEffectiveBranchResolver::class);

    expect($resolver->enabled())->toBeTrue();

    // Branch lock depends on the lease flag and cannot outlive it. Rolling back
    // ONE flag therefore rolls back both halves of the narrowing — there is no
    // state where a doctor's branch stays narrowed with nothing left to
    // invalidate the session.
    rbArmFlag(DoctorEffectiveBranchResolver::FLAG_SINGLE_ACTIVE_SESSION, false);

    expect($resolver->enabled())->toBeFalse();
    expect($resolver->branchIdFor(User::factory()->create()))->toBeNull();
});

it('keeps every home lock and cover row across a branch lock rollback', function () {
    seedAccessControl();

    config()->set('session.driver', 'database');

    [, $doctor] = rbDoctor('drg Locked');
    $branch = Branch::factory()->create([
        'code' => 'RB'.Str::random(3),
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $lock = new DoctorBranchLock;
    $lock->forceFill([
        'doctor_id' => $doctor->id,
        'home_branch_id' => $branch->id,
        'established_via' => DoctorBranchLock::VIA_INITIAL_ASSIGNMENT,
        'established_at' => now(),
        'transfer_count' => 0,
    ])->save();

    rbArmFlag(DoctorEffectiveBranchResolver::FLAG_BRANCH_LOCK, true);
    rbArmFlag(DoctorEffectiveBranchResolver::FLAG_SINGLE_ACTIVE_SESSION, true);
    rbArmFlag(DoctorEffectiveBranchResolver::FLAG_SINGLE_ACTIVE_SESSION, false);

    $fresh = $lock->fresh();

    // The provisioning a readiness programme spent a sprint establishing must
    // survive a rollback untouched. There is deliberately no path back to UNSET.
    expect($fresh)->not->toBeNull();
    expect((int) $fresh->home_branch_id)->toBe((int) $branch->id);
    expect((int) $fresh->transfer_count)->toBe(0);
});

// ---------------------------------------------------------------------------
// C. CONFIG CACHE — the realization step both directions depend on
// ---------------------------------------------------------------------------

it('resolves each doctor flag through a cached config the way production does', function (string $envKey, string $flagKey) {
    // Production runs cached configuration, and Laravel skips the environment
    // file entirely when it is cached. So an activation OR a rollback that edits
    // the environment and stops there changes nothing at all. This roundtrips
    // the real config file through var_export exactly as `config:cache` does.
    expect(ffCachedService([$envKey => 'true'])->enabled($flagKey))->toBeTrue();
    expect(ffCachedService([$envKey => 'false'])->enabled($flagKey))->toBeFalse();

    // Back to where it started: a rollback has to be able to restore the
    // original answer, not merely produce a different one.
    expect(ffCachedService([$envKey => 'true'])->enabled($flagKey))->toBeTrue();
})->with([
    ['FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION', 'doctor.single_active_session'],
    ['FEATURE_DOCTOR_BRANCH_LOCK', 'doctor.branch_lock'],
    ['FEATURE_DOCTOR_TRUSTED_DEVICE_ENFORCEMENT', 'doctor.trusted_device_enforcement'],
]);

it('falls back to the committed default when the override is absent or unreadable', function (?string $value) {
    // An unset or unparseable override must not resolve to "on". Every one of
    // these flags ships committed OFF, and a rollback that left the value in a
    // state the parser cannot read must land on the safe answer.
    expect(ffCachedService(['FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION' => $value])
        ->enabled('doctor.single_active_session'))->toBeFalse();
})->with([null, '', 'banana', 'YES_PLEASE']);

// ---------------------------------------------------------------------------
// D. THE RECOVERY SURFACE, STATED HONESTLY
// ---------------------------------------------------------------------------

it('keeps the operator force-logout command reachable independently of the lease flag', function () {
    seedAccessControl();

    // The HTTP surface 404s while single_active_session is off — the controller
    // gates every action on the capability being armed — so an incident plan
    // that assumes a browser can clear a stuck lease is wrong. The console
    // command is NOT flag-gated, which is what makes SSH recovery real rather
    // than aspirational.
    rbArmFlag(DoctorSessionLeaseService::FLAG, false);

    expect(collect(Artisan::all()))
        ->toHaveKey('doctor:session-force-logout');
});
