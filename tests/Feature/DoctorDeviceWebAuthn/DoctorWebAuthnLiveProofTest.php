<?php

declare(strict_types=1);

/**
 * FIX-DOCTOR-WEBAUTHN-READINESS-LIVE-PROOF-1 — the false-green suite.
 *
 * Every test below sets up a deployment that the OLD engine would have called
 * ARMED, and requires the new one to refuse. The positive path is last, and it
 * is deliberately the only test that may reach READY: if a change makes one of
 * the refusals pass, that change has reintroduced the defect.
 *
 * The shape under test is the one production actually holds. Device 3 carries
 * a revoked credential with real success rows beside a live one; devices 5 and
 * 6 last asserted on 2026-09-09; device 7 has never asserted at all.
 */

use App\Models\User;
use App\Modules\DoctorDevice\Interfaces\DoctorWebAuthnLiveProofRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorWebAuthnLiveProofService;
use App\Modules\LabOrder\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const LP_NOW = '2026-09-19 12:00:00';

beforeEach(function () {
    // A window must exist for STALE and PASS to be distinguishable at all.
    config()->set('doctor_webauthn_live_proof.freshness_window_days', 7);
    config()->set('webauthn.device_binding.require_device_bound', true);
});

function lpNow(): CarbonImmutable
{
    return CarbonImmutable::parse(LP_NOW, 'UTC');
}

/**
 * `sys_audit_logs.performed_by` is a real foreign key, so the trail cannot be
 * written by an imaginary doctor. One actor is reused across the file because
 * this engine never reads the actor — it correlates on credential and device.
 */
function lpActor(): User
{
    // No static cache: RefreshDatabase hands each test a fresh database, and a
    // memoised actor would point at a row that no longer exists.
    return User::query()->first() ?? User::factory()->create();
}

function lpDevice(string $status = DoctorDevice::STATUS_ACTIVE): DoctorDevice
{
    return DoctorDevice::factory()->create([
        'status' => $status,
    ]);
}

function lpCredential(
    DoctorDevice $device,
    string $verdict = DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND,
    ?string $revokedAt = null,
): DoctorDeviceWebAuthnCredential {
    $credential = new DoctorDeviceWebAuthnCredential;

    $credential->forceFill([
        'uuid' => (string) Str::uuid(),
        'doctor_device_id' => $device->id,
        'credential_id' => 'cred-'.Str::random(24),
        'public_key' => 'not-a-real-key-and-never-read-by-this-engine',
        'signature_counter' => 0,
        'user_verified' => true,
        'backup_eligible' => false,
        'device_bound_verdict' => $verdict,
        'registered_at' => lpNow()->subDays(30),
        'revoked_at' => $revokedAt,
    ])->save();

    return $credential->refresh();
}

/**
 * A server-side WebAuthn success exactly as DoctorDeviceWebAuthnLoginService
 * writes it: entity is the credential, the device is in new_values.
 */
function lpWebAuthnProof(
    DoctorDeviceWebAuthnCredential $credential,
    DoctorDevice $device,
    string $at,
): AuditLog {
    $log = new AuditLog;

    $log->forceFill([
        'entity_type' => 'trx_doctor_device_webauthn_credentials',
        'entity_id' => $credential->id,
        'action' => DoctorWebAuthnLiveProofRepositoryInterface::WEBAUTHN_PROOF_ACTION,
        'new_values' => [
            'doctor_device_id' => $device->id,
            'doctor_id' => 21,
            'authorization_id' => 1,
        ],
        'performed_by' => lpActor()->id,
        'performed_at' => CarbonImmutable::parse($at, 'UTC'),
        'created_at' => CarbonImmutable::parse($at, 'UTC'),
    ])->save();

    return $log;
}

function lpReport(): array
{
    return app(DoctorWebAuthnLiveProofService::class)->report(lpNow());
}

// ---------------------------------------------------------------------------
// §33 / §41 — rows exist, nothing was ever proved
// ---------------------------------------------------------------------------

it('refuses READY when the flag is armed and the rows exist but nothing ever asserted', function () {
    $device = lpDevice();
    lpCredential($device);

    $report = lpReport();

    // Everything the OLD engine looked at is present and healthy.
    expect($report['population'])->toBe(1)
        ->and($report['devices'][0]['credentials_usable'])->toBe(1);

    // And it is still not ready, because nobody has shown the leg works.
    expect($report['live_assertion_proof'])->toBe(DoctorWebAuthnLiveProofService::PROOF_NEVER_PROVEN)
        ->and($report['effective_readiness'])->toBe(DoctorWebAuthnLiveProofService::READINESS_NOT_READY)
        ->and($report['devices'][0]['reason'])->toBe('no_qualifying_assertion');
});

// ---------------------------------------------------------------------------
// §34 — a real proof that has gone quiet
// ---------------------------------------------------------------------------

it('reports STALE, not PASS, for a proof outside the freshness window', function () {
    $device = lpDevice();
    $credential = lpCredential($device);

    // The real outage shape: last assertion 2026-09-09, measured 2026-09-19.
    lpWebAuthnProof($credential, $device, '2026-09-09 14:49:04');

    $report = lpReport();

    expect($report['live_assertion_proof'])->toBe(DoctorWebAuthnLiveProofService::PROOF_STALE)
        ->and($report['proof_freshness'])->toBe(DoctorWebAuthnLiveProofService::FRESHNESS_STALE)
        ->and($report['effective_readiness'])->toBe(DoctorWebAuthnLiveProofService::READINESS_NOT_READY)
        ->and($report['devices'][0]['reason'])->toBe('proof_older_than_freshness_window')
        // Fractional, so 9.4 days does not read as 9 and sit one rounding away
        // from the window it has already left.
        ->and($report['devices'][0]['proof_age_days'])->toBeGreaterThan(9.0);
});

it('holds a proof exactly on the window boundary as fresh, and one second past it as stale', function () {
    $onBoundary = lpDevice();
    lpWebAuthnProof(lpCredential($onBoundary), $onBoundary, lpNow()->subDays(7)->toDateTimeString());

    expect(lpReport()['live_assertion_proof'])
        ->toBe(DoctorWebAuthnLiveProofService::PROOF_PASS);

    DoctorDevice::query()->update(['status' => DoctorDevice::STATUS_REVOKED]);

    $pastIt = lpDevice();
    lpWebAuthnProof(lpCredential($pastIt), $pastIt, lpNow()->subDays(7)->subSecond()->toDateTimeString());

    expect(lpReport()['live_assertion_proof'])
        ->toBe(DoctorWebAuthnLiveProofService::PROOF_STALE);
});

// ---------------------------------------------------------------------------
// §35 / §36 / §37 — the proof was real; the path underneath is gone
// ---------------------------------------------------------------------------

it('does not let a revoked device stay green on a fresh proof', function () {
    $device = lpDevice();
    $credential = lpCredential($device);
    lpWebAuthnProof($credential, $device, lpNow()->subHour()->toDateTimeString());

    // It asserted an hour ago, and then the tablet was withdrawn.
    $device->forceFill(['status' => DoctorDevice::STATUS_REVOKED])->save();

    $report = lpReport();

    // The estate now has nothing to measure, which is UNVERIFIED and is
    // emphatically not READY. A revoked device is out of the population, not
    // passing within it.
    expect($report['population'])->toBe(0)
        ->and($report['effective_readiness'])->not->toBe(DoctorWebAuthnLiveProofService::READINESS_READY)
        ->and($report['unverified_reason'])->toBe('no_active_devices_to_measure');
});

it('does not let a revoked credential stay green on its own historical proof', function () {
    $device = lpDevice();
    $credential = lpCredential($device);
    lpWebAuthnProof($credential, $device, lpNow()->subHour()->toDateTimeString());

    // Production holds exactly this shape: credential 1 on device 3 is revoked
    // and keeps two success rows.
    $credential->forceFill(['revoked_at' => lpNow()->subMinutes(5)])->save();

    $report = lpReport();

    expect($report['devices'][0]['credentials_usable'])->toBe(0)
        ->and($report['devices'][0]['reason'])->toBe('no_usable_credential')
        ->and($report['live_assertion_proof'])->toBe(DoctorWebAuthnLiveProofService::PROOF_NEVER_PROVEN)
        ->and($report['effective_readiness'])->toBe(DoctorWebAuthnLiveProofService::READINESS_NOT_READY);
});

it('does not let a credential whose binding is no longer acceptable stay green', function () {
    $device = lpDevice();
    // Admitted while the policy was loose; the policy is tight now.
    $credential = lpCredential($device, DoctorDeviceWebAuthnCredential::VERDICT_BACKUP_ELIGIBLE);
    lpWebAuthnProof($credential, $device, lpNow()->subHour()->toDateTimeString());

    $report = lpReport();

    expect($report['devices'][0]['credentials_usable'])->toBe(0)
        ->and($report['effective_readiness'])->toBe(DoctorWebAuthnLiveProofService::READINESS_NOT_READY);
});

it('treats an unknown binding verdict as unusable rather than as a pass', function () {
    $device = lpDevice();
    $credential = lpCredential($device, DoctorDeviceWebAuthnCredential::VERDICT_UNKNOWN);
    lpWebAuthnProof($credential, $device, lpNow()->subHour()->toDateTimeString());

    // "We could not tell" is not "device-bound".
    expect(lpReport()['effective_readiness'])
        ->toBe(DoctorWebAuthnLiveProofService::READINESS_NOT_READY);
});

// ---------------------------------------------------------------------------
// §38 — the whole reason this defect hid for ten days
// ---------------------------------------------------------------------------

it('refuses to accept an Android Keystore login as WebAuthn liveness', function () {
    $device = lpDevice();
    $credential = lpCredential($device);

    // A real, verified, server-side doctor login — through the OTHER mechanism.
    $log = new AuditLog;
    $log->forceFill([
        'entity_type' => 'trx_doctor_device_webauthn_credentials',
        'entity_id' => $credential->id,
        'action' => 'DOCTOR_APP_LOGIN_AUTHORIZATION_SUCCESS',
        'new_values' => ['doctor_device_id' => $device->id, 'doctor_id' => 21],
        'performed_by' => lpActor()->id,
        'performed_at' => lpNow()->subMinutes(2),
        'created_at' => lpNow()->subMinutes(2),
    ])->save();

    $report = lpReport();

    // Android working says nothing about the browser leg. That was true for the
    // full ten days of the outage and it is true here.
    expect($report['live_assertion_proof'])->toBe(DoctorWebAuthnLiveProofService::PROOF_NEVER_PROVEN)
        ->and($report['effective_readiness'])->toBe(DoctorWebAuthnLiveProofService::READINESS_NOT_READY);
});

// ---------------------------------------------------------------------------
// §39 — a proof belongs where it was made
// ---------------------------------------------------------------------------

it('does not let a proof recorded against one device prove another', function () {
    $proven = lpDevice();
    $unproven = lpDevice();

    $credential = lpCredential($unproven);

    // The credential lives on $unproven, but the assertion was recorded against
    // $proven. A credential is bound to one device, so this should be
    // impossible — which is why it is asserted rather than assumed.
    lpWebAuthnProof($credential, $proven, lpNow()->subHour()->toDateTimeString());

    $report = lpReport();

    $rows = collect($report['devices'])->keyBy('device_id');

    expect($rows[$unproven->id]['live_assertion_proof'])
        ->toBe(DoctorWebAuthnLiveProofService::PROOF_NEVER_PROVEN)
        ->and($report['effective_readiness'])
        ->toBe(DoctorWebAuthnLiveProofService::READINESS_NOT_READY);
});

// ---------------------------------------------------------------------------
// §40 — a measurement that failed is not a measurement that passed
// ---------------------------------------------------------------------------

it('reports UNVERIFIED when the proof query throws', function () {
    $device = lpDevice();
    lpCredential($device);

    app()->bind(DoctorWebAuthnLiveProofRepositoryInterface::class, fn () => new class implements DoctorWebAuthnLiveProofRepositoryInterface
    {
        public function latestWebAuthnProofForCredentials(array $credentialIds): Collection
        {
            throw new RuntimeException('audit trail unavailable');
        }
    });

    $report = lpReport();

    expect($report['live_assertion_proof'])->toBe(DoctorWebAuthnLiveProofService::PROOF_UNVERIFIED)
        ->and($report['proof_freshness'])->toBe(DoctorWebAuthnLiveProofService::FRESHNESS_UNVERIFIED)
        ->and($report['effective_readiness'])->toBe(DoctorWebAuthnLiveProofService::READINESS_UNVERIFIED)
        ->and($report['unverified_reason'])->toBe('proof_query_failed');
});

it('reports UNVERIFIED rather than READY when there is nothing to measure', function () {
    expect(lpReport()['effective_readiness'])
        ->toBe(DoctorWebAuthnLiveProofService::READINESS_UNVERIFIED);
});

it('reports UNVERIFIED when no freshness policy is configured, rather than passing', function () {
    config()->set('doctor_webauthn_live_proof.freshness_window_days', null);

    $device = lpDevice();
    $credential = lpCredential($device);
    lpWebAuthnProof($credential, $device, lpNow()->subMinute()->toDateTimeString());

    $report = lpReport();

    // A proof one minute old, and still not a pass — because nobody said how
    // fresh is fresh. Being untold is not being reassured.
    expect($report['freshness_window_days'])->toBeNull()
        ->and($report['live_assertion_proof'])->toBe(DoctorWebAuthnLiveProofService::PROOF_UNVERIFIED)
        ->and($report['devices'][0]['reason'])->toBe('no_freshness_policy_configured')
        /*
         * The ROLL-UP, asserted separately and deliberately.
         *
         * Mutation found this: flipping the roll-up's UNVERIFIED arm to READY
         * survived the whole suite, because every other UNVERIFIED test reaches
         * the early-return helper rather than the match. A device-level
         * UNVERIFIED must carry all the way to the estate verdict, or "I could
         * not tell" silently becomes "ready" one level up — which is this
         * programme's defect wearing a different hat.
         */
        ->and($report['proof_freshness'])->toBe(DoctorWebAuthnLiveProofService::FRESHNESS_UNVERIFIED)
        ->and($report['effective_readiness'])->toBe(DoctorWebAuthnLiveProofService::READINESS_UNVERIFIED);
});

it('carries a device-level UNVERIFIED up to the estate verdict, outranking a fresh sibling', function () {
    /*
     * `sys_audit_logs.performed_at` is NOT NULL, so the trail itself cannot
     * hold an undateable row and the service's unparseable-timestamp branch is
     * defensive rather than reachable through the repository. It is still worth
     * holding, because the property under test is the ROLL-UP ORDER, and
     * mutation proved that order was unasserted: flipping the UNVERIFIED arm to
     * READY survived the entire suite.
     *
     * A stub repository is the honest way to reach it — it exercises the
     * service's contract with its collaborator rather than pretending the
     * database can produce a row it cannot.
     */
    $fresh = lpDevice();
    $freshCredential = lpCredential($fresh);

    $unreadable = lpDevice();
    $unreadableCredential = lpCredential($unreadable);

    app()->bind(
        DoctorWebAuthnLiveProofRepositoryInterface::class,
        fn () => new class($fresh->id, $freshCredential->id, $unreadable->id, $unreadableCredential->id) implements DoctorWebAuthnLiveProofRepositoryInterface
        {
            public function __construct(
                private int $freshDevice,
                private int $freshCredential,
                private int $badDevice,
                private int $badCredential,
            ) {}

            public function latestWebAuthnProofForCredentials(array $credentialIds): Collection
            {
                return collect([
                    $this->freshCredential => [
                        'count' => 1,
                        'last_at_by_device' => [$this->freshDevice => '2026-09-19 11:55:00'],
                    ],
                    $this->badCredential => [
                        'count' => 1,
                        'last_at_by_device' => [$this->badDevice => 'not-a-timestamp'],
                    ],
                ]);
            }
        }
    );

    $report = lpReport();

    $rows = collect($report['devices'])->keyBy('device_id');

    // The fresh one is genuinely fresh...
    expect($rows[$fresh->id]['live_assertion_proof'])
        ->toBe(DoctorWebAuthnLiveProofService::PROOF_PASS);

    // ...and the undateable one is neither dropped nor treated as fresh.
    expect($rows[$unreadable->id]['live_assertion_proof'])
        ->toBe(DoctorWebAuthnLiveProofService::PROOF_UNVERIFIED)
        ->and($rows[$unreadable->id]['reason'])->toBe('proof_timestamp_unparseable');

    // UNVERIFIED outranks the fresh sibling. A report containing something we
    // could not measure is not a report that the estate is ready.
    expect($report['live_assertion_proof'])->toBe(DoctorWebAuthnLiveProofService::PROOF_UNVERIFIED)
        ->and($report['effective_readiness'])->toBe(DoctorWebAuthnLiveProofService::READINESS_UNVERIFIED);
});

// ---------------------------------------------------------------------------
// §42 — the one path that may go green
// ---------------------------------------------------------------------------

it('reports PASS for an eligible device whose usable credential asserted inside the window', function () {
    $device = lpDevice();
    $credential = lpCredential($device);
    lpWebAuthnProof($credential, $device, '2026-09-19 00:53:04');

    $report = lpReport();

    expect($report['live_assertion_proof'])->toBe(DoctorWebAuthnLiveProofService::PROOF_PASS)
        ->and($report['proof_freshness'])->toBe(DoctorWebAuthnLiveProofService::FRESHNESS_FRESH)
        ->and($report['effective_readiness'])->toBe(DoctorWebAuthnLiveProofService::READINESS_READY)
        ->and($report['scope_coverage'])->toContain('1/1')
        ->and($report['proof_source'])->toContain('DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS');

    // §49 — UTC is the authority and clinic-local is always labelled, so an
    // unlabelled timestamp can never be mistaken for local time.
    expect($report['last_qualifying_proof_utc'])->toContain('UTC')
        ->and($report['last_qualifying_proof_local'])->toContain('WITA (UTC+8)')
        // 00:53:04 UTC is 08:53:04 WITA. If this ever reads 00:53 with a WITA
        // label, the offset silently stopped being applied.
        ->and($report['last_qualifying_proof_local'])->toContain('08:53:04');
});

it('requires EVERY active device to be fresh, so one proven tablet cannot carry the estate', function () {
    $fresh = lpDevice();
    lpWebAuthnProof(lpCredential($fresh), $fresh, '2026-09-19 00:53:04');

    $stale = lpDevice();
    lpWebAuthnProof(lpCredential($stale), $stale, '2026-09-09 14:49:04');

    $never = lpDevice();
    lpCredential($never);

    $report = lpReport();

    // This is production's actual shape, and the honest answer is not ready.
    expect($report['population'])->toBe(3)
        ->and($report['scope_coverage'])->toContain('1/3')
        ->and($report['live_assertion_proof'])->toBe(DoctorWebAuthnLiveProofService::PROOF_NEVER_PROVEN)
        ->and($report['effective_readiness'])->toBe(DoctorWebAuthnLiveProofService::READINESS_NOT_READY);
});

// ---------------------------------------------------------------------------
// The estate privacy contract, restated for the new dimension
// ---------------------------------------------------------------------------

it('reports device ids and never device names, credential ids or key material', function () {
    $device = lpDevice();
    $credential = lpCredential($device);
    lpWebAuthnProof($credential, $device, '2026-09-19 00:53:04');

    $raw = json_encode(lpReport());

    // The command this feeds has always held that the device estate is not
    // something a console report enumerates. Adding a liveness dimension must
    // not quietly widen what it discloses — an earlier draft reported
    // device_name and a sibling test caught it.
    expect($raw)->not->toContain($device->device_name)
        ->and($raw)->not->toContain($credential->credential_id)
        ->and($raw)->not->toContain($credential->public_key)
        // The id is what an operator acts on, and it is not sensitive.
        ->and($raw)->toContain('"device_id":'.$device->id);
});

// ---------------------------------------------------------------------------
// Found by adversarial review: a credential with history on TWO devices
// ---------------------------------------------------------------------------

it('never lets a device borrow a fresher assertion performed on a different device', function () {
    /*
     * THE DEFECT THIS PINS.
     *
     * The first implementation returned ONE `last_at` per credential (the max
     * across every row) beside a flat `device_ids` union, and the service
     * checked membership. A credential whose history spans two devices then
     * passed the membership test for BOTH and used whichever timestamp was
     * newest — so this device could report PASS on an assertion performed
     * somewhere else, while its own newest assertion was long stale.
     *
     * It is reachable only when a credential's `doctor_device_id` changed over
     * its life: a device swap, a data fix, or the "migration that quietly
     * re-pointed a row" the correlation was written to catch. The check built
     * for the abnormal case failed open precisely on it.
     *
     * The repository now keys timestamps BY DEVICE, so the borrow is
     * unrepresentable rather than merely checked.
     */
    $subject = lpDevice();
    $elsewhere = lpDevice();

    $credential = lpCredential($subject);

    // This device's own newest assertion is long past the window...
    lpWebAuthnProof($credential, $subject, '2026-09-01 08:00:00');
    // ...and the same credential has a very recent assertion on another device.
    lpWebAuthnProof($credential, $elsewhere, lpNow()->subMinutes(10)->toDateTimeString());

    $rows = collect(lpReport()['devices'])->keyBy('device_id');

    // The subject is judged on ITS OWN history, which is stale.
    expect($rows[$subject->id]['live_assertion_proof'])
        ->toBe(DoctorWebAuthnLiveProofService::PROOF_STALE)
        ->and($rows[$subject->id]['proof_age_days'])->toBeGreaterThan(7.0);

    /*
     * And the other device does not inherit it either, for a separate and
     * equally correct reason: the credential lives on the subject, so
     * `$elsewhere` holds none of its own and cannot serve a ceremony at all.
     * Its fresh-looking history is the residue of the re-pointing.
     *
     * Two independent filters therefore refuse the borrow — per-device keying,
     * and the usable-credential gate — and neither is load-bearing alone.
     */
    expect($rows[$elsewhere->id]['live_assertion_proof'])
        ->toBe(DoctorWebAuthnLiveProofService::PROOF_NEVER_PROVEN)
        ->and($rows[$elsewhere->id]['reason'])->toBe('no_usable_credential');

    // The estate is therefore NOT ready, which is the truthful answer.
    expect(lpReport()['effective_readiness'])
        ->toBe(DoctorWebAuthnLiveProofService::READINESS_NOT_READY);
});

it('ignores an assertion row that does not say which device it happened on', function () {
    $device = lpDevice();
    $credential = lpCredential($device);

    $proof = lpWebAuthnProof($credential, $device, lpNow()->subMinutes(5)->toDateTimeString());
    // A payload with no device cannot prove any device, and must not be folded
    // into a neighbour's timestamp or invent a key of its own.
    $proof->forceFill(['new_values' => ['doctor_id' => 21]])->save();

    $report = lpReport();

    expect($report['devices'][0]['live_assertion_proof'])
        ->toBe(DoctorWebAuthnLiveProofService::PROOF_NEVER_PROVEN)
        ->and($report['devices'][0]['reason'])->toBe('no_qualifying_assertion')
        ->and($report['effective_readiness'])->toBe(DoctorWebAuthnLiveProofService::READINESS_NOT_READY);
});

// ---------------------------------------------------------------------------
// Found by adversarial review: fail-open holes behind the "always UNVERIFIED"
// contract, and a guarantee that turned out to be env-conditional
// ---------------------------------------------------------------------------

it('answers UNVERIFIED, not READY, when the roll-up itself is handed nobody', function () {
    /*
     * rollUp() used to fall through to `default => PASS` on an empty status
     * list, so it answered READY to a population of nobody. report() guards
     * against reaching it empty — but a guard in a DIFFERENT method is how a
     * false green survives a refactor, and this is the same empty-population
     * defect the programme already shipped once, one level down.
     *
     * Reached through the public surface by making the estate read return
     * nothing, which is the only way a caller can get there.
     */
    expect(lpReport()['effective_readiness'])
        ->toBe(DoctorWebAuthnLiveProofService::READINESS_UNVERIFIED);

    // And directly, because the arm must hold on its own rather than because
    // the caller happened not to call it.
    $rollUp = new ReflectionMethod(DoctorWebAuthnLiveProofService::class, 'rollUp');
    $rollUp->setAccessible(true);

    $empty = $rollUp->invoke(app(DoctorWebAuthnLiveProofService::class), [], 7, lpNow());

    expect($empty['live_assertion_proof'])->toBe(DoctorWebAuthnLiveProofService::PROOF_UNVERIFIED)
        ->and($empty['effective_readiness'])->toBe(DoctorWebAuthnLiveProofService::READINESS_UNVERIFIED);
});

it('survives an unusable display timezone instead of throwing out of the report', function () {
    // One `s`. A plausible typo in an environment value, and before this guard
    // it threw straight past both try blocks and out of report().
    config()->set('doctor_webauthn_live_proof.display_timezone', 'Asia/Makasar');

    $device = lpDevice();
    lpWebAuthnProof(lpCredential($device), $device, '2026-09-19 00:53:04');

    $report = lpReport();

    // The measurement still lands, and the clinic-local rendering degrades
    // honestly rather than silently claiming a local time it could not compute.
    expect($report['live_assertion_proof'])->toBe(DoctorWebAuthnLiveProofService::PROOF_PASS)
        ->and($report['last_qualifying_proof_utc'])->toContain('UTC')
        ->and($report['last_qualifying_proof_local'])->toContain('display timezone unusable');
});

it('refuses a proof dated in the future rather than treating it as permanently fresh', function () {
    $device = lpDevice();
    // Writer-host clock skew, or a backfilled audit row. The freshness test was
    // one-sided, so this read FRESH forever and could never age out.
    lpWebAuthnProof(lpCredential($device), $device, lpNow()->addDays(3)->toDateTimeString());

    $report = lpReport();

    expect($report['live_assertion_proof'])->toBe(DoctorWebAuthnLiveProofService::PROOF_UNVERIFIED)
        ->and($report['devices'][0]['reason'])->toBe('proof_dated_in_the_future')
        ->and($report['effective_readiness'])->toBe(DoctorWebAuthnLiveProofService::READINESS_UNVERIFIED);
});

it('does not read a blank timestamp from a collaborator as "now"', function () {
    /*
     * CarbonImmutable::parse('') returns NOW and does not throw, so a
     * contract-violating repository handing back an empty value would mark
     * every device freshly proven and the catch would never fire. The shipped
     * repository cannot produce that — but this service depends on the
     * INTERFACE, and a fail-closed guarantee resting on a collaborator's good
     * behaviour is not a guarantee.
     */
    $device = lpDevice();
    $credential = lpCredential($device);

    app()->bind(
        DoctorWebAuthnLiveProofRepositoryInterface::class,
        fn () => new class($device->id, $credential->id) implements DoctorWebAuthnLiveProofRepositoryInterface
        {
            public function __construct(private int $deviceId, private int $credentialId) {}

            public function latestWebAuthnProofForCredentials(array $credentialIds): Collection
            {
                return collect([
                    $this->credentialId => [
                        'count' => 1,
                        'last_at_by_device' => [$this->deviceId => ''],
                    ],
                ]);
            }
        }
    );

    $report = lpReport();

    expect($report['live_assertion_proof'])->toBe(DoctorWebAuthnLiveProofService::PROOF_UNVERIFIED)
        ->and($report['devices'][0]['reason'])->toBe('proof_timestamp_unparseable')
        ->and($report['effective_readiness'])->not->toBe(DoctorWebAuthnLiveProofService::READINESS_READY);
});

it('mirrors the deployment binding policy when device binding is NOT required', function () {
    /*
     * A RECORDED DECISION, NOT AN OVERSIGHT.
     *
     * Every other binding test pins require_device_bound = true in beforeEach,
     * so they assert the POLICY rather than the engine — adversarial review
     * pointed out that the guarantee was env-conditional and invisible to the
     * suite. This is the missing case.
     *
     * With the policy relaxed, WebAuthnDeviceBinding::isAcceptable() returns
     * true for every verdict, and a syncable credential's assertion counts as
     * live proof. That is intended: if the deployment would let that credential
     * log a doctor in, its successful assertion IS evidence the browser leg
     * works, and reporting NOT_READY while logins succeed would be lying in the
     * other direction. Production runs the policy at true.
     */
    config()->set('webauthn.device_binding.require_device_bound', false);

    $device = lpDevice();
    $credential = lpCredential($device, DoctorDeviceWebAuthnCredential::VERDICT_BACKUP_ELIGIBLE);
    lpWebAuthnProof($credential, $device, '2026-09-19 00:53:04');

    expect(lpReport()['effective_readiness'])
        ->toBe(DoctorWebAuthnLiveProofService::READINESS_READY);

    // Tighten the policy and the SAME credential stops counting, on the same
    // data — which is the direction that actually protects the estate.
    config()->set('webauthn.device_binding.require_device_bound', true);

    expect(lpReport()['effective_readiness'])
        ->toBe(DoctorWebAuthnLiveProofService::READINESS_NOT_READY);
});

// ---------------------------------------------------------------------------
// Found by the FIRST PRODUCTION MEASUREMENT: the command's own key mapping
// ---------------------------------------------------------------------------

it('does not claim the live-proof report failed when it succeeded', function () {
    /*
     * THE BUG THIS PINS, AND WHY THE SUITE MISSED IT.
     *
     * The command mapped `$proof['unverified_reason'] ?? 'live_proof_report_failed'`.
     * The service returns null there on the SUCCESS path, and `??` treats null
     * as absent — so the first deployed build printed
     * `unverified_reason=live_proof_report_failed` on every healthy run, beside
     * four correctly measured devices. The report's entire purpose is to stop
     * asserting things it cannot support, and it was asserting its own failure.
     *
     * Every existing test exercised the SERVICE. Nothing asserted the shape the
     * COMMAND emits, which is the thing an operator actually reads — so a
     * defect living purely in the key mapping was invisible to a green suite.
     */
    $device = lpDevice();
    lpWebAuthnProof(lpCredential($device), $device, '2026-09-19 00:53:04');

    $exit = Artisan::call('webauthn:readiness', ['--json' => true]);
    $json = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        // A healthy report says nothing was unverifiable.
        ->and($json['unverified_reason'])->toBeNull()
        // And it is a real measurement, not an empty fallback.
        ->and($json['live_assertion_proof'])->toBe(DoctorWebAuthnLiveProofService::PROOF_PASS)
        ->and($json['proof_population'])->toBe(1)
        ->and($json['devices'])->toHaveCount(1);
});

it('surfaces the ENGINE\'s own unverified reason rather than substituting its own', function () {
    /*
     * The mirror of the bug above. When the engine legitimately cannot measure,
     * the command must report WHY the engine said so — not overwrite it with a
     * generic "the report failed", which would describe a different fault and
     * send an operator looking in the wrong place.
     *
     * No devices exist here, so the engine returns its own
     * `no_active_devices_to_measure`.
     */
    $exit = Artisan::call('webauthn:readiness', ['--json' => true]);
    $json = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($json['unverified_reason'])->toBe('no_active_devices_to_measure')
        ->and($json['effective_readiness'])->toBe(DoctorWebAuthnLiveProofService::READINESS_UNVERIFIED)
        ->and($json['devices'])->toBe([]);
});

it('exits non-zero under --require-live-proof while the estate is unproven', function () {
    // Production's actual shape: one fresh tablet, one never proven.
    $fresh = lpDevice();
    lpWebAuthnProof(lpCredential($fresh), $fresh, '2026-09-19 00:53:04');
    lpCredential(lpDevice());

    // The default invocation still answers the OLD question and stays 0, so a
    // caller that asked about the relying party is not broken by liveness.
    expect(Artisan::call('webauthn:readiness'))->toBe(0);

    // Opting in to liveness fails, because 1 of 2 is not ready.
    expect(Artisan::call('webauthn:readiness', ['--require-live-proof' => true]))->toBe(1);
});
