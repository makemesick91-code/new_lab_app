<?php

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1 — the pilot tablet reached
 * `/device-login/null` and got a 404, from a login that had entirely succeeded.
 *
 * WHAT ACTUALLY HAPPENED
 *
 * With enforcement off no login ticket is minted, and the response carried
 * `"login_ticket": null`. The shipped v0.3.0-phase3 client parses it with
 * `JSONObject.optString("login_ticket")`, and Android's `optString` returns the
 * literal four-character string `"null"` for a JSON null — it only returns the
 * `""` fallback when the key is ABSENT. So `.ifBlank { null }` never fired, the
 * client's state machine saw a non-blank ticket, chose the
 * "approved AND enforcement on" branch, and navigated to
 * `device-login/` + `"null"`.
 *
 * WHY THAT IS WORTH A TEST RATHER THAN A ONE-LINE EDIT
 *
 * It defeated a fail-closed property. `DoctorLoginStateMachine` treats a ticket
 * as the ONLY thing that opens the clinical app, and a doctor holding no ticket
 * was routed down the branch reserved for holding one. Nothing was granted —
 * the server mints no session without a real ticket, and the route 404s — but
 * the client's check was not checking what it believed, and that is exactly the
 * class of thing that stops being harmless later.
 *
 * WHY OMISSION RATHER THAN AN EMPTY STRING
 *
 * Both make the shipped client behave. Omission is chosen because absence is
 * what is actually true: there is no ticket. `""` would invent a VALUE whose
 * meaning is "a ticket that is the empty string", which is a different claim
 * and one a corrected client would have to special-case forever. Absence is
 * also what the existing assertions already expect — `json('login_ticket')`
 * returns null for an absent key as readily as for a null one — so no contract
 * moves.
 *
 * THE MODEL BELOW IS THE POINT
 *
 * `shippedClient*()` reproduces the shipped APK's parsing and navigation in
 * PHP. Without it this file could only assert what the server sends, which is
 * the half that was never in doubt. With it, the thing the pilot actually
 * needs — that the tablet cannot construct `/device-login/null` — is provable
 * here rather than only on a tablet in a clinic.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceEnrollment;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Support\DeviceKeyMaterial;
use App\Modules\DoctorDevice\Support\DeviceProofMessage;
use App\Support\Android\AndroidDoctorEnforcementScope;
use Database\Factories\DoctorDeviceEnrollmentFactory;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;

// ---------------------------------------------------------------------------
// A model of the SHIPPED v0.3.0-phase3 client. Deliberately faithful, quirk
// included — a corrected model would prove nothing about the APK in the clinic.
// ---------------------------------------------------------------------------

/**
 * `org.json.JSONObject.optString(name)` as Android implements it.
 *
 * Absent key   -> `opt()` is Java null      -> fallback ""
 * JSON null    -> `opt()` is JSONObject.NULL -> String.valueOf(...) -> "null"
 *
 * That asymmetry is the whole defect, so it is modelled rather than smoothed.
 *
 * @param  array<string,mixed>  $json  the decoded response body
 */
function shippedClientOptString(array $json, string $key): string
{
    if (! array_key_exists($key, $json)) {
        return '';
    }

    $value = $json[$key];

    if ($value === null) {
        return 'null';
    }

    return is_string($value) ? $value : var_export($value, true);
}

/** Kotlin's `optString(k).ifBlank { null }`. */
function shippedClientNullableString(array $json, string $key): ?string
{
    $value = shippedClientOptString($json, $key);

    return trim($value) === '' ? null : $value;
}

/** `DoctorLoginStateMachine.resolve`, for the outcomes this file exercises. */
function shippedClientScreen(?array $json): string
{
    if ($json === null) {
        return 'OFFLINE';
    }

    $outcome = shippedClientNullableString($json, 'outcome');
    $ticket = shippedClientNullableString($json, 'login_ticket');

    return match ($outcome) {
        'pending' => 'AWAITING_APPROVAL',
        'rejected' => 'ACCESS_REJECTED',
        'revoked' => 'ACCESS_REVOKED',
        'active' => $ticket !== null && trim($ticket) !== ''
            ? 'APPROVED_OPEN_CLINIC'
            : 'APPROVED_ENFORCEMENT_OFF',
        default => 'LOGIN_FAILED',
    };
}

/** The URL the shipped client would open, or null when it opens the clinic root. */
function shippedClientNavigation(array $json): ?string
{
    if (shippedClientScreen($json) !== 'APPROVED_OPEN_CLINIC') {
        return null;
    }

    return 'device-login/'.(shippedClientNullableString($json, 'login_ticket') ?? '');
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

function ticketFixture(): array
{
    seedAccessControl();

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $doctor = Doctor::factory()->withAllowedBranches([$branch])->create(['is_active' => true]);

    $user = User::factory()->create(['password' => bcrypt('password123')]);
    $user->assignRole('Doctor');
    $doctor->forceFill(['user_id' => $user->id])->save();

    [$pub, $priv] = DoctorDeviceEnrollmentFactory::generateKeyPair();
    $fingerprint = DeviceKeyMaterial::fingerprint($pub);

    DoctorDeviceEnrollment::factory()->create([
        'public_key' => $pub,
        'public_key_fingerprint' => $fingerprint,
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
        'status' => DoctorDeviceEnrollment::STATUS_PENDING,
        'device_model' => 'SM-X236B',
        'expires_at' => now()->addMinutes(15),
    ]);

    return compact('branch', 'doctor', 'user', 'pub', 'priv', 'fingerprint');
}

/** Ask for a nonce and sign it exactly as the app does. */
function ticketProof(array $f): array
{
    $challenge = postJson(route('device-api.v1.doctor.challenge'), ['fingerprint' => $f['fingerprint']])
        ->assertOk();

    $signature = '';
    openssl_sign(
        DeviceProofMessage::build($challenge->json('purpose'), $challenge->json('nonce'), $f['fingerprint']),
        $signature,
        $f['priv'],
        OPENSSL_ALGO_SHA256,
    );

    return ['nonce' => $challenge->json('nonce'), 'signature' => base64_encode($signature)];
}

function ticketLogin(array $f): TestResponse
{
    return postJson(route('device-api.v1.doctor.login'), array_merge(ticketProof($f), [
        'email' => $f['user']->email,
        'password' => 'password123',
    ]));
}

/** Arm enforcement, fleet-wide, so a ticket is actually mintable. */
function ticketArmEnforcement(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $on;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $on;
    config()->set('feature_flags.flags', $flags);

    config()->set('doctor_device_enforcement.scope', array_replace_recursive(
        (array) config('doctor_device_enforcement.scope'),
        ['mode' => AndroidDoctorEnforcementScope::MODE_UNSCOPED],
    ));

    config()->set('android_release.enforcement.scope', array_merge(
        (array) config('android_release.enforcement.scope'),
        ['global_permitted' => $on],
    ));
}

/**
 * Approve BOTH halves the way an administrator does.
 *
 * A device the app provisioned during first login is `pending_approval`, and a
 * ticket needs `deviceUsable()` as well as an ACTIVE authorization — so
 * approving only the pair leaves a state that can never mint one. Modelling
 * half an approval would have made the enforcement-ON tests pass for the wrong
 * reason.
 */
function ticketApprove(array $f): DoctorDeviceAuthorization
{
    DoctorDevice::query()->firstOrFail()->forceFill([
        'status' => DoctorDevice::STATUS_ACTIVE,
    ])->save();

    $authorization = DoctorDeviceAuthorization::query()->firstOrFail();
    $authorization->forceFill([
        'status' => DoctorDeviceAuthorization::STATUS_ACTIVE,
        'approved_at' => now(),
        'approved_by' => $f['user']->id,
    ])->save();

    return $authorization->refresh();
}

// ---------------------------------------------------------------------------
// 1-3. Enforcement OFF: absence, and the URL that can no longer be built
// ---------------------------------------------------------------------------

it('omits the login ticket entirely when enforcement is off and the pair is active', function () {
    $f = ticketFixture();

    ticketLogin($f)->assertOk();
    ticketApprove($f);

    $body = ticketLogin($f)->assertOk()->json();

    expect($body['outcome'])->toBe('active');

    // Absent, not null. Absence is what is true, and it is the only form the
    // shipped client reads as "no ticket".
    expect(array_key_exists('login_ticket', $body))->toBeFalse();
    expect(array_key_exists('login_ticket_expires_at', $body))->toBeFalse();
});

it('is read by the shipped client as approved-with-enforcement-off', function () {
    $f = ticketFixture();

    ticketLogin($f)->assertOk();
    ticketApprove($f);

    $body = ticketLogin($f)->assertOk()->json();

    expect(shippedClientScreen($body))->toBe('APPROVED_ENFORCEMENT_OFF');
});

it('cannot be turned into a device-login URL by the shipped client', function () {
    $f = ticketFixture();

    ticketLogin($f)->assertOk();
    ticketApprove($f);

    $body = ticketLogin($f)->assertOk()->json();

    expect(shippedClientNavigation($body))->toBeNull();
});

it('would have produced /device-login/null before this fix, which is why the shape matters', function () {
    // The old response shape, asserted directly so the regression has a name.
    // If someone reintroduces an explicit null here, this is the failure they
    // will read.
    $old = ['outcome' => 'active', 'login_ticket' => null];

    expect(shippedClientNullableString($old, 'login_ticket'))->toBe('null');
    expect(shippedClientScreen($old))->toBe('APPROVED_OPEN_CLINIC');
    expect(shippedClientNavigation($old))->toBe('device-login/null');

    // And the new shape, for contrast.
    $new = ['outcome' => 'active'];

    expect(shippedClientNullableString($new, 'login_ticket'))->toBeNull();
    expect(shippedClientScreen($new))->toBe('APPROVED_ENFORCEMENT_OFF');
    expect(shippedClientNavigation($new))->toBeNull();
});

// ---------------------------------------------------------------------------
// 4. Enforcement ON: the ticket is still emitted, unchanged
// ---------------------------------------------------------------------------

it('still emits a real ticket when enforcement is on and everything is active', function () {
    $f = ticketFixture();

    ticketLogin($f)->assertOk();
    ticketApprove($f);

    ticketArmEnforcement(true);

    $body = ticketLogin($f)->assertOk()->json();

    expect($body['outcome'])->toBe('active');
    expect(array_key_exists('login_ticket', $body))->toBeTrue();
    expect($body['login_ticket'])->toBeString()->not->toBe('');
    expect(array_key_exists('login_ticket_expires_at', $body))->toBeTrue();

    // And the shipped client opens the clinic through it, as designed.
    expect(shippedClientScreen($body))->toBe('APPROVED_OPEN_CLINIC');
    expect(shippedClientNavigation($body))->toBe('device-login/'.$body['login_ticket']);
});

// ---------------------------------------------------------------------------
// 5-6. The server stays fail-closed
// ---------------------------------------------------------------------------

it('refuses a forged ticket and mints no session from one', function () {
    $f = ticketFixture();
    ticketLogin($f)->assertOk();
    ticketApprove($f);
    ticketArmEnforcement(true);

    // A ticket that was never minted. Includes the literal the defect produced.
    foreach (['null', '', 'forged-ticket', str_repeat('a', 64)] as $forged) {
        test()->get('/device-login/'.$forged);
        test()->assertGuest();
    }
});

it('mints no session while enforcement is off, however approved the pair is', function () {
    $f = ticketFixture();

    ticketLogin($f)->assertOk();
    ticketApprove($f);

    $body = ticketLogin($f)->assertOk()->json();

    expect($body['enforcement_active'])->toBeFalse();
    test()->assertGuest();
});

// ---------------------------------------------------------------------------
// 7-9. Nothing about the pilot moved
// ---------------------------------------------------------------------------

it('changes no enforcement scope and leaves global enforcement off', function () {
    $f = ticketFixture();

    ticketLogin($f)->assertOk();
    ticketApprove($f);
    ticketLogin($f)->assertOk();

    $scope = app(AndroidDoctorEnforcementScope::class);

    expect(app(DoctorAppLoginGate::class)->enforcementEnabled())->toBeFalse();
    expect($scope->globalPermitted())->toBeFalse();
    expect(config('android_release.enforcement.scope.global_permitted'))->toBeFalse();
});

it('leaves a doctor outside the pilot scope logging in through a browser', function () {
    $f = ticketFixture();
    ticketLogin($f)->assertOk();
    ticketApprove($f);

    $other = User::factory()->create(['password' => bcrypt('password123')]);
    $other->assignRole('Doctor');

    // Pilot-scoped enforcement on someone else must not touch this doctor.
    $flags = config('feature_flags.flags', []);
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = true;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = true;
    config()->set('feature_flags.flags', $flags);
    config()->set('doctor_device_enforcement.scope', array_replace_recursive(
        (array) config('doctor_device_enforcement.scope'),
        ['mode' => AndroidDoctorEnforcementScope::MODE_PILOT, 'pilot' => ['doctor_user_id' => $f['user']->id]],
    ));

    test()->post(route('login'), ['email' => $other->email, 'password' => 'password123']);

    test()->assertAuthenticatedAs($other->fresh());
});

// ---------------------------------------------------------------------------
// 10. A corrected client must be able to read this too
// ---------------------------------------------------------------------------

it('stays deterministic for a corrected client that distinguishes absent from null', function () {
    $f = ticketFixture();

    ticketLogin($f)->assertOk();
    ticketApprove($f);

    $off = ticketLogin($f)->assertOk()->json();

    ticketArmEnforcement(true);
    $on = ticketLogin($f)->assertOk()->json();

    // A corrected client asks `has()`/`isNull()`. Both shapes answer cleanly:
    // the key is present exactly when a ticket exists, and never present
    // holding a null or an empty string.
    expect(array_key_exists('login_ticket', $off))->toBeFalse();
    expect(array_key_exists('login_ticket', $on))->toBeTrue();
    expect($on['login_ticket'])->not->toBeNull()->not->toBe('');

    // The rest of the envelope is unchanged in both, so nothing else has to be
    // re-learned by either client.
    foreach (['outcome', 'authorization_uuid', 'doctor', 'device', 'enforcement_active'] as $key) {
        expect(array_key_exists($key, $off))->toBeTrue("`{$key}` must survive in the enforcement-off shape");
        expect(array_key_exists($key, $on))->toBeTrue("`{$key}` must survive in the enforcement-on shape");
    }
});

it('never sends a null or empty-string ticket in either state', function () {
    $f = ticketFixture();

    ticketLogin($f)->assertOk();
    ticketApprove($f);

    foreach ([false, true] as $armed) {
        ticketArmEnforcement($armed);

        $body = ticketLogin($f)->assertOk()->json();

        if (array_key_exists('login_ticket', $body)) {
            expect($body['login_ticket'])->toBeString()->not->toBe('');
        }

        // The one shape the shipped client mis-reads must never be emitted.
        expect($body['login_ticket'] ?? 'absent')->not->toBeNull();
    }
});
