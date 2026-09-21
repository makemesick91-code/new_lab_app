<?php

/**
 * DOCTOR-DEVICE-GUIDED-REGISTRATION-WORKFLOW-1 — steps 5 and 6, and the false
 * greens they exist to refuse.
 *
 * THIS IS THE FILE THAT MATTERS. A registration wizard's last screen says
 * "READY FOR CLINICAL USE", and an operator acts on that sentence without
 * re-reading the checklist behind it. Every way that sentence could appear
 * over an unusable tablet is a bug worth more than the feature.
 *
 * The three that have actually shipped in this codebase before, and are
 * therefore asserted rather than assumed:
 *
 *   - a PASS produced by a control instead of by an event (§10);
 *   - a PASS produced by somebody ELSE's evidence (§12 — a proof performed on
 *     a different tablet);
 *   - a green produced by a short-circuit, where revoking one thing reports
 *     the failure of another (§14-16 — each revocation must fail its OWN gate
 *     and leave the others alone, or the operator is sent to fix the wrong
 *     page).
 *
 * The fixtures build a genuinely COMPLETE tablet first and then break exactly
 * one thing per test, so a gate that fails for an unrelated reason shows up as
 * a failing expectation instead of hiding inside an already-red checklist.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Interfaces\DoctorWebAuthnLiveProofRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorDeviceRegistrationReadinessService as Readiness;
use App\Modules\DoctorDevice\Services\DoctorDeviceWebAuthnLoginService;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

beforeEach(function () {
    seedAccessControl();

    config()->set('webauthn.device_binding.require_device_bound', true);
    config()->set('doctor_webauthn_live_proof.freshness_window_days', 7);

    // Browser login armed, matching the production posture. With the estate
    // master switch OFF the login gate refuses every correctly provisioned
    // tablet, so leaving it off here would make each test below pass for a
    // reason that has nothing to do with what it is testing.
    //
    // The whole flags array is rewritten rather than reached into with a dotted
    // key, because a dotted `config()->set` builds a nested structure
    // FeatureFlagService never reads.
    $flags = config('feature_flags.flags', []);
    $flags[DoctorDeviceWebAuthnLoginService::FLAG]['default'] = true;
    $flags[DoctorDeviceWebAuthnLoginService::FLAG]['env_value'] = true;
    config()->set('feature_flags.flags', $flags);

    $this->branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
});

function rrEvaluate(DoctorDevice $device): array
{
    return app(Readiness::class)->evaluate($device->fresh());
}

/** The status of one named gate, so a test can name the gate it is about. */
function rrGate(array $report, string $key): string
{
    foreach ($report['gates'] as $gate) {
        if ($gate['key'] === $key) {
            return (string) $gate['status'];
        }
    }

    throw new RuntimeException("Gate {$key} is not in the report — the checklist lost a gate.");
}

function rrDevice(string $status = DoctorDevice::STATUS_ACTIVE): DoctorDevice
{
    return DoctorDevice::factory()->create([
        'branch_id' => test()->branch->id,
        'status' => $status,
    ]);
}

function rrCredential(
    DoctorDevice $device,
    string $verdict = DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND,
    bool $userVerified = true,
    ?string $revokedAt = null,
): DoctorDeviceWebAuthnCredential {
    $credential = new DoctorDeviceWebAuthnCredential;

    $credential->forceFill([
        'uuid' => (string) Str::uuid(),
        'doctor_device_id' => $device->id,
        'credential_id' => 'cred-'.Str::random(24),
        'public_key' => 'not-a-real-key',
        'signature_counter' => 0,
        'user_verified' => $userVerified,
        'backup_eligible' => false,
        'device_bound_verdict' => $verdict,
        'registered_at' => now()->subDays(30),
        'revoked_at' => $revokedAt,
    ])->save();

    return $credential->refresh();
}

function rrAuthorization(DoctorDevice $device, string $status = DoctorDeviceAuthorization::STATUS_ACTIVE): DoctorDeviceAuthorization
{
    $account = User::factory()->create(['is_active' => true]);
    $account->assignRole('Doctor');

    $doctor = Doctor::factory()->create(['user_id' => $account->id, 'is_active' => true]);

    return DoctorDeviceAuthorization::factory()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
        'status' => $status,
        'approved_at' => $status === DoctorDeviceAuthorization::STATUS_ACTIVE ? now() : null,
        'revoked_at' => $status === DoctorDeviceAuthorization::STATUS_REVOKED ? now() : null,
    ]);
}

/**
 * A server-side WebAuthn success exactly as DoctorDeviceWebAuthnLoginService
 * writes it: the entity is the CREDENTIAL and the device is in new_values.
 * Nothing in this file writes a "test passed" flag, because no such flag
 * exists.
 */
function rrProof(DoctorDeviceWebAuthnCredential $credential, DoctorDevice $device, string $at): AuditLog
{
    $log = new AuditLog;

    $log->forceFill([
        'entity_type' => 'trx_doctor_device_webauthn_credentials',
        'entity_id' => $credential->id,
        'action' => DoctorWebAuthnLiveProofRepositoryInterface::WEBAUTHN_PROOF_ACTION,
        'new_values' => ['doctor_device_id' => $device->id],
        'performed_by' => User::query()->first()?->id ?? User::factory()->create()->id,
        'performed_at' => CarbonImmutable::parse($at, 'UTC'),
        'created_at' => CarbonImmutable::parse($at, 'UTC'),
    ])->save();

    return $log;
}

/** @return array{device:DoctorDevice,credential:DoctorDeviceWebAuthnCredential,authorization:DoctorDeviceAuthorization} */
function rrReadyTablet(): array
{
    $device = rrDevice();
    $credential = rrCredential($device);
    $authorization = rrAuthorization($device);

    rrProof($credential, $device, now()->subDay()->toIso8601String());

    return compact('device', 'credential', 'authorization');
}

// ---------------------------------------------------------------------------
// The baseline: a complete tablet really does read READY
// ---------------------------------------------------------------------------

it('reports READY for a tablet that is genuinely complete', function () {
    $report = rrEvaluate(rrReadyTablet()['device']);

    expect($report['verdict'])->toBe(Readiness::READY)
        ->and($report['failed_gates'])->toBe([]);
});

// ---------------------------------------------------------------------------
// 10 — there is no control that can declare step 5 passed
// ---------------------------------------------------------------------------

it('exposes no route that could record a successful login test', function () {
    $named = collect(app('router')->getRoutes())
        ->map(fn ($route): string => (string) $route->getName())
        ->filter()
        ->values();

    // The workflow's ONLY write is step 4. Nothing may exist that marks a
    // login test, a readiness check or a completion.
    $writes = $named
        ->filter(fn (string $name): bool => str_starts_with($name, 'settings.doctor-device-registration.'))
        ->filter(fn (string $name): bool => collect(app('router')->getRoutes())
            ->first(fn ($r) => $r->getName() === $name)
            ->methods() !== ['GET', 'HEAD']);

    expect($writes->values()->all())->toBe(['settings.doctor-device-registration.doctors.store']);
});

it('refuses a forged POST to the login-test step', function () {
    $device = rrDevice();
    $operator = User::factory()->create();
    $operator->assignRole('Super Admin');

    test()->actingAs($operator->fresh())
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('settings.doctor-device-registration.login-test', $device))
        ->assertStatus(405);
});

it('keeps the login test failing when no assertion was ever performed', function () {
    $device = rrDevice();
    rrCredential($device);
    rrAuthorization($device);

    // Rows exist, provisioning is complete, and nobody has ever logged in.
    $report = rrEvaluate($device);

    expect(rrGate($report, Readiness::GATE_LOGIN_PROOF))->not->toBe(Readiness::GATE_PASS)
        ->and($report['verdict'])->toBe(Readiness::NOT_READY);
});

// ---------------------------------------------------------------------------
// 11 / 12 — real evidence passes; somebody else's evidence does not
// ---------------------------------------------------------------------------

it('accepts a real, fresh WebAuthn assertion performed on this device', function () {
    $fixture = rrReadyTablet();

    expect(rrGate(rrEvaluate($fixture['device']), Readiness::GATE_LOGIN_PROOF))->toBe(Readiness::GATE_PASS);
});

it('does not let a proof performed on ANOTHER tablet satisfy this one', function () {
    // Two devices exist on purpose. With a single-device fixture this test
    // passes even when the correlation is wrong, which is exactly how a
    // borrowed-proof defect survives a suite that looks thorough.
    $other = rrDevice();
    $otherCredential = rrCredential($other);
    rrAuthorization($other);
    rrProof($otherCredential, $other, now()->subHour()->toIso8601String());

    $subject = rrDevice();
    rrCredential($subject);
    rrAuthorization($subject);

    $report = rrEvaluate($subject);

    expect(rrGate($report, Readiness::GATE_LOGIN_PROOF))->not->toBe(Readiness::GATE_PASS)
        ->and($report['verdict'])->toBe(Readiness::NOT_READY);

    // ...while the tablet that DID prove itself still reads READY, so the
    // assertion above is about correlation and not about a broken engine.
    expect(rrEvaluate($other)['verdict'])->toBe(Readiness::READY);
});

it('does not accept a credential row re-pointed at this device as its own proof', function () {
    $other = rrDevice();
    $otherCredential = rrCredential($other);
    rrProof($otherCredential, $other, now()->subHour()->toIso8601String());

    $subject = rrDevice();
    rrAuthorization($subject);

    // The credential moves, but the audit row still records the device the
    // assertion actually happened on.
    $otherCredential->forceFill(['doctor_device_id' => $subject->id])->save();

    expect(rrGate(rrEvaluate($subject), Readiness::GATE_LOGIN_PROOF))->not->toBe(Readiness::GATE_PASS);
});

// ---------------------------------------------------------------------------
// 13 — stale is not fresh, and unmeasured is not either
// ---------------------------------------------------------------------------

it('separates "a login happened" from "the login is still fresh"', function () {
    $device = rrDevice();
    $credential = rrCredential($device);
    rrAuthorization($device);

    rrProof($credential, $device, now()->subDays(30)->toIso8601String());

    $report = rrEvaluate($device);

    // The proof gate passes — an assertion really did happen — and freshness
    // is what fails. Collapsing the two would tell the operator to perform a
    // login that has already been performed.
    expect(rrGate($report, Readiness::GATE_LOGIN_PROOF))->toBe(Readiness::GATE_PASS)
        ->and(rrGate($report, Readiness::GATE_LOGIN_PROOF_FRESHNESS))->toBe(Readiness::GATE_FAIL)
        ->and($report['verdict'])->toBe(Readiness::NOT_READY);
});

it('treats an unconfigured freshness policy as unverified rather than as a pass', function () {
    config()->set('doctor_webauthn_live_proof.freshness_window_days', null);

    $fixture = rrReadyTablet();
    $report = rrEvaluate($fixture['device']);

    expect(rrGate($report, Readiness::GATE_LOGIN_PROOF_FRESHNESS))->toBe(Readiness::GATE_UNVERIFIED)
        ->and($report['verdict'])->toBe(Readiness::NOT_READY);
});

// ---------------------------------------------------------------------------
// 14 / 15 / 16 — one revocation, one failing gate
// ---------------------------------------------------------------------------

it('fails on the CREDENTIAL gate when the credential is revoked, and only that gate', function () {
    $fixture = rrReadyTablet();
    $fixture['credential']->forceFill(['revoked_at' => now()])->save();

    $report = rrEvaluate($fixture['device']);

    expect($report['verdict'])->toBe(Readiness::NOT_READY)
        ->and(rrGate($report, Readiness::GATE_WEBAUTHN_CREDENTIAL_ACTIVE))->toBe(Readiness::GATE_FAIL)
        // The device itself is untouched, and the checklist must say so.
        ->and(rrGate($report, Readiness::GATE_DEVICE_ACTIVE))->toBe(Readiness::GATE_PASS)
        ->and(rrGate($report, Readiness::GATE_APPROVAL_ACTIVE))->toBe(Readiness::GATE_PASS)
        ->and(rrGate($report, Readiness::GATE_ACTIVE_DOCTOR_AUTHORIZATION))->toBe(Readiness::GATE_PASS);
});

it('fails on the DEVICE gates when the device is revoked, leaving the doctor gate alone', function () {
    $fixture = rrReadyTablet();
    $fixture['device']->forceFill([
        'status' => DoctorDevice::STATUS_REVOKED,
        'revoked_at' => now(),
    ])->save();

    $report = rrEvaluate($fixture['device']);

    expect($report['verdict'])->toBe(Readiness::NOT_READY)
        ->and(rrGate($report, Readiness::GATE_DEVICE_ACTIVE))->toBe(Readiness::GATE_FAIL)
        ->and(rrGate($report, Readiness::GATE_DEVICE_NOT_REVOKED))->toBe(Readiness::GATE_FAIL)
        ->and(rrGate($report, Readiness::GATE_ACTIVE_DOCTOR_AUTHORIZATION))->toBe(Readiness::GATE_PASS);
});

it('fails on the AUTHORIZATION gate when the doctor authorization is revoked, and only that gate', function () {
    $fixture = rrReadyTablet();
    $fixture['authorization']->forceFill([
        'status' => DoctorDeviceAuthorization::STATUS_REVOKED,
        'revoked_at' => now(),
    ])->save();

    $report = rrEvaluate($fixture['device']);

    expect($report['verdict'])->toBe(Readiness::NOT_READY)
        ->and(rrGate($report, Readiness::GATE_ACTIVE_DOCTOR_AUTHORIZATION))->toBe(Readiness::GATE_FAIL)
        ->and(rrGate($report, Readiness::GATE_DEVICE_ACTIVE))->toBe(Readiness::GATE_PASS)
        ->and(rrGate($report, Readiness::GATE_WEBAUTHN_CREDENTIAL_ACTIVE))->toBe(Readiness::GATE_PASS);
});

it('refuses a syncable passkey on its own gate', function () {
    $device = rrDevice();
    rrCredential($device, DoctorDeviceWebAuthnCredential::VERDICT_BACKUP_ELIGIBLE);
    rrAuthorization($device);

    $report = rrEvaluate($device);

    expect(rrGate($report, Readiness::GATE_CREDENTIAL_DEVICE_BOUND))->toBe(Readiness::GATE_FAIL)
        // It is present and not revoked — the binding is what is wrong.
        ->and(rrGate($report, Readiness::GATE_WEBAUTHN_CREDENTIAL_ACTIVE))->toBe(Readiness::GATE_PASS);
});

it('refuses a credential that skipped user verification on its own gate', function () {
    $device = rrDevice();
    rrCredential($device, userVerified: false);
    rrAuthorization($device);

    expect(rrGate(rrEvaluate($device), Readiness::GATE_USER_VERIFICATION_VALID))->toBe(Readiness::GATE_FAIL);
});

it('reports a pending tablet on the approval gate rather than on the credential gate', function () {
    $device = rrDevice(DoctorDevice::STATUS_PENDING_APPROVAL);
    rrCredential($device);
    rrAuthorization($device);

    $report = rrEvaluate($device);

    expect(rrGate($report, Readiness::GATE_APPROVAL_ACTIVE))->toBe(Readiness::GATE_FAIL)
        ->and(rrGate($report, Readiness::GATE_WEBAUTHN_CREDENTIAL_ACTIVE))->toBe(Readiness::GATE_PASS);
});

// ---------------------------------------------------------------------------
// Step 7 opens only on a real READY
// ---------------------------------------------------------------------------

it('opens step 7 once the tablet is genuinely ready', function () {
    $fixture = rrReadyTablet();

    $operator = User::factory()->create();
    $operator->assignRole('Super Admin');

    test()->actingAs($operator->fresh())
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('settings.doctor-device-registration.complete', $fixture['device']))
        ->assertOk()
        ->assertSee('READY FOR CLINICAL USE');
});

it('closes step 7 again the moment the credential behind it is revoked', function () {
    $fixture = rrReadyTablet();

    $operator = User::factory()->create();
    $operator->assignRole('Super Admin');
    $operator = $operator->fresh();

    test()->actingAs($operator)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('settings.doctor-device-registration.complete', $fixture['device']))
        ->assertOk();

    $fixture['credential']->forceFill(['revoked_at' => now()])->save();

    // DERIVED, not stored: there is no wizard status column to go stale, so
    // the completed step un-completes itself.
    test()->actingAs($operator)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('settings.doctor-device-registration.complete', $fixture['device']))
        ->assertRedirect(route('settings.doctor-device-registration.readiness', $fixture['device']));
});

// ---------------------------------------------------------------------------
// The engine reports; it never provisions
// ---------------------------------------------------------------------------

it('does not write anything while evaluating readiness', function () {
    $fixture = rrReadyTablet();

    $before = [
        'device' => $fixture['device']->fresh()->toArray(),
        'credential' => $fixture['credential']->fresh()->toArray(),
        'authorization' => $fixture['authorization']->fresh()->toArray(),
        'audits' => AuditLog::query()->count(),
    ];

    rrEvaluate($fixture['device']);
    rrEvaluate($fixture['device']);

    expect($fixture['device']->fresh()->toArray())->toBe($before['device'])
        ->and($fixture['credential']->fresh()->toArray())->toBe($before['credential'])
        ->and($fixture['authorization']->fresh()->toArray())->toBe($before['authorization'])
        ->and(AuditLog::query()->count())->toBe($before['audits']);
});

// ---------------------------------------------------------------------------
// The board judges every row against ONE estate snapshot
// ---------------------------------------------------------------------------

it('builds the estate proof report exactly once for a whole board of devices', function () {
    // EXACTLY ONE, not "fewer than before". A ceiling ("at most N") passes for
    // a duplicate build as happily as for a correct one, and this engine scans
    // every active device and its audit trail on each call — so a per-row
    // rebuild is both slow and internally inconsistent, judging row 1 against a
    // different snapshot than row 20.
    //
    // Counted at the REPOSITORY, which is where the cost is and which — unlike
    // the final service in front of it — can be decorated.
    $counter = new class
    {
        public int $calls = 0;
    };

    $real = app(DoctorWebAuthnLiveProofRepositoryInterface::class);

    app()->instance(
        DoctorWebAuthnLiveProofRepositoryInterface::class,
        new class($real, $counter) implements DoctorWebAuthnLiveProofRepositoryInterface
        {
            public function __construct(
                private DoctorWebAuthnLiveProofRepositoryInterface $inner,
                private object $counter,
            ) {}

            public function latestWebAuthnProofForCredentials(array $credentialIds): Collection
            {
                $this->counter->calls++;

                return $this->inner->latestWebAuthnProofForCredentials($credentialIds);
            }
        }
    );

    foreach (range(1, 3) as $ignored) {
        $device = rrDevice();
        rrCredential($device);
        rrAuthorization($device);
    }

    $operator = User::factory()->create();
    $operator->assignRole('Super Admin');

    test()->actingAs($operator->fresh())
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('settings.doctor-device-registration.index'))
        ->assertOk();

    expect($counter->calls)->toBe(1);
});
