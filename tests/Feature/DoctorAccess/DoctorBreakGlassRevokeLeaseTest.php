<?php

/**
 * FIX-SUNU-GO-LIVE-BLOCKERS-1 / B6 — revoking emergency access must not leave
 * the doctor locked out of every other way in.
 *
 * Revocation always denied the NEXT request. What it did not do was release
 * the session lease the emergency window had created — and under
 * `doctor.single_active_session` an open lease is an INCUMBENT. So revoking a
 * grant produced a doctor who could neither continue the emergency session nor
 * start a new one by any path, until an operator released the lease by hand.
 *
 * This is not hypothetical. The 2026-09-18 production drill revoked its grant
 * after 72 seconds and left lease 13 open for roughly eighteen hours, until a
 * Super Admin force-released it the following morning.
 *
 * The scope of the release is the other half of the rule. Logging out a
 * session the grant never created would be a worse bug than the one being
 * fixed, so the matrix pins what must be left ALONE as hard as what must go.
 *
 * Leases are claimed through the real login route, never built by hand:
 * actingAs() fires Authenticated, not Login, so it never reaches the claim.
 */

use App\Models\User;
use App\Modules\DoctorAccess\Models\DoctorBreakGlassGrant;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use App\Modules\DoctorAccess\Services\DoctorBreakGlassService;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\LabOrder\Models\AuditLog;
use Illuminate\Support\Carbon;

require_once __DIR__.'/helpers.php';

function bgrService(): DoctorBreakGlassService
{
    return app(DoctorBreakGlassService::class);
}

/**
 * Arm enforcement so the test doctor is ACTUALLY covered by it.
 *
 * TRAP that cost a debugging round: daArmDoctorAccess() turns the flags on but
 * leaves the scope at `pilot`, and a doctor outside the pilot cohort exits the
 * gate before break-glass is ever consulted. The login then succeeds because
 * enforcement does not apply — not because the grant admitted it — so
 * first_used_at is never stamped and the whole fixture is vacuous.
 */
function bgrArmUnscopedEnforcement(): void
{
    // Leases need the DoctorAccess flags...
    daArmDoctorAccess();

    // ...and the gate needs its OWN enforcement flag, which daArmDoctorAccess
    // does not touch. Without it the gate short-circuits on
    // `enforcementEnabled()` and break-glass is never reached.
    $flags = config('feature_flags.flags', []);
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = true;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = true;
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

/**
 * Detach the test client from whoever is logged in, WITHOUT logging them out.
 *
 * `post('/logout')` or Auth::logout() would fire Logout and release the very
 * lease the next assertion depends on. Dropping the session rows and
 * forgetting the resolved guard leaves the lease exactly where it is, which is
 * what a second doctor on a second device actually looks like.
 */
function bgrDetachClient(User $user): void
{
    daKillSessionsFor($user);
    test()->flushSession();
    app('auth')->forgetGuards();
}

function bgrApprover(): User
{
    // The approver is created before any doctor fixture, so the permission
    // table has to exist by now — daDoctorAccount() seeds it too late for this.
    daSeedAccessControl();

    $user = User::factory()->create(['name' => 'Supervisor RME']);
    $user->givePermissionTo('grant_doctor_break_glass_access');

    return $user->fresh();
}

/**
 * A doctor admitted by break-glass and holding the lease that admission
 * created — the exact state the drill left behind.
 *
 * @return array{user: User, grant: DoctorBreakGlassGrant, lease: DoctorSessionLease}
 */
function bgrAdmittedDoctor(User $approver, string $name = 'drg Emergency'): array
{
    bgrArmUnscopedEnforcement();

    ['user' => $user] = daDoctorAccount([daBranch()], [], User::factory()->create(['name' => $name]));

    $grant = bgrService()->grant($user, $approver, 'Tablet rusak, pasien menunggu', 2);

    // No trusted device exists for this account, so the login is admitted by
    // the grant and by nothing else — which is also what stamps first_used_at.
    daLoginPost($user);

    $lease = daCurrentLease($user);
    expect($lease)->not->toBeNull('break-glass login did not claim a lease');

    return ['user' => $user, 'grant' => $grant->fresh(), 'lease' => $lease];
}

// ─── The defect ──────────────────────────────────────────────────────────────

it('releases the lease that the revoked emergency window created', function () {
    $approver = bgrApprover();
    ['user' => $user, 'grant' => $grant] = bgrAdmittedDoctor($approver);

    expect($grant->first_used_at)->not->toBeNull()
        ->and(daCurrentLease($user))->not->toBeNull();

    bgrService()->revoke($grant, $approver, 'Perangkat pengganti sudah tersedia');

    expect(daCurrentLease($user))->toBeNull('the emergency lease survived revocation');
});

it('leaves the doctor able to authenticate again after revocation', function () {
    $approver = bgrApprover();
    ['user' => $user, 'grant' => $grant] = bgrAdmittedDoctor($approver);

    bgrService()->revoke($grant, $approver, 'Perangkat pengganti sudah tersedia');

    // The whole point: no stale incumbent is left blocking the next claim.
    expect(DoctorSessionLease::query()
        ->where('user_id', $user->id)
        ->whereNull('released_at')
        ->count())->toBe(0);
});

it('records whether a lease was released on the revocation audit row', function () {
    $approver = bgrApprover();
    ['grant' => $grant] = bgrAdmittedDoctor($approver);

    bgrService()->revoke($grant, $approver, 'Perangkat pengganti sudah tersedia');

    $row = AuditLog::query()
        ->where('action', DoctorBreakGlassService::ACTION_REVOKED)
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->new_values['session_lease_released'] ?? null)->toBeTrue();
});

// ─── What must be left alone ─────────────────────────────────────────────────

it('does not release anything for a grant that never carried a session', function () {
    $approver = bgrApprover();
    bgrArmUnscopedEnforcement();
    ['user' => $user] = daDoctorAccount([daBranch()]);

    // Filed but never used: there is no emergency session of its making.
    $grant = bgrService()->grant($user, $approver, 'Disiapkan untuk jaga malam', 2);
    expect($grant->first_used_at)->toBeNull();

    bgrService()->revoke($grant->fresh(), $approver, 'Tidak jadi dipakai malam ini');

    $row = AuditLog::query()
        ->where('action', DoctorBreakGlassService::ACTION_REVOKED)
        ->latest('id')
        ->first();

    expect($row->new_values['session_lease_released'] ?? null)->toBeFalse();
});

it('leaves a session claimed before the window opened untouched', function () {
    $approver = bgrApprover();
    ['user' => $user, 'grant' => $grant, 'lease' => $lease] = bgrAdmittedDoctor($approver);

    // Construct the ordering the guard exists for: a login that predates the
    // grant. That is reachable in production whenever a doctor is already
    // working on a trusted device and an approver files a grant afterwards.
    $grant->forceFill(['granted_at' => $lease->claimed_at->copy()->addHour()])->save();

    bgrService()->revoke($grant->fresh(), $approver, 'Tidak diperlukan');

    expect(daCurrentLease($user))->not->toBeNull('a pre-existing legitimate session was logged out');
});

it('does not touch another doctor while revoking this one', function () {
    $approver = bgrApprover();

    // Two doctors admitted on two different devices. TRAP: one test client
    // holds one session, so logging the second in while the first is still
    // authenticated is a no-op redirect that claims no lease. Dropping the
    // first doctor's session ROWS detaches this client without firing Logout,
    // which would have released the very lease the assertion depends on.
    ['user' => $second, 'lease' => $secondLease] = bgrAdmittedDoctor($approver, 'drg Dua');
    bgrDetachClient($second);

    ['user' => $first, 'grant' => $firstGrant] = bgrAdmittedDoctor($approver, 'drg Satu');

    expect(daCurrentLease($second))->not->toBeNull('fixture failed: the second doctor holds no lease');

    bgrService()->revoke($firstGrant, $approver, 'Perangkat pengganti sudah tersedia');

    $survivor = daCurrentLease($second);

    expect(daCurrentLease($first))->toBeNull('the revoked doctor kept their lease')
        ->and($survivor)->not->toBeNull('an unrelated doctor was logged out')
        ->and((int) $survivor->id)->toBe((int) $secondLease->id);
});

// ─── Idempotency ─────────────────────────────────────────────────────────────

it('is idempotent when revoked twice', function () {
    $approver = bgrApprover();
    ['user' => $user, 'grant' => $grant] = bgrAdmittedDoctor($approver);

    $first = bgrService()->revoke($grant, $approver, 'Perangkat pengganti sudah tersedia');
    $revokedAt = $first->revoked_at;

    Carbon::setTestNow(Carbon::now()->addMinutes(5));
    $second = bgrService()->revoke($first->fresh(), $approver, 'Diulang tanpa sengaja');
    Carbon::setTestNow();

    // The first revocation is the one that happened, and the second must not
    // rewrite it or release anything further.
    expect($second->revoked_at->equalTo($revokedAt))->toBeTrue()
        ->and(daCurrentLease($user))->toBeNull();
});

it('still revokes cleanly when there is no lease left to release', function () {
    $approver = bgrApprover();
    ['user' => $user, 'grant' => $grant] = bgrAdmittedDoctor($approver);

    // The doctor logged out on their own before the approver got there.
    daCurrentLease($user)->forceFill([
        'released_at' => Carbon::now(),
        'released_reason' => DoctorSessionLease::RELEASE_LOGOUT,
    ])->save();

    $revoked = bgrService()->revoke($grant, $approver, 'Perangkat pengganti sudah tersedia');

    expect($revoked->isRevoked())->toBeTrue()
        ->and(daCurrentLease($user))->toBeNull();
});
