<?php

/**
 * SECURITY-DOCTOR-WEBAUTHN-DEVICE-BOUND-FAIL-CLOSED-1
 *
 * Two fail-open defects, each reproduced here against the unmodified code
 * before it was fixed.
 *
 * 1. CONFIGURATION. `config/webauthn.php` read the requirement as
 *    `(bool) env('WEBAUTHN_REQUIRE_DEVICE_BOUND', true)`. Laravel's env() hands
 *    a present-but-empty variable back as '' (and maps the literals `empty`,
 *    `(empty)`, `null` and `(null)` to '' or null), and PHP casts every one of
 *    those to FALSE. So a blank line in the environment file — the most
 *    ordinary editing accident there is — silently turned the device-bound
 *    requirement OFF, and a syncable passkey on a doctor's phone became a
 *    working clinic credential. The runtime reader had the same shape:
 *    `(bool) config(...)` turns a null value into "not required".
 *
 * 2. AUTHENTICATOR DATA. The library verifies the assertion signature and then,
 *    when the signed BE bit differs from the registered credential, DISPATCHES
 *    AN EVENT and carries on. Nothing listened. Both login services gated only on
 *    the verdict STORED at registration, so an assertion whose signed
 *    authenticator data says "this credential is backup-eligible" was accepted
 *    for a credential registered as device-bound. W3C WebAuthn Level 3 §7.2
 *    makes that comparison mandatory for a relying party that uses backup state
 *    as policy, which this one does.
 *
 * WHAT THESE TESTS ARE CAREFUL ABOUT
 *
 * Fail closed means the REQUIREMENT stays on when its configuration is unclear.
 * It does not mean doctor device enforcement switches on, that a pilot widens,
 * or that a deliberately written `false` stops working. Each of those is pinned
 * here too, because a fix that made the posture "safer" by changing who is
 * enforced would be a different, unreviewed change.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorDeviceWebAuthnLoginService;
use App\Modules\DoctorDevice\Support\WebAuthnDeviceBinding;
use App\Modules\FrontOfficeDevice\Services\FrontOfficeBranchDeviceLockService;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Support\AccessControl\FrontOfficeRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\FakeWebAuthnAuthenticator;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

const DBFC_ORIGIN = 'https://clinic.example.test';
const DBFC_RP_ID = 'clinic.example.test';
const DBFC_ENV_KEY = 'WEBAUTHN_REQUIRE_DEVICE_BOUND';

function dbfcRelyingParty(): void
{
    config()->set('app.url', DBFC_ORIGIN);
    config()->set('webauthn.relying_party.id', DBFC_RP_ID);
    config()->set('webauthn.relying_party.allowed_origins', DBFC_ORIGIN);
}

/**
 * Both doctor switches. The whole flags array is rewritten because the flag
 * KEYS contain dots; `config()->set()` on a dotted path would build a nested
 * structure FeatureFlagService never reads and leave the flag quietly off.
 */
function dbfcFlags(bool $enforcement, bool $webauthn): void
{
    $flags = config('feature_flags.flags', []);

    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $enforcement;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $enforcement;
    $flags[DoctorDeviceWebAuthnLoginService::FLAG]['default'] = $webauthn;
    $flags[DoctorDeviceWebAuthnLoginService::FLAG]['env_value'] = $webauthn;

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
 * Put the device-binding block the REAL config file builds for a given
 * environment value into the running config — via a var_export round trip, so
 * what is installed is exactly what `config:cache` would have written.
 */
function dbfcInstallBuiltDeviceBinding(?string $raw): array
{
    $built = ffBuildRegistry([DBFC_ENV_KEY => $raw], 'webauthn.php');

    $path = tempnam(sys_get_temp_dir(), 'dbfccache');
    file_put_contents($path, '<?php return '.var_export($built, true).';');

    try {
        $cached = require $path;
    } finally {
        @unlink($path);
    }

    config()->set('webauthn.device_binding', $cached['device_binding']);

    return $cached['device_binding'];
}

/** @return array{user: User, doctor: Doctor, device: DoctorDevice, branch: Branch} */
function dbfcClinicFixture(): array
{
    seedAccessControl();

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    $user = User::factory()->create(['password' => bcrypt('rahasia-klinik')]);
    $user->assignRole('Doctor');

    $doctor = Doctor::factory()->create(['user_id' => $user->id, 'is_active' => true]);

    $device = DoctorDevice::factory()->create([
        'branch_id' => $branch->id,
        'status' => DoctorDevice::STATUS_ACTIVE,
    ]);

    DoctorDeviceAuthorization::factory()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
        'status' => DoctorDeviceAuthorization::STATUS_ACTIVE,
    ]);

    return ['user' => $user, 'doctor' => $doctor, 'device' => $device, 'branch' => $branch];
}

/**
 * Register through the real endpoints as an operator would at the tablet.
 *
 * @return array{credential: ?DoctorDeviceWebAuthnCredential, authenticator: FakeWebAuthnAuthenticator, response: mixed}
 */
function dbfcEnroll(DoctorDevice $device, bool $backupEligible = false): array
{
    $authenticator = new FakeWebAuthnAuthenticator(DBFC_RP_ID, DBFC_ORIGIN, $backupEligible, false);

    actingAs(superAdmin());

    $options = postJson(route('settings.doctor-devices.webauthn.options', $device))->json();

    $response = post(route('settings.doctor-devices.webauthn.store', $device), [
        'credential' => $authenticator->attestation($options['challenge']),
    ]);

    auth()->logout();

    return [
        'credential' => DoctorDeviceWebAuthnCredential::query()
            ->where('credential_id', $authenticator->credentialIdBase64Url())
            ->first(),
        'authenticator' => $authenticator,
        'response' => $response,
    ];
}

/** Password step, then the options call, exactly as the browser performs it. */
function dbfcChallengeFor(User $user): string
{
    post(route('login'), ['email' => $user->email, 'password' => 'rahasia-klinik']);

    return (string) postJson(route('doctor-device-webauthn.options'))->json('challenge');
}

function dbfcRejectionReasons(): array
{
    return DB::table('sys_audit_logs')
        ->where('action', 'DOCTOR_DEVICE_WEBAUTHN_LOGIN_REJECTED')
        ->pluck('new_values')
        ->map(fn ($v) => json_decode((string) $v, true)['reason'] ?? null)
        ->all();
}

beforeEach(function () {
    dbfcRelyingParty();
});

/* ---------------------------------------------------------------------------
 | 1. The configuration contract — the REAL config file, controlled environment
 |-------------------------------------------------------------------------- */

// Literals, not constants: a dataset is evaluated when the file loads, and the
// vocabulary is pinned against the class constants in the next test.
dataset('device-bound environment values', [
    // raw value          required  source
    'unset' => [null, true, 'default'],
    'empty string' => ['', true, 'blank_fail_closed'],
    'whitespace only' => ['   ', true, 'blank_fail_closed'],
    'laravel empty keyword' => ['empty', true, 'blank_fail_closed'],
    'laravel (empty) keyword' => ['(empty)', true, 'blank_fail_closed'],
    'laravel null keyword' => ['null', true, 'default'],
    'laravel (null) keyword' => ['(null)', true, 'default'],
    'unparseable word' => ['maybe', true, 'invalid_fail_closed'],
    'off is not a relaxation' => ['off', true, 'invalid_fail_closed'],
    'no is not a relaxation' => ['no', true, 'invalid_fail_closed'],
    'numeric two' => ['2', true, 'invalid_fail_closed'],
    // A container env-file keeps quotes literally. env() maps booleans BEFORE it
    // strips quotes, so these arrive as the STRING "false" — truthy before the
    // fix, so they must stay REQUIRED (found by the independent review).
    'single-quoted false' => ["'false'", true, 'invalid_fail_closed'],
    'double-quoted false' => ['"false"', true, 'invalid_fail_closed'],
    'single-quoted (false)' => ["'(false)'", true, 'invalid_fail_closed'],
    'quoted zero still relaxes' => ["'0'", false, 'explicit_off'],
    'true' => ['true', true, 'explicit_on'],
    'TRUE' => ['TRUE', true, 'explicit_on'],
    'one' => ['1', true, 'explicit_on'],
    'on' => ['on', true, 'explicit_on'],
    'yes' => ['yes', true, 'explicit_on'],
    'false' => ['false', false, 'explicit_off'],
    'FALSE' => ['FALSE', false, 'explicit_off'],
    'laravel (false) keyword' => ['(false)', false, 'explicit_off'],
    'zero' => ['0', false, 'explicit_off'],
]);

it('resolves the requirement from the environment and fails closed on anything unclear', function (?string $raw, bool $required, string $source) {
    $built = ffBuildRegistry([DBFC_ENV_KEY => $raw], 'webauthn.php');

    expect($built['device_binding']['require_device_bound'])->toBe($required)
        ->and($built['device_binding']['require_device_bound_source'])->toBe($source);
})->with('device-bound environment values');

it('pins the source vocabulary the reports and the dataset rely on', function () {
    expect([
        WebAuthnDeviceBinding::SOURCE_DEFAULT,
        WebAuthnDeviceBinding::SOURCE_EXPLICIT_ON,
        WebAuthnDeviceBinding::SOURCE_EXPLICIT_OFF,
        WebAuthnDeviceBinding::SOURCE_BLANK,
        WebAuthnDeviceBinding::SOURCE_INVALID,
        WebAuthnDeviceBinding::SOURCE_RUNTIME_INVALID,
        WebAuthnDeviceBinding::SOURCE_UNRECORDED,
    ])->toBe([
        'default',
        'explicit_on',
        'explicit_off',
        'blank_fail_closed',
        'invalid_fail_closed',
        'runtime_value_invalid_fail_closed',
        'unrecorded',
    ]);
});

it('relaxes the requirement only for the two spellings that already relaxed it before this fix', function () {
    // The relaxing vocabulary is deliberately the narrowest possible: `false`
    // and `0` (and Laravel's `(false)`, which env() hands over as boolean
    // false). `off` and `no` used to resolve to REQUIRED — `(bool) 'off'` is
    // true in PHP — so accepting them as a relaxation now would silently weaken
    // a deployment that relied on the old behaviour. A fix is never allowed to
    // be a relaxation.
    // Every string here was truthy before the fix (`(bool) ' 0 '`, `(bool)
    // 'false'` are true), so each stays REQUIRED. The STRINGS 'false' and
    // '(false)' only reach the resolver when the quotes were literal — a bare
    // `false` is a boolean by then.
    foreach (['off', 'no', 'disabled', 'n', 'f', ' false ', ' 0 ', "false\n", 'false', 'FALSE', '(false)', '00'] as $spelling) {
        expect(WebAuthnDeviceBinding::resolveRequirementFromEnv($spelling))->toBeTrue(var_export($spelling, true).' must not relax');
    }

    foreach (['0', false, 0] as $spelling) {
        expect(WebAuthnDeviceBinding::resolveRequirementFromEnv($spelling))->toBeFalse(var_export($spelling, true));
    }
});

it('keeps the requirement on across a config cache round trip when the variable is blank', function () {
    // Production runs a cached config, where env() is never read at request
    // time. The value that matters is the one var_export'ed into the cache.
    $block = dbfcInstallBuiltDeviceBinding('');

    expect($block['require_device_bound'])->toBeTrue()
        ->and(WebAuthnDeviceBinding::isRequired())->toBeTrue()
        ->and(WebAuthnDeviceBinding::requirementPosture())->toBe([
            'required' => true,
            'source' => WebAuthnDeviceBinding::SOURCE_BLANK,
            'valid' => false,
        ]);
});

it('honours an explicit, reviewed relaxation across a config cache round trip', function () {
    dbfcInstallBuiltDeviceBinding('false');

    expect(WebAuthnDeviceBinding::isRequired())->toBeFalse()
        ->and(WebAuthnDeviceBinding::requirementPosture())->toBe([
            'required' => false,
            'source' => WebAuthnDeviceBinding::SOURCE_EXPLICIT_OFF,
            'valid' => true,
        ]);
});

it('reports the shipped default as required and valid', function () {
    dbfcInstallBuiltDeviceBinding(null);

    expect(WebAuthnDeviceBinding::requirementPosture())->toBe([
        'required' => true,
        'source' => WebAuthnDeviceBinding::SOURCE_DEFAULT,
        'valid' => true,
    ]);
});

/* ---------------------------------------------------------------------------
 | 2. The runtime reader — only a genuine boolean false relaxes
 |-------------------------------------------------------------------------- */

dataset('non-boolean runtime values', [
    'null' => [null],
    'empty string' => [''],
    'string false' => ['false'],
    'string zero' => ['0'],
    'integer zero' => [0],
    'string off' => ['off'],
    'array' => [[]],
]);

it('treats any non-boolean runtime value as required', function (mixed $value) {
    // A hand-edited cache, a test override or a future refactor can put a
    // non-boolean here. `(bool) null` and `(bool) ''` are false, which is how
    // the old reader turned "I do not know" into "not required".
    config()->set(WebAuthnDeviceBinding::CONFIG_KEY, $value);

    expect(WebAuthnDeviceBinding::isRequired())->toBeTrue()
        ->and(WebAuthnDeviceBinding::requirementPosture())->toBe([
            'required' => true,
            'source' => WebAuthnDeviceBinding::SOURCE_RUNTIME_INVALID,
            'valid' => false,
        ]);
})->with('non-boolean runtime values');

it('treats a missing requirement key as required', function () {
    config()->set('webauthn.device_binding', ['verdict_pass' => 'device_bound']);

    expect(WebAuthnDeviceBinding::isRequired())->toBeTrue();
});

it('does not call an unexplained runtime relaxation a clean configuration', function () {
    // e.g. a config cache built by the pre-fix file from a blank variable: it
    // holds `false` and no provenance at all.
    config()->set('webauthn.device_binding', ['require_device_bound' => false]);

    expect(WebAuthnDeviceBinding::requirementPosture())->toBe([
        'required' => false,
        'source' => WebAuthnDeviceBinding::SOURCE_UNRECORDED,
        'valid' => false,
    ]);

    // A REQUIRED value with no provenance is harmless whatever its origin.
    config()->set('webauthn.device_binding', ['require_device_bound' => true]);

    expect(WebAuthnDeviceBinding::requirementPosture()['valid'])->toBeTrue();
});

it('flags a cached value that contradicts the source it was recorded with', function () {
    // e.g. a cache file edited by hand to `false` while the environment never
    // said so. The boolean is still honoured — it is the only authority the
    // runtime has — but the report must not call it a clean configuration.
    config()->set(WebAuthnDeviceBinding::CONFIG_KEY, false);
    config()->set(WebAuthnDeviceBinding::SOURCE_CONFIG_KEY, WebAuthnDeviceBinding::SOURCE_DEFAULT);

    expect(WebAuthnDeviceBinding::requirementPosture()['valid'])->toBeFalse();
});

/* ---------------------------------------------------------------------------
 | 3. Registration — the end-to-end effect of the configuration defect
 |-------------------------------------------------------------------------- */

it('refuses a syncable credential when the cached requirement is blank', function () {
    $fixture = dbfcClinicFixture();
    dbfcInstallBuiltDeviceBinding('');

    $result = dbfcEnroll($fixture['device'], backupEligible: true);

    $result['response']->assertSessionHasErrors('credential');

    expect($result['credential'])->toBeNull()
        ->and(DB::table('sys_audit_logs')
            ->where('action', 'DOCTOR_DEVICE_WEBAUTHN_REGISTRATION_REJECTED')
            ->where('new_values', 'like', '%device_binding_policy%')
            ->count())->toBe(1);
});

it('refuses a syncable credential when the runtime requirement is null', function () {
    $fixture = dbfcClinicFixture();
    config()->set(WebAuthnDeviceBinding::CONFIG_KEY, null);

    $result = dbfcEnroll($fixture['device'], backupEligible: true);

    // Refused for THIS reason, not for something unrelated.
    expect($result['credential'])->toBeNull()
        ->and(DB::table('sys_audit_logs')
            ->where('action', 'DOCTOR_DEVICE_WEBAUTHN_REGISTRATION_REJECTED')
            ->where('new_values', 'like', '%device_binding_policy%')
            ->count())->toBe(1);
});

it('still registers a device-bound credential when the requirement is blank', function () {
    // Fail closed must not become "nothing can be enrolled".
    $fixture = dbfcClinicFixture();
    dbfcInstallBuiltDeviceBinding('');

    $result = dbfcEnroll($fixture['device'], backupEligible: false);

    expect($result['credential'])->not->toBeNull()
        ->and($result['credential']->device_bound_verdict)->toBe(DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND);
});

/* ---------------------------------------------------------------------------
 | 4. Doctor login — the signed BE bit must agree with the registration
 |-------------------------------------------------------------------------- */

it('completes a login whose signed backup flags agree with the registration', function () {
    $fixture = dbfcClinicFixture();
    ['authenticator' => $authenticator] = dbfcEnroll($fixture['device']);
    dbfcFlags(enforcement: true, webauthn: true);

    $challenge = dbfcChallengeFor($fixture['user']);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($challenge, (string) $fixture['device']->uuid),
    ])->assertSessionHasNoErrors();

    expect(auth()->id())->toBe($fixture['user']->id)
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBe($fixture['device']->id);
});

it('refuses a genuinely signed assertion that reports a device-bound credential as backup-eligible', function () {
    $fixture = dbfcClinicFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = dbfcEnroll($fixture['device']);
    dbfcFlags(enforcement: true, webauthn: true);

    expect($credential->device_bound_verdict)->toBe(DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND);

    $challenge = dbfcChallengeFor($fixture['user']);

    // Signed with the REAL key: the signature verifies, only the flags say
    // the credential may be synced. This is the case the library reports with
    // an event and does not refuse.
    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion(
            $challenge,
            (string) $fixture['device']->uuid,
            backupEligibleOverride: true,
        ),
    ])->assertSessionHasErrors('credential');

    $credential->refresh();

    expect(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBeNull()
        ->and(dbfcRejectionReasons())->toContain(WebAuthnDeviceBinding::REASON_BACKUP_ELIGIBILITY_CHANGED)
        // Nothing about a refused login may be recorded as use.
        ->and($credential->last_used_at)->toBeNull()
        ->and((int) $credential->signature_counter)->toBe(0)
        ->and(DB::table('sys_audit_logs')->where('action', 'DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS')->count())->toBe(0);
});

// PIN, not evidence of the fix: the library already refused this before the
// change. Kept so a future library upgrade cannot quietly stop refusing it.
it('refuses the invalid BE=0 BS=1 combination at assertion', function () {
    $fixture = dbfcClinicFixture();
    ['authenticator' => $authenticator] = dbfcEnroll($fixture['device']);
    dbfcFlags(enforcement: true, webauthn: true);

    $challenge = dbfcChallengeFor($fixture['user']);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion(
            $challenge,
            (string) $fixture['device']->uuid,
            backupStateOverride: true,
        ),
    ])->assertSessionHasErrors('credential');

    expect(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBeNull()
        ->and(dbfcRejectionReasons())->toContain('assertion_rejected');
});

// PIN, not evidence of the fix — and it also pins the ORDER: if the new BE
// check ever ran before signature verification, this would be refused as
// `backup_eligibility_changed` instead of `assertion_rejected`.
it('refuses a tampered authenticator data flag because the signature no longer covers it', function () {
    $fixture = dbfcClinicFixture();
    ['authenticator' => $authenticator] = dbfcEnroll($fixture['device']);
    dbfcFlags(enforcement: true, webauthn: true);

    $challenge = dbfcChallengeFor($fixture['user']);
    $payload = $authenticator->assertion($challenge, (string) $fixture['device']->uuid);

    // Flip BE in transit, after signing. The flags are covered by the
    // signature, so this must die in the library before any policy runs.
    $raw = base64_decode(strtr($payload['response']['authenticatorData'], '-_', '+/'), true);
    $raw[32] = chr(ord($raw[32]) | 0x08);
    $payload['response']['authenticatorData'] = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    post(route('doctor-device-webauthn.store'), ['credential' => $payload])
        ->assertSessionHasErrors('credential');

    expect(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBeNull()
        ->and(dbfcRejectionReasons())->toContain('assertion_rejected');
});

it('judges the signed BE against the policy when the registered BE was never recorded', function () {
    // A row whose verdict says device-bound but whose BE column is empty
    // (legacy or hand-edited). There is nothing to compare, so the consistency
    // rule cannot fire; the signed bit itself must still satisfy the policy.
    $fixture = dbfcClinicFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = dbfcEnroll($fixture['device']);
    $credential->forceFill(['backup_eligible' => null])->save();
    dbfcFlags(enforcement: true, webauthn: true);

    $challenge = dbfcChallengeFor($fixture['user']);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion(
            $challenge,
            (string) $fixture['device']->uuid,
            backupEligibleOverride: true,
        ),
    ])->assertSessionHasErrors('credential');

    expect(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBeNull()
        ->and(dbfcRejectionReasons())->toContain(WebAuthnDeviceBinding::REASON_ASSERTED_DEVICE_BINDING_POLICY);
});

it('accepts a never-recorded BE under an explicitly relaxed policy', function () {
    dbfcInstallBuiltDeviceBinding('false');

    $fixture = dbfcClinicFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = dbfcEnroll($fixture['device'], backupEligible: true);
    $credential->forceFill([
        'backup_eligible' => null,
        'device_bound_verdict' => DoctorDeviceWebAuthnCredential::VERDICT_UNKNOWN,
    ])->save();
    dbfcFlags(enforcement: true, webauthn: true);

    $challenge = dbfcChallengeFor($fixture['user']);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($challenge, (string) $fixture['device']->uuid),
    ])->assertSessionHasNoErrors();

    expect(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBe($fixture['device']->id);
});

it('lets an explicitly relaxed deployment keep using a consistently backup-eligible credential', function () {
    // Scope preservation: `false` is the reviewed, out-loud opt-out the config
    // file documents. The fix must not take it away.
    dbfcInstallBuiltDeviceBinding('false');

    $fixture = dbfcClinicFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = dbfcEnroll($fixture['device'], backupEligible: true);
    dbfcFlags(enforcement: true, webauthn: true);

    expect($credential)->not->toBeNull()
        ->and($credential->device_bound_verdict)->toBe(DoctorDeviceWebAuthnCredential::VERDICT_BACKUP_ELIGIBLE);

    $challenge = dbfcChallengeFor($fixture['user']);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($challenge, (string) $fixture['device']->uuid),
    ])->assertSessionHasNoErrors();

    expect(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBe($fixture['device']->id);
});

it('refuses a backup-eligibility change even under a relaxed policy', function () {
    // BE "MUST NOT change" (WebAuthn L3 §6.1.3). An authenticator that
    // contradicts its own registration is broken or lying, whatever the policy.
    dbfcInstallBuiltDeviceBinding('false');

    $fixture = dbfcClinicFixture();
    ['authenticator' => $authenticator] = dbfcEnroll($fixture['device'], backupEligible: true);
    dbfcFlags(enforcement: true, webauthn: true);

    $challenge = dbfcChallengeFor($fixture['user']);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion(
            $challenge,
            (string) $fixture['device']->uuid,
            backupEligibleOverride: false,
        ),
    ])->assertSessionHasErrors('credential');

    expect(dbfcRejectionReasons())->toContain(WebAuthnDeviceBinding::REASON_BACKUP_ELIGIBILITY_CHANGED);
});

it('ends an open session on its next request once a blank requirement makes its syncable credential unacceptable', function () {
    // A session established while the deployment had formally accepted
    // syncable credentials. The environment is then broken — the line blanked —
    // and the per-request check must read that as REQUIRED, not as relaxed.
    dbfcInstallBuiltDeviceBinding('false');

    $fixture = dbfcClinicFixture();
    ['authenticator' => $authenticator] = dbfcEnroll($fixture['device'], backupEligible: true);
    dbfcFlags(enforcement: true, webauthn: true);

    $challenge = dbfcChallengeFor($fixture['user']);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($challenge, (string) $fixture['device']->uuid),
    ]);

    expect(auth()->check())->toBeTrue();

    dbfcInstallBuiltDeviceBinding('');

    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse();
});

it('keeps an open device-bound session alive when the requirement turns blank', function () {
    // The other half: failing closed must not end a session that satisfies the
    // stricter reading anyway.
    $fixture = dbfcClinicFixture();
    ['authenticator' => $authenticator] = dbfcEnroll($fixture['device']);
    dbfcFlags(enforcement: true, webauthn: true);

    $challenge = dbfcChallengeFor($fixture['user']);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($challenge, (string) $fixture['device']->uuid),
    ]);

    dbfcInstallBuiltDeviceBinding('');

    get(route('profile.edit'));

    expect(auth()->check())->toBeTrue()
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBe($fixture['device']->id);
});

/* ---------------------------------------------------------------------------
 | 5. Scope preservation — fail closed is not "switch enforcement on"
 |-------------------------------------------------------------------------- */

it('leaves an ordinary doctor login untouched when enforcement is off and the requirement is blank', function () {
    $fixture = dbfcClinicFixture();
    dbfcInstallBuiltDeviceBinding('');
    dbfcFlags(enforcement: false, webauthn: false);

    post(route('login'), ['email' => $fixture['user']->email, 'password' => 'rahasia-klinik'])
        ->assertRedirect();

    expect(auth()->id())->toBe($fixture['user']->id)
        // No device step was demanded and no device binding was minted.
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBeNull();
});

it('does not touch the enforcement flags or the pilot scope', function () {
    $before = [
        config('feature_flags.flags'),
        config('doctor_device_enforcement'),
        config('android_release.enforcement.scope'),
    ];

    dbfcInstallBuiltDeviceBinding('');
    WebAuthnDeviceBinding::isRequired();
    WebAuthnDeviceBinding::requirementPosture();

    expect([
        config('feature_flags.flags'),
        config('doctor_device_enforcement'),
        config('android_release.enforcement.scope'),
    ])->toBe($before);
});

/* ---------------------------------------------------------------------------
 | 6. Front Office — the second assertion path gets the same check
 |-------------------------------------------------------------------------- */

/**
 * A Front Office account in the lock cohort, an approved SPN4 tablet with an
 * enrolled credential, and the account walked to the assertion step.
 *
 * @return array{user: User, device: DoctorDevice, authenticator: FakeWebAuthnAuthenticator, challenge: string}
 */
function dbfcFrontOfficeAtAssertionStep(): array
{
    seedAccessControl();

    $branch = Branch::factory()->create([
        'code' => 'SPN4', 'name' => 'Cabang SPN4', 'is_active' => true, 'is_rme_enabled' => true,
    ]);

    $user = User::factory()->create([
        'email' => 'fo-dbfc@example.test',
        'password' => Hash::make('password'),
    ])->assignRole(FrontOfficeRole::NAME);

    $flags = config('feature_flags.flags', []);
    $flags[FrontOfficeBranchDeviceLockService::FLAG]['default'] = true;
    config()->set('feature_flags.flags', $flags);
    config()->set('front_office_device_lock.scope.cohort', $user->id.':SPN4');

    $device = DoctorDevice::factory()->create([
        'branch_id' => $branch->id,
        'status' => DoctorDevice::STATUS_ACTIVE,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED,
    ]);

    $authenticator = new FakeWebAuthnAuthenticator(DBFC_RP_ID, DBFC_ORIGIN);

    test()->actingAs(User::factory()->create()->assignRole('Super Admin'))
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->withServerVariables(['HTTP_ORIGIN' => DBFC_ORIGIN]);

    $options = test()->postJson(route('settings.doctor-devices.webauthn.options', $device))->json();
    test()->post(route('settings.doctor-devices.webauthn.store', $device), [
        'credential' => $authenticator->attestation($options['challenge']),
    ])->assertSessionHasNoErrors();

    auth()->logout();
    test()->flushSession();

    test()->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('front-office-device-webauthn.show'));

    $options = test()->postJson(route('front-office-device-webauthn.options'))->json();

    return ['user' => $user, 'device' => $device, 'authenticator' => $authenticator, 'challenge' => (string) $options['challenge']];
}

function dbfcFrontOfficeRejectionReasons(): array
{
    return DB::table('sys_audit_logs')
        ->where('action', 'like', 'FRONT_OFFICE_DEVICE%REJECTED')
        ->pluck('new_values')
        ->map(fn ($v) => json_decode((string) $v, true)['reason'] ?? null)
        ->all();
}

it('completes a Front Office login whose signed backup flags agree with the registration', function () {
    $fo = dbfcFrontOfficeAtAssertionStep();

    test()->post(route('front-office-device-webauthn.store'), [
        'credential' => $fo['authenticator']->assertion($fo['challenge'], (string) $fo['device']->uuid),
    ])->assertSessionHasNoErrors();

    expect(session(FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID))->toBe($fo['device']->id);
});

it('refuses a Front Office assertion whose signed BE bit contradicts the registration', function () {
    $fo = dbfcFrontOfficeAtAssertionStep();

    test()->post(route('front-office-device-webauthn.store'), [
        'credential' => $fo['authenticator']->assertion(
            $fo['challenge'],
            (string) $fo['device']->uuid,
            backupEligibleOverride: true,
        ),
    ])->assertSessionHasErrors();

    expect(session(FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID))->toBeNull()
        ->and(DB::table('sys_audit_logs')
            ->where('action', 'FRONT_OFFICE_DEVICE_WEBAUTHN_LOGIN_SUCCESS')->count())->toBe(0)
        ->and(dbfcFrontOfficeRejectionReasons())->toContain(WebAuthnDeviceBinding::REASON_BACKUP_ELIGIBILITY_CHANGED);
});

// PIN of the ORDER on the Front Office path: a flag flipped after signing must
// die in the library (`assertion_rejected`), never reach the BE check first.
it('refuses a tampered Front Office authenticator data flag in the library, before the BE check', function () {
    $fo = dbfcFrontOfficeAtAssertionStep();

    $payload = $fo['authenticator']->assertion($fo['challenge'], (string) $fo['device']->uuid);
    $raw = base64_decode(strtr($payload['response']['authenticatorData'], '-_', '+/'), true);
    $raw[32] = chr(ord($raw[32]) | 0x08);
    $payload['response']['authenticatorData'] = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    test()->post(route('front-office-device-webauthn.store'), ['credential' => $payload])
        ->assertSessionHasErrors();

    expect(session(FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID))->toBeNull()
        ->and(dbfcFrontOfficeRejectionReasons())->toContain('assertion_rejected')
        ->and(dbfcFrontOfficeRejectionReasons())->not->toContain(WebAuthnDeviceBinding::REASON_BACKUP_ELIGIBILITY_CHANGED);
});

/* ---------------------------------------------------------------------------
 | 7. Reporting surfaces read the same answer the enforcement reads
 |-------------------------------------------------------------------------- */

function dbfcReadiness(array $options = []): array
{
    $out = new BufferedOutput;
    $exit = Artisan::call('webauthn:readiness', array_merge(['--json' => true, '--report-only' => true], $options), $out);

    return ['exit' => $exit, 'json' => json_decode($out->fetch(), true)];
}

it('reports an unparseable requirement as required and invalid without echoing the raw value', function () {
    dbfcInstallBuiltDeviceBinding('sentinel-raw-value-xyz');

    $result = dbfcReadiness();

    expect($result['json']['require_device_bound'])->toBeTrue()
        ->and($result['json']['require_device_bound_source'])->toBe(WebAuthnDeviceBinding::SOURCE_INVALID)
        ->and($result['json']['device_binding_config_valid'])->toBeFalse()
        ->and(json_encode($result['json']))->not->toContain('sentinel-raw-value-xyz');
});

it('reports a blank requirement as required and invalid', function () {
    dbfcInstallBuiltDeviceBinding('');

    $json = dbfcReadiness()['json'];

    expect($json['require_device_bound'])->toBeTrue()
        ->and($json['require_device_bound_source'])->toBe(WebAuthnDeviceBinding::SOURCE_BLANK)
        ->and($json['device_binding_config_valid'])->toBeFalse();
});

it('reports the runtime value the enforcement actually uses, not a re-cast of it', function () {
    // The old report re-cast the config itself, so a null value printed
    // `require_device_bound=false` beside an enforcement path that — after this
    // fix — refuses syncable credentials. Two readers, two answers.
    config()->set(WebAuthnDeviceBinding::CONFIG_KEY, null);

    expect(dbfcReadiness()['json']['require_device_bound'])->toBeTrue();
});

it('fails --strict on an invalid device-binding configuration and passes it on a clean one', function () {
    dbfcInstallBuiltDeviceBinding('');
    expect(dbfcReadiness(['--strict' => true])['exit'])->toBe(1);

    dbfcInstallBuiltDeviceBinding(null);
    expect(dbfcReadiness(['--strict' => true])['exit'])->toBe(0);

    // A reviewed relaxation is a valid configuration, reported loudly but not a
    // configuration fault.
    dbfcInstallBuiltDeviceBinding('false');
    $relaxed = dbfcReadiness(['--strict' => true]);
    expect($relaxed['exit'])->toBe(0)
        ->and($relaxed['json']['require_device_bound'])->toBeFalse();
});

it('tells the enrolment operator that syncable credentials are refused when the requirement is blank', function () {
    $fixture = dbfcClinicFixture();
    config()->set(WebAuthnDeviceBinding::CONFIG_KEY, null);

    actingAs(superAdmin());

    get(route('settings.doctor-devices.webauthn.create', $fixture['device']))
        ->assertOk()
        ->assertSee('akan <strong>ditolak</strong>', false);
});

/* ---------------------------------------------------------------------------
 | 8. Structural guards — the fail-open shape cannot quietly come back
 |-------------------------------------------------------------------------- */

it('never casts the device-bound environment variable straight to a boolean', function () {
    $config = (string) file_get_contents(config_path('webauthn.php'));

    expect($config)->not->toMatch('/\(bool\)\s*env\(\s*[\'"]'.DBFC_ENV_KEY.'/')
        ->and($config)->toContain('WebAuthnDeviceBinding::resolveRequirementFromEnv(')
        ->and($config)->toContain('WebAuthnDeviceBinding::classifyRequirementOverride(');
});

it('reads the requirement in exactly one place', function () {
    // Three readers used to re-cast the config themselves and could disagree
    // with the enforcement. Every reader now asks WebAuthnDeviceBinding. The
    // scan covers both quote styles, reading the whole block and indexing it,
    // and the facade — across app, views, routes and bootstrap.
    $patterns = [
        '/[\'"]webauthn\.device_binding\.require_device_bound[\'"]/',
        '/(?:config|Config::get|config\(\)->get)\s*\(\s*[\'"]webauthn\.device_binding[\'"]/',
        '/(?:config|Config::get|config\(\)->get)\s*\(\s*[\'"]webauthn[\'"]\s*\)/',
    ];

    $offenders = [];

    foreach ([app_path(), resource_path('views'), base_path('routes'), base_path('bootstrap')] as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php'
                || str_ends_with($file->getPathname(), 'Support/WebAuthnDeviceBinding.php')
                || str_contains($file->getPathname(), 'bootstrap/cache')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});

it('checks the signed backup flags on every assertion path, after the signature and before any write', function () {
    foreach ([
        'app/Modules/DoctorDevice/Services/DoctorDeviceWebAuthnLoginService.php',
        'app/Modules/FrontOfficeDevice/Services/FrontOfficeDeviceWebAuthnLoginService.php',
    ] as $path) {
        $source = (string) file_get_contents(base_path($path));

        $verify = strpos($source, 'assertionValidator()->check(');
        $check = strpos($source, 'WebAuthnDeviceBinding::assertionDenialReason(');
        $used = strpos($source, 'if ($backupDenial !== null)');
        $write = strpos($source, "'last_used_at' => now()");

        // toContain() is variadic (every argument is a needle), so the path is
        // carried as the failure message of a boolean instead.
        expect($verify !== false && $check !== false && $used !== false && $write !== false)->toBeTrue("{$path}: markers missing")
            ->and($verify < $check)->toBeTrue("{$path}: the BE check must run after signature verification")
            ->and($check < $used)->toBeTrue("{$path}: the BE check result must be acted on")
            ->and($used < $write)->toBeTrue("{$path}: the BE check must run before the credential is stamped as used");
    }
});
