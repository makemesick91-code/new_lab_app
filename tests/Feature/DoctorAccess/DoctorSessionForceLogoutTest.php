<?php

declare(strict_types=1);

/**
 * DOCTOR-ACCESS-PR-A-SINGLE-SESSION-FOUNDATION — FORCE LOGOUT, end to end.
 *
 * NEW IN THIS PULL REQUEST, AND NEW TO THE SPRINT. The combined sprint reached
 * `DoctorSessionReleaseService` only through an HTTP controller that belongs to
 * PR-B, so the service shipped with no test of its own and the console surface
 * did not exist. Shipping a service nothing calls would have been the worse half
 * of that; shipping a command nothing tests would have been the other. This file
 * is the second half.
 *
 * ── WHY THE ESCAPE HATCH EXISTS ───────────────────────────────────────────
 *
 * One active session per doctor means a lease can get STUCK: a tablet locked in
 * a drawer, or a doctor who went home without logging out while their session
 * row is still live. REFUSED-NOT-EVICTED is the rule, so the only way out is a
 * deliberate, authorized, audited human action — never an idle timer, which
 * would be eviction by the back door, and never a manual UPDATE, which leaves no
 * trail and frees no clinic room.
 *
 * ── THE FIVE PROPERTIES THIS FILE DEFENDS ─────────────────────────────────
 *
 *   1. IT RELEASES THE LEASE, and the victim's next protected request is the
 *      moment they notice — not the moment the command ran.
 *   2. IT REQUIRES A WRITTEN REASON, bounded by config, and the refusal quotes
 *      the same bounds the server enforces.
 *   3. IT AUDITS, twice: the lease lifecycle row and the operator's own words.
 *   4. IT REFUSES AN UNKNOWN, INACTIVE, UNAUTHORIZED OR ABSENT ACTOR, and it
 *      refuses a self-release even for an actor who holds the grant.
 *   5. IT REVOKES NO DEVICE, NO AUTHORIZATION AND NO CREDENTIAL. Asserted
 *      against real rows rather than argued from the call graph, because a
 *      guarantee nobody measured is a guarantee that breaks silently.
 *
 * ── HOW THE COMMAND IS DRIVEN ─────────────────────────────────────────────
 *
 * Through `Artisan::call()` and `Artisan::output()`, never
 * `expectsOutputToContain()`. That expectation consumes exactly ONE writeln per
 * call, so a command emitting one multi-line JSON payload satisfies only the
 * first and the rest silently pass — the trap the DoctorAccess governance
 * fixtures in the combined sprint already recorded, and the reason `--json` is
 * used here for every assertion about content.
 */

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use App\Modules\DoctorAccess\Services\DoctorSessionLeaseService;
use App\Modules\DoctorAccess\Services\DoctorSessionReleaseService;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\LabOrder\Models\AuditLog;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| Local fixtures. The `dfl` prefix is this file's initials: Pest loads every
| file in tests/Feature into ONE process, so a sibling suite declaring a
| same-named global function would be a fatal redeclaration rather than a test
| failure.
|--------------------------------------------------------------------------
*/

/**
 * Run the command and decode its single JSON object.
 *
 * `--json` on every call, for the reason in the file header: the table
 * renderer's output cannot be asserted a line at a time without the
 * one-writeln-per-expectation trap, and a refusal must be as readable to a test
 * as a success.
 *
 * @param  array<string, mixed>  $options
 * @return array{exit: int, payload: array<string, mixed>}
 */
function dflRun(array $options): array
{
    $exit = Artisan::call('doctor:session-force-logout', array_merge(
        ['--json' => true],
        $options,
    ));

    return [
        'exit' => $exit,
        'payload' => (array) json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR),
    ];
}

/** How many audit rows carry this action? */
function dflAuditCount(string $action): int
{
    return AuditLog::query()->where('action', $action)->count();
}

/**
 * Put a genuinely different browser in front of us — the office PC the doctor
 * walked to.
 *
 * TRAP, AND THE REASON THIS IS NOT OPTIONAL. The test client forwards no cookie,
 * but SessionManager caches ONE Store instance and Store::loadSession() overlays
 * the handler's data onto the attributes it already holds, so the in-memory
 * session SURVIVES between requests in one test. Without a flush, a second
 * daLoginPost() is not a second browser — it is the doctor's OWN browser, still
 * carrying the released lease token, and the lease middleware evicts it on that
 * very request before the login controller ever runs. The login then fails for
 * a reason that has nothing to do with the release.
 *
 * The cached guard and the `auth.driver` singleton go too: SessionGuard caches
 * `$this->user` and carries `loggedOut` after a teardown, and DatabaseSessionHandler
 * resolves the guard through the container to stamp `sessions.user_id`.
 */
function dflFreshBrowser(): void
{
    session()->flush();

    Auth::forgetGuards();
    app()->forgetInstance('auth.driver');
    app()->forgetInstance(Guard::class);
}

/**
 * A doctor holding a genuinely claimed lease, through the real login route.
 *
 * TRAP: a lease row built by hand would not exercise the claim at all, and a
 * lease claimed through actingAs() does not exist — setUser() fires
 * Authenticated, not Login. Only daLoginPost() reaches the claim listener.
 *
 * @return array{user: User, doctor: Doctor, lease: DoctorSessionLease}
 */
function dflDoctorHoldingLease(): array
{
    daArmDoctorAccess();

    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([daBranch()]);

    daLoginPost($user);

    $lease = daCurrentLease($user);

    expect($lease)->not->toBeNull();

    return ['user' => $user, 'doctor' => $doctor, 'lease' => $lease];
}

/**
 * A trusted tablet, an active authorization and a live WebAuthn credential for
 * this doctor.
 *
 * These three rows exist ONLY so the "revokes nothing" assertions are not
 * vacuous. Counting zero unchanged rows would pass for every implementation,
 * including one that revoked everything it could find.
 *
 * @return array{device: DoctorDevice, authorization: DoctorDeviceAuthorization, credential: DoctorDeviceWebAuthnCredential}
 */
function dflDeviceEstate(Doctor $doctor): array
{
    $device = DoctorDevice::factory()->create([
        'branch_id' => daBranch()->id,
        'device_name' => 'Tablet Bangsal',
        'status' => DoctorDevice::STATUS_ACTIVE,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED,
        'public_key' => base64_encode('dfl-fixture-public-key'),
        'key_algorithm' => 'EC',
    ]);

    $authorization = DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    // Written directly: there is no credential factory, and enrolling through
    // the real registration route would drag an operator login and a WebAuthn
    // ceremony into a test about a console command. What matters here is that a
    // live, unrevoked row exists to be left alone.
    $credential = new DoctorDeviceWebAuthnCredential;

    $credential->forceFill([
        'uuid' => (string) Str::uuid(),
        'doctor_device_id' => $device->id,
        'credential_id' => 'dfl-credential-'.$device->id,
        'public_key' => base64_encode('dfl-credential-public-key'),
        'signature_counter' => 0,
        'user_verified' => true,
        'backup_eligible' => false,
        'backup_state' => false,
        'device_bound_verdict' => DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND,
        'registered_at' => now(),
    ])->save();

    return [
        'device' => $device,
        'authorization' => $authorization,
        'credential' => $credential->refresh(),
    ];
}

/*
|--------------------------------------------------------------------------
| 1. It releases the lease
|--------------------------------------------------------------------------
*/

it('releases a stuck lease and frees the doctor to log in again', function () {
    ['user' => $user, 'doctor' => $doctor, 'lease' => $lease] = dflDoctorHoldingLease();

    $operator = daSupervisorRme();

    $result = dflRun([
        '--doctor' => (string) $doctor->id,
        '--actor' => (string) $operator->id,
        '--reason' => 'Tablet bangsal terkunci di lemari, dokter menunggu di poli.',
        '--apply' => true,
    ]);

    expect($result['exit'])->toBe(SymfonyCommand::SUCCESS)
        ->and($result['payload']['applied'])->toBeTrue()
        ->and($result['payload']['released'])->toBeTrue()
        ->and((int) $result['payload']['doctor_id'])->toBe((int) $doctor->id)
        ->and((int) $result['payload']['actor_user_id'])->toBe((int) $operator->id);

    // THE LEASE IS RELEASED, with the operator named on the row.
    $lease->refresh();

    expect($lease->released_at)->not->toBeNull()
        ->and($lease->released_reason)->toBe(DoctorSessionLease::RELEASE_ADMIN)
        ->and((int) $lease->released_by_user_id)->toBe((int) $operator->id);

    daAssertActiveLeaseCount(0, $user);

    // RETAINED, not deleted: the released row is the trail.
    expect(DoctorSessionLease::query()->where('user_id', $user->id)->count())->toBe(1);

    // And the doctor may now take a lease from ANOTHER BROWSER — the office PC
    // they walked to — which is the whole point of the escape hatch. It has to
    // be a fresh browser: the doctor's original one still carries the released
    // token and is evicted on its next request, which is the subject of the very
    // next test rather than of this one.
    dflFreshBrowser();
    daLoginPost($user);
    $this->assertAuthenticatedAs($user);
    daAssertActiveLeaseCount(1, $user);
});

it('does not log the doctor out in place: the session ends on its next request', function () {
    /*
     * THE HONEST BOUNDARY, asserted rather than left in a docblock. There is no
     * cross-session logout primitive in this codebase and the command does not
     * invent one: the release is DATA. The victim's browser keeps working until
     * it asks for something, and for an idle tablet that can be minutes.
     */
    ['doctor' => $doctor] = dflDoctorHoldingLease();

    // The doctor's own browser is the one in front of us, mid-session.
    $this->get('/profile')->assertOk();

    $result = dflRun([
        '--doctor' => (string) $doctor->id,
        '--actor' => (string) daSupervisorRme()->id,
        '--reason' => 'Dokter pulang tanpa keluar, sesi menghalangi dokter berikutnya.',
        '--apply' => true,
    ]);

    expect($result['exit'])->toBe(SymfonyCommand::SUCCESS);

    // The next protected request is where it lands — not the moment the command
    // ran, because nothing reached into the doctor's session.
    $response = $this->get('/profile');

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors('email');
    $this->assertGuest();

    expect(session(DoctorSessionLeaseService::SESSION_LEASE_TOKEN))->toBeNull();
    expect(dflAuditCount(DoctorSessionLeaseService::ACTION_EVICTED))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 2. Dry run by default
|--------------------------------------------------------------------------
*/

it('previews without --apply and writes nothing at all', function () {
    ['user' => $user, 'doctor' => $doctor, 'lease' => $lease] = dflDoctorHoldingLease();

    $before = AuditLog::query()->count();

    $result = dflRun([
        '--doctor' => (string) $doctor->id,
        '--actor' => (string) daSupervisorRme()->id,
        '--reason' => 'Memeriksa dulu apakah dokter ini benar memegang sesi.',
    ]);

    expect($result['exit'])->toBe(SymfonyCommand::SUCCESS)
        ->and($result['payload']['applied'])->toBeFalse()
        ->and($result['payload']['released'])->toBeFalse()
        // The preview reports the REAL lease, so an operator can tell a stuck
        // session from a doctor who is simply not logged in.
        ->and($result['payload']['holds_active_lease'])->toBeTrue()
        ->and((int) $result['payload']['lease_id'])->toBe((int) $lease->id)
        ->and((int) $result['payload']['user_id'])->toBe((int) $user->id)
        ->and($result['payload']['claimed_at'])->not->toBeNull();

    // NOTHING WAS WRITTEN. Not the lease, not an audit row.
    expect($lease->fresh()->released_at)->toBeNull();
    daAssertActiveLeaseCount(1, $user);
    expect(AuditLog::query()->count())->toBe($before);
    expect(dflAuditCount(DoctorSessionReleaseService::ACTION_RELEASED_BY_APPROVER))->toBe(0);
    expect(dflAuditCount(DoctorSessionLeaseService::ACTION_REVOKED))->toBe(0);
});

it('previews a doctor who holds no lease as holding none', function () {
    // No login at all: the ordinary diagnostic case, an operator checking a
    // doctor who is simply not signed in anywhere.
    daArmDoctorAccess();
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([daBranch()]);

    $result = dflRun([
        '--doctor' => (string) $doctor->id,
        '--actor' => (string) daSupervisorRme()->id,
    ]);

    expect($result['exit'])->toBe(SymfonyCommand::SUCCESS)
        ->and($result['payload']['holds_active_lease'])->toBeFalse()
        ->and($result['payload']['lease_id'])->toBeNull()
        ->and($result['payload']['claimed_at'])->toBeNull()
        // A REASON IS NOT REQUIRED TO LOOK. An operator diagnosing a stuck lease
        // should not have to decide what to write before they may read.
        ->and($result['payload']['reason'])->toBeNull();

    daAssertActiveLeaseCount(0, $user);
});

/*
|--------------------------------------------------------------------------
| 3. A written reason
|--------------------------------------------------------------------------
*/

it('refuses to apply without a reason and quotes the bound it enforces', function () {
    ['doctor' => $doctor, 'lease' => $lease] = dflDoctorHoldingLease();

    $min = (int) config('doctor_access.reason.min_length');
    $max = (int) config('doctor_access.reason.max_length');

    $result = dflRun([
        '--doctor' => (string) $doctor->id,
        '--actor' => (string) daSupervisorRme()->id,
        '--apply' => true,
    ]);

    expect($result['exit'])->toBe(SymfonyCommand::FAILURE)
        ->and($result['payload']['refused'])->toBeTrue()
        // The operator is told the SAME bound the server applies, read from
        // config rather than restated here, so moving one moves both.
        ->and($result['payload']['message'])->toContain((string) $min)
        ->and($result['payload']['message'])->toContain((string) $max);

    expect($lease->fresh()->released_at)->toBeNull();
});

it('refuses a reason too short to explain anything, and accepts one at the bound', function () {
    ['doctor' => $doctor, 'lease' => $lease] = dflDoctorHoldingLease();

    $min = (int) config('doctor_access.reason.min_length');
    $operator = daSupervisorRme();

    // 'x' and 'asdf' pass a non-empty check and explain nothing. One character
    // short of the floor is the boundary that matters.
    $tooShort = dflRun([
        '--doctor' => (string) $doctor->id,
        '--actor' => (string) $operator->id,
        '--reason' => str_repeat('a', $min - 1),
        '--apply' => true,
    ]);

    expect($tooShort['exit'])->toBe(SymfonyCommand::FAILURE)
        ->and($tooShort['payload']['refused'])->toBeTrue();

    expect($lease->fresh()->released_at)->toBeNull();

    // Over the ceiling is refused too, so an audit payload cannot be used as a
    // free-text store.
    $tooLong = dflRun([
        '--doctor' => (string) $doctor->id,
        '--actor' => (string) $operator->id,
        '--reason' => str_repeat('a', (int) config('doctor_access.reason.max_length') + 1),
        '--apply' => true,
    ]);

    expect($tooLong['exit'])->toBe(SymfonyCommand::FAILURE);
    expect($lease->fresh()->released_at)->toBeNull();

    // EXACTLY at the floor is accepted — a bound that refused its own value
    // would be off by one and nobody would notice until an operator was stuck.
    $atBound = dflRun([
        '--doctor' => (string) $doctor->id,
        '--actor' => (string) $operator->id,
        '--reason' => str_repeat('a', $min),
        '--apply' => true,
    ]);

    expect($atBound['exit'])->toBe(SymfonyCommand::SUCCESS)
        ->and($atBound['payload']['released'])->toBeTrue();

    expect($lease->fresh()->released_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 4. It audits — twice, for two different facts
|--------------------------------------------------------------------------
*/

it('writes both audit rows: the lease lifecycle and the operator own words', function () {
    ['user' => $user, 'doctor' => $doctor] = dflDoctorHoldingLease();

    $operator = daSupervisorRme();
    $reason = 'Tablet hilang di perjalanan, dokter perlu masuk dari PC ruang dokter.';

    dflRun([
        '--doctor' => (string) $doctor->id,
        '--actor' => (string) $operator->id,
        '--reason' => $reason,
        '--apply' => true,
    ]);

    // ROW ONE: the lease lifecycle, carrying the closed reason vocabulary.
    $revoked = AuditLog::query()
        ->where('action', DoctorSessionLeaseService::ACTION_REVOKED)
        ->where('entity_type', DoctorSessionLeaseService::AUDIT_ENTITY_LEASES)
        ->first();

    expect($revoked)->not->toBeNull()
        ->and($revoked->new_values['reason'] ?? null)->toBe(DoctorSessionLease::RELEASE_ADMIN);

    /*
     * ROW TWO: WHY a human ended somebody else's session, in their own words —
     * a fact the first row cannot hold, because its `reason` is a closed
     * vocabulary. An eviction nobody can explain is not an operational action.
     */
    $decision = AuditLog::query()
        ->where('action', DoctorSessionReleaseService::ACTION_RELEASED_BY_APPROVER)
        ->where('entity_type', DoctorSessionReleaseService::ENTITY_TYPE)
        ->where('entity_id', (int) $doctor->id)
        ->first();

    expect($decision)->not->toBeNull()
        ->and($decision->new_values['reason'] ?? null)->toBe($reason)
        ->and((int) ($decision->new_values['user_id'] ?? 0))->toBe((int) $user->id)
        ->and($decision->new_values['session_released'] ?? null)->toBeTrue()
        // THE GUARANTEE, WRITTEN DOWN. The trail itself states that no device
        // estate was touched, so somebody reading it a month later does not have
        // to re-derive it from the call graph.
        ->and($decision->new_values['devices_touched'] ?? null)->toBeFalse()
        ->and($decision->new_values['webauthn_credentials_touched'] ?? null)->toBeFalse();

    // Attributed to the operator, not to 'the console'. The column is
    // `performed_by` — `sys_audit_logs` has no `user_id`, and asserting the
    // wrong column would read as an unattributed row rather than a failure.
    expect((int) $decision->performed_by)->toBe((int) $operator->id);
});

/*
|--------------------------------------------------------------------------
| 5. The actor
|--------------------------------------------------------------------------
*/

it('refuses to run with no actor at all', function () {
    ['doctor' => $doctor, 'lease' => $lease] = dflDoctorHoldingLease();

    $result = dflRun([
        '--doctor' => (string) $doctor->id,
        '--reason' => 'Tidak ada aktor yang disebut, jadi tidak ada yang bertanggung jawab.',
        '--apply' => true,
    ]);

    expect($result['exit'])->toBe(SymfonyCommand::FAILURE)
        ->and($result['payload']['refused'])->toBeTrue();

    // THE LINUX USER IS NOT AN APPLICATION IDENTITY. Without a named actor there
    // is nobody to attribute the release to and nobody for the self-release
    // refusal to compare against, so the command declines rather than inventing
    // 'system'.
    expect($lease->fresh()->released_at)->toBeNull();
    expect(dflAuditCount(DoctorSessionReleaseService::ACTION_RELEASED_BY_APPROVER))->toBe(0);
});

it('refuses an unknown actor, an inactive actor and an unauthorized actor', function () {
    ['user' => $user, 'doctor' => $doctor, 'lease' => $lease] = dflDoctorHoldingLease();

    $reason = 'Mencoba mengakhiri sesi tanpa wewenang yang benar.';

    // UNKNOWN: an id nobody holds.
    $unknown = dflRun([
        '--doctor' => (string) $doctor->id,
        '--actor' => '99999999',
        '--reason' => $reason,
        '--apply' => true,
    ]);

    expect($unknown['exit'])->toBe(SymfonyCommand::FAILURE);

    // INACTIVE: a real account that held the grant and has since been retired.
    $retired = daSupervisorRme();
    $retired->forceFill(['is_active' => false])->save();

    $inactive = dflRun([
        '--doctor' => (string) $doctor->id,
        '--actor' => (string) $retired->id,
        '--reason' => $reason,
        '--apply' => true,
    ]);

    expect($inactive['exit'])->toBe(SymfonyCommand::FAILURE);

    // UNAUTHORIZED: a real, active, seeded role that holds no doctor-access
    // permission. A role-LESS user would fail for the wrong reason.
    $kasir = daUnauthorisedActor('Kasir');

    expect($kasir->can('release_doctor_session_leases'))->toBeFalse();

    $unauthorized = dflRun([
        '--doctor' => (string) $doctor->id,
        '--actor' => (string) $kasir->id,
        '--reason' => $reason,
        '--apply' => true,
    ]);

    expect($unauthorized['exit'])->toBe(SymfonyCommand::FAILURE);

    // Three refusals, no writes.
    expect($lease->fresh()->released_at)->toBeNull();
    daAssertActiveLeaseCount(1, $user);
    expect(dflAuditCount(DoctorSessionReleaseService::ACTION_RELEASED_BY_APPROVER))->toBe(0);
});

it('resolves an actor by email as well as by id', function () {
    ['doctor' => $doctor, 'lease' => $lease] = dflDoctorHoldingLease();

    $operator = daSupervisorRme();

    $result = dflRun([
        '--doctor' => (string) $doctor->id,
        '--actor' => $operator->email,
        '--reason' => 'Dipanggil dari runbook, operator diidentifikasi lewat email.',
        '--apply' => true,
    ]);

    expect($result['exit'])->toBe(SymfonyCommand::SUCCESS)
        ->and((int) $result['payload']['actor_user_id'])->toBe((int) $operator->id);

    expect((int) $lease->fresh()->released_by_user_id)->toBe((int) $operator->id);
});

it('refuses a self release even for an actor who holds the grant', function () {
    /*
     * An operator ending their OWN session through this action is not a support
     * action; it is a logout, and the logout path is where a session is released
     * cleanly with its own reason. Blocking it here keeps `admin_release` meaning
     * what it says in the trail.
     *
     * THE REFUSAL LIVES IN THE SERVICE, inside the transaction, after the doctor
     * row is locked — a permission check could not catch it, and a policy clause
     * would not run for a Super Admin, whom the single global `Gate::before`
     * short-circuits. So the actor here deliberately HOLDS the grant.
     */
    daArmDoctorAccess();

    $operator = daSupervisorRme();

    // The same human is both the operator and the doctor: a supervisor who also
    // sees patients. Their user account is linked to the doctor record.
    ['doctor' => $doctor] = daDoctorAccount([daBranch()], user: $operator);

    expect($operator->fresh()->can('release_doctor_session_leases'))->toBeTrue();

    daLoginPost($operator->fresh());
    $lease = daCurrentLease($operator);
    expect($lease)->not->toBeNull();

    foreach ([false, true] as $apply) {
        $options = [
            '--doctor' => (string) $doctor->id,
            '--actor' => (string) $operator->id,
            '--reason' => 'Mencoba mengakhiri sesi saya sendiri lewat jalur operator.',
        ];

        if ($apply) {
            $options['--apply'] = true;
        }

        $result = dflRun($options);

        // REFUSED ON BOTH PATHS, because the preview runs the same guard: a
        // dry run that reported a release the decision would refuse is a worse
        // preview than none.
        expect($result['exit'])->toBe(SymfonyCommand::FAILURE)
            ->and($result['payload']['refused'])->toBeTrue();
    }

    expect($lease->fresh()->released_at)->toBeNull();
    daAssertActiveLeaseCount(1, $operator);
    expect(dflAuditCount(DoctorSessionReleaseService::ACTION_RELEASED_BY_APPROVER))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| 6. The subject
|--------------------------------------------------------------------------
*/

it('refuses a doctor id that is absent, inactive or unlinked', function () {
    daArmDoctorAccess();

    $operator = daSupervisorRme();
    $reason = 'Menguji penolakan subjek yang tidak dapat diputuskan.';

    // ABSENT.
    expect(dflRun([
        '--doctor' => '99999999',
        '--actor' => (string) $operator->id,
        '--reason' => $reason,
        '--apply' => true,
    ])['exit'])->toBe(SymfonyCommand::FAILURE);

    // INACTIVE, read at decision time and never trusted from an earlier screen.
    ['doctor' => $inactive] = daDoctorAccount([daBranch()]);
    $inactive->forceFill(['is_active' => false])->save();

    expect(dflRun([
        '--doctor' => (string) $inactive->id,
        '--actor' => (string) $operator->id,
        '--reason' => $reason,
        '--apply' => true,
    ])['exit'])->toBe(SymfonyCommand::FAILURE);

    /*
     * UNLINKED. A Doctor-role record with no `mst_doctors.user_id` has no
     * session to end, so without this refusal the command would report success
     * while doing nothing — the worst possible answer for an operator trying to
     * unstick a doctor.
     */
    ['doctor' => $unlinked] = daDoctorAccount([daBranch()]);
    $unlinked->forceFill(['user_id' => null])->save();

    $result = dflRun([
        '--doctor' => (string) $unlinked->id,
        '--actor' => (string) $operator->id,
        '--reason' => $reason,
        '--apply' => true,
    ]);

    expect($result['exit'])->toBe(SymfonyCommand::FAILURE)
        ->and($result['payload']['refused'])->toBeTrue()
        // The message names the REMEDY, not just the condition.
        ->and($result['payload']['message'])->toContain('master dokter');

    expect(dflAuditCount(DoctorSessionReleaseService::ACTION_RELEASED_BY_APPROVER))->toBe(0);
});

it('refuses a malformed doctor option instead of guessing at one', function () {
    daArmDoctorAccess();

    $operator = daSupervisorRme();

    foreach (['0', 'abc', '-1', '1.5'] as $malformed) {
        $result = dflRun([
            '--doctor' => $malformed,
            '--actor' => (string) $operator->id,
            '--reason' => 'Nilai --doctor tidak dapat dibaca sebagai id.',
            '--apply' => true,
        ]);

        expect($result['exit'])->toBe(SymfonyCommand::FAILURE)
            ->and($result['payload']['refused'])->toBeTrue();
    }

    // And with the option absent entirely, which is what a runbook step missing
    // a copy-paste looks like.
    $absent = dflRun([
        '--actor' => (string) $operator->id,
        '--reason' => 'Nilai --doctor tidak disertakan sama sekali.',
        '--apply' => true,
    ]);

    expect($absent['exit'])->toBe(SymfonyCommand::FAILURE)
        ->and($absent['payload']['refused'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 7. Idempotent, and honest about it
|--------------------------------------------------------------------------
*/

it('reports nothing to release rather than claiming a release that never happened', function () {
    ['user' => $user, 'doctor' => $doctor] = dflDoctorHoldingLease();

    $operator = daSupervisorRme();

    $options = [
        '--doctor' => (string) $doctor->id,
        '--actor' => (string) $operator->id,
        '--reason' => 'Sesi macet, dokter menunggu. Dijalankan dua kali dari runbook.',
        '--apply' => true,
    ];

    $first = dflRun($options);

    expect($first['exit'])->toBe(SymfonyCommand::SUCCESS)
        ->and($first['payload']['released'])->toBeTrue();

    // A SECOND RUN IS NOT AN ERROR. An operator re-running a runbook step must
    // not be told something failed — but must also not be told a release
    // happened when none did.
    $second = dflRun($options);

    expect($second['exit'])->toBe(SymfonyCommand::SUCCESS)
        ->and($second['payload']['applied'])->toBeTrue()
        ->and($second['payload']['released'])->toBeFalse();

    daAssertActiveLeaseCount(0, $user);

    // One release, two decision rows: the second run really did nothing to the
    // lease, and really did record that somebody asked.
    expect(dflAuditCount(DoctorSessionLeaseService::ACTION_REVOKED))->toBe(1);
    expect(dflAuditCount(DoctorSessionReleaseService::ACTION_RELEASED_BY_APPROVER))->toBe(2);

    expect(AuditLog::query()
        ->where('action', DoctorSessionReleaseService::ACTION_RELEASED_BY_APPROVER)
        ->orderByDesc('id')
        ->first()
        ->new_values['session_released'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 8. IT ENDS A LOGIN SESSION. IT ENDS NOTHING ELSE.
|--------------------------------------------------------------------------
*/

it('revokes no device, no authorization and no webauthn credential', function () {
    /*
     * RULING P17, MEASURED. WebAuthn revocation is irreversible — the only write
     * to `revoked_at` in the registration service is `=> now()` — so a support
     * action taken to unstick a doctor at 08:00 must never destroy the credential
     * on their tablet. The doctor logs back in; they do not re-enrol.
     *
     * Asserted against REAL ROWS. Counting zero unchanged rows would pass for
     * every implementation, including one that revoked everything it found.
     */
    ['user' => $user, 'doctor' => $doctor] = dflDoctorHoldingLease();

    ['device' => $device, 'authorization' => $authorization, 'credential' => $credential]
        = dflDeviceEstate($doctor);

    // The estate is genuinely live before the release, or the assertions below
    // would be about rows that were already dead.
    expect($device->fresh()->status)->toBe(DoctorDevice::STATUS_ACTIVE)
        ->and($authorization->fresh()->isActive())->toBeTrue()
        ->and($credential->fresh()->revoked_at)->toBeNull();

    $deviceSnapshot = $device->fresh()->only(['status', 'revoked_at', 'identity_state', 'enrollment_status']);
    $authorizationSnapshot = $authorization->fresh()->only(['status', 'revoked_at', 'revoked_reason']);
    $credentialSnapshot = $credential->fresh()->only(['revoked_at', 'revoked_by', 'revoked_reason']);

    $result = dflRun([
        '--doctor' => (string) $doctor->id,
        '--actor' => (string) daSupervisorRme()->id,
        '--reason' => 'Sesi macet di tablet bangsal; perangkat tetap dipercaya.',
        '--apply' => true,
    ]);

    expect($result['exit'])->toBe(SymfonyCommand::SUCCESS)
        ->and($result['payload']['released'])->toBeTrue();

    // The session is gone.
    daAssertActiveLeaseCount(0, $user);

    // THE ESTATE IS UNCHANGED, field by field.
    expect($device->fresh()->only(['status', 'revoked_at', 'identity_state', 'enrollment_status']))
        ->toBe($deviceSnapshot);
    expect($authorization->fresh()->only(['status', 'revoked_at', 'revoked_reason']))
        ->toBe($authorizationSnapshot);
    expect($credential->fresh()->only(['revoked_at', 'revoked_by', 'revoked_reason']))
        ->toBe($credentialSnapshot);

    // Nothing anywhere in those three tables was retired, not merely nothing
    // belonging to this doctor.
    expect(DoctorDevice::query()->whereNotNull('revoked_at')->count())->toBe(0)
        ->and(DoctorDeviceAuthorization::query()->whereNotNull('revoked_at')->count())->toBe(0)
        ->and(DoctorDeviceWebAuthnCredential::query()->whereNotNull('revoked_at')->count())->toBe(0);

    // And the counts are non-zero, so the three assertions above are statements
    // about rows that exist rather than about an empty table.
    expect(DoctorDevice::query()->count())->toBeGreaterThan(0)
        ->and(DoctorDeviceAuthorization::query()->count())->toBeGreaterThan(0)
        ->and(DoctorDeviceWebAuthnCredential::query()->count())->toBeGreaterThan(0);
});
