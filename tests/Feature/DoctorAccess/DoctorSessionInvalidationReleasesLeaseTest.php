<?php

/**
 * FIX-BREAK-GLASS-EXPIRY-LEASE-RELEASE / D3 — being logged out must not lock
 * the doctor out.
 *
 * FIX-SUNU-GO-LIVE-BLOCKERS-1 closed the REVOKE path: revoking a break-glass
 * grant now releases the lease it created. Adversarial review pointed out the
 * other door — a grant left to EXPIRE never reaches `revoke()` at all — and
 * tracing that turned up something broader:
 *
 *   `DoctorDeviceSessionService::invalidate()` wrote an audit row, called
 *   Auth::logout() and invalidated the session, but NEVER released the lease.
 *   There is no Logout listener in this application (only Login is wired), so
 *   nothing else released it either.
 *
 * `AuthenticatedSessionController` had always released the lease before
 * calling `invalidate()`. The per-request middleware path had not. So EVERY
 * mid-session denial stranded a lease — an expired or revoked break-glass
 * grant, a revoked device, a deactivated authorization — and under
 * `doctor.single_active_session` a stranded lease is an incumbent: the doctor
 * could not log back in by ANY path until somebody cleared it by hand.
 *
 * The fix moves the release INTO `invalidate()`, so the invariant is
 * structural rather than something each caller has to remember. These tests
 * pin the behaviour at the middleware, which is the caller that forgot.
 */

use App\Models\User;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use App\Modules\DoctorAccess\Services\DoctorBreakGlassService;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Carbon;

require_once __DIR__.'/helpers.php';

function bgeArmUnscopedEnforcement(): void
{
    daArmDoctorAccess();

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

function bgeApprover(): User
{
    daSeedAccessControl();

    $user = User::factory()->create(['name' => 'Supervisor RME']);
    $user->givePermissionTo('grant_doctor_break_glass_access');

    return $user->fresh();
}

/** A doctor working inside a live emergency window, holding its lease. */
function bgeAdmittedDoctor(User $approver, string $name = 'drg Darurat'): array
{
    bgeArmUnscopedEnforcement();

    ['user' => $user] = daDoctorAccount([daBranch()], [], User::factory()->create(['name' => $name]));

    $grant = app(DoctorBreakGlassService::class)->grant($user, $approver, 'Tablet rusak, pasien menunggu', 2);

    daLoginPost($user);

    expect(daCurrentLease($user))->not->toBeNull('fixture failed: no lease was claimed');

    return ['user' => $user, 'grant' => $grant->fresh()];
}

// ─── The gap the review found ────────────────────────────────────────────────

it('releases the lease when an expired emergency window logs the doctor out', function () {
    $approver = bgeApprover();
    ['user' => $user] = bgeAdmittedDoctor($approver);

    // Walk past the end of the 2-hour window. Nothing revokes it — it simply
    // lapses, which is the door revoke() never sees.
    Carbon::setTestNow(Carbon::now()->addHours(3));

    // Any ordinary request now trips the per-request gate.
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)->get('/dashboard');

    expect(daCurrentLease($user))->toBeNull('an expired window left its lease stranded');

    Carbon::setTestNow();
});

it('leaves the doctor able to authenticate again after the window lapses', function () {
    $approver = bgeApprover();
    ['user' => $user] = bgeAdmittedDoctor($approver);

    Carbon::setTestNow(Carbon::now()->addHours(3));
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)->get('/dashboard');
    Carbon::setTestNow();

    // The whole point of the fix: no stale incumbent blocks the next claim.
    expect(DoctorSessionLease::query()
        ->where('user_id', $user->id)
        ->whereNull('released_at')
        ->count())->toBe(0);
});

it('records the release with the device-invalidated reason', function () {
    $approver = bgeApprover();
    ['user' => $user] = bgeAdmittedDoctor($approver);

    Carbon::setTestNow(Carbon::now()->addHours(3));
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)->get('/dashboard');
    Carbon::setTestNow();

    $lease = DoctorSessionLease::query()
        ->where('user_id', $user->id)
        ->latest('id')
        ->first();

    expect($lease->released_reason)->toBe(DoctorSessionLease::RELEASE_DEVICE_INVALIDATED);
});

// ─── The same door, opened a different way ──────────────────────────────────

it('releases the lease when the grant is revoked and the doctor comes back', function () {
    $approver = bgeApprover();
    ['user' => $user, 'grant' => $grant] = bgeAdmittedDoctor($approver);

    // Revocation already releases the lease directly (FIX-SUNU-GO-LIVE-BLOCKERS-1
    // / B6). This asserts the middleware path is ALSO safe rather than
    // double-releasing or resurrecting anything.
    app(DoctorBreakGlassService::class)->revoke($grant, $approver, 'Perangkat pengganti sudah tersedia');

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)->get('/dashboard');

    expect(DoctorSessionLease::query()
        ->where('user_id', $user->id)
        ->whereNull('released_at')
        ->count())->toBe(0);
});

// ─── What must not happen ───────────────────────────────────────────────────

it('does not release a lease belonging to a different doctor', function () {
    $approver = bgeApprover();

    ['user' => $bystander] = bgeAdmittedDoctor($approver, 'drg Lain');
    $bystanderLease = daCurrentLease($bystander);
    daKillSessionsFor($bystander);
    test()->flushSession();
    app('auth')->forgetGuards();

    ['user' => $subject] = bgeAdmittedDoctor($approver, 'drg Subjek');

    Carbon::setTestNow(Carbon::now()->addHours(3));
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)->get('/dashboard');
    Carbon::setTestNow();

    $survivor = daCurrentLease($bystander);

    expect(daCurrentLease($subject))->toBeNull()
        ->and($survivor)->not->toBeNull('an unrelated doctor was logged out')
        ->and((int) $survivor->id)->toBe((int) $bystanderLease->id);
});

it('is harmless for a session that never held a lease', function () {
    daSeedAccessControl();
    bgeArmUnscopedEnforcement();

    // A non-doctor is not subject to the lease engine at all.
    $staff = userWith(['view_clinic_visits']);
    $this->actingAs($staff)->withoutMiddleware(EnsureRmeOnlineContext::class)->get('/dashboard');

    expect(DoctorSessionLease::query()->count())->toBe(0);
});
