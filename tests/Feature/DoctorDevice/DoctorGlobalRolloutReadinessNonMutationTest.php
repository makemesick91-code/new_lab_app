<?php

/**
 * DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 — the readiness engine cannot act.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM THE OTHER TWO
 *
 * They prove the engine computes and reports the right answer. Correctness is
 * not the property this file is about. This one proves the engine is INCAPABLE
 * of the thing it reports on — that a command whose whole subject is fleet-wide
 * enforcement cannot arm it, cannot provision anyone, and cannot quietly write
 * a row while reading.
 *
 * That is deliberately a STRUCTURAL claim, not a behavioural one. A behavioural
 * test proves the code did not mutate on the paths it happened to take today; a
 * source scan proves the primitives to mutate are not in the file at all, so a
 * future edit that adds one fails here rather than in production. It is the
 * same argument Phase4aPilotPreparationScanner makes about itself, and it
 * applies harder here: that scanner reads configuration, this one reads the
 * whole clinical fleet.
 *
 * THE SPECIFIC TRAP
 *
 * A readiness report is exactly the kind of tool somebody later "improves" by
 * having it fix what it found. The moment it can provision, its output stops
 * being evidence and becomes a description of its own side effects.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 *
 * That the numbers are right. Read-only and wrong is entirely possible; the
 * sibling suites are what stop that.
 */

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceRolloutReadinessRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorGlobalRolloutReadinessService;
use App\Support\Android\AndroidDoctorEnforcementScope;
use App\Support\Android\Phase4aPilotPreparationScanner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedAccessControl();
});

/** File-unique `grrHygiene` prefix. */
function grrHygieneSource(string $relative): string
{
    $path = base_path($relative);

    expect(file_exists($path))->toBeTrue("Expected {$relative} to exist");

    return (string) file_get_contents($path);
}

/**
 * Both halves of the read side. The command is included because a thin wrapper
 * is exactly where somebody would put the "while we're here, fix it" call.
 *
 * @return list<string>
 */
function grrHygieneReadOnlyFiles(): array
{
    return [
        'app/Modules/DoctorDevice/Services/DoctorGlobalRolloutReadinessService.php',
        'app/Console/Commands/DoctorGlobalRolloutReadinessCommand.php',
        'app/Modules/DoctorDevice/Repositories/DoctorDeviceRolloutReadinessRepository.php',
    ];
}

// ---------------------------------------------------------------------------
// Structural: the primitives are not in the file
// ---------------------------------------------------------------------------

it('contains none of the primitives that could write, spawn or fetch', function () {
    $forbidden = [
        'file_put_contents', 'fopen(', 'unlink(', 'rename(', 'mkdir(',
        'Process::', 'shell_exec', 'proc_open', 'passthru', 'system(', 'exec(',
        'Http::', 'curl_',
    ];

    foreach (grrHygieneReadOnlyFiles() as $relative) {
        $source = grrHygieneSource($relative);

        foreach ($forbidden as $primitive) {
            expect($source)->not->toContain(
                $primitive,
                "{$relative} must not be able to {$primitive}"
            );
        }
    }
});

it('contains no persistence call, so it cannot provision what it measures', function () {
    $forbidden = ['->save(', '->update(', '->delete(', '->forceDelete(', '->insert(', '::create(', 'DB::'];

    foreach (grrHygieneReadOnlyFiles() as $relative) {
        $source = grrHygieneSource($relative);

        foreach ($forbidden as $primitive) {
            expect($source)->not->toContain(
                $primitive,
                "{$relative} must not persist anything"
            );
        }
    }
});

it('never reads a request, so a widened report cannot be asked for over HTTP', function () {
    // The engine enumerates the whole clinical fleet. Anything that let a
    // caller shape that enumeration would be a scope the request controls.
    $forbidden = ['request(', 'Request $', '$_GET', '$_POST', '$_REQUEST', '->input('];

    foreach (grrHygieneReadOnlyFiles() as $relative) {
        $source = grrHygieneSource($relative);

        foreach ($forbidden as $primitive) {
            expect($source)->not->toContain($primitive, "{$relative} must not read a request");
        }
    }
});

it('registers no route, so the fleet report stays a console tool', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => (string) $route->uri());

    expect($routes->filter(fn (string $uri): bool => str_contains($uri, 'rollout-readiness')))
        ->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Behavioural: running it changes nothing
// ---------------------------------------------------------------------------

it('leaves every identity row byte-identical after a full run', function () {
    $user = User::factory()->create(['name' => 'drg Snapshot']);
    $user->assignRole('Doctor');

    $doctor = Doctor::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
    ]);

    $device = DoctorDevice::factory()->create([
        'status' => DoctorDevice::STATUS_ACTIVE,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
    ]);

    DoctorDeviceWebAuthnCredential::query()->create([
        'uuid' => (string) Str::uuid(),
        'doctor_device_id' => $device->id,
        'credential_id' => 'cred-hygiene',
        'public_key' => 'pk-hygiene',
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

    $tables = [
        'users',
        'mst_doctors',
        'mst_doctor_devices',
        'mst_doctor_device_authorizations',
        'trx_doctor_device_webauthn_credentials',
    ];

    $before = collect($tables)->mapWithKeys(fn (string $table): array => [
        $table => DB::table($table)->orderBy('id')->get()->toJson(),
    ]);

    expect(Artisan::call('doctor:rollout-readiness'))->toBe(0);

    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())
            ->toBe($before[$table], "{$table} changed while merely being reported on");
    }
});

it('writes no audit row, because reading is not an event', function () {
    $user = User::factory()->create(['name' => 'drg Quiet']);
    $user->assignRole('Doctor');

    $before = DB::table('sys_audit_logs')->count();

    Artisan::call('doctor:rollout-readiness');
    Artisan::call('doctor:rollout-readiness', ['--json' => true]);

    expect(DB::table('sys_audit_logs')->count())->toBe($before);
});

it('cannot arm enforcement or widen the scope by being run', function () {
    $gate = app(DoctorAppLoginGate::class);
    $scope = app(AndroidDoctorEnforcementScope::class);

    $armedBefore = $gate->enforcementEnabled();
    $globalBefore = $scope->globalPermitted();
    $modeBefore = $scope->mode();
    $cohortBefore = $scope->pilotDoctorUserIds();

    Artisan::call('doctor:rollout-readiness');
    Artisan::call('doctor:rollout-readiness', ['--strict' => true]);

    expect($gate->enforcementEnabled())->toBe($armedBefore);
    expect($scope->globalPermitted())->toBe($globalBefore);
    expect($scope->mode())->toBe($modeBefore);
    expect($scope->pilotDoctorUserIds())->toBe($cohortBefore);

    // And the declared ceiling is untouched: a report that could edit its own
    // declaration would be auditing itself.
    expect(config('android_release.enforcement.expected_posture'))
        ->toBe(Phase4aPilotPreparationScanner::POSTURE_BOUNDED_PILOT);
});

// ---------------------------------------------------------------------------
// The boundary that keeps the login path narrow
// ---------------------------------------------------------------------------

it('keeps fleet enumeration out of the interface the login gate is given', function () {
    /*
     * DoctorDeviceWebAuthnCredentialRepositoryInterface says in its own
     * docblock that it deliberately has no "all credentials" accessor, because
     * the login path has no reason to enumerate the clinic's hardware estate.
     * This sprint needed exactly that enumeration and put it in a SECOND
     * interface instead of widening the first — so the gate, which is
     * constructor-injected with the first, still cannot reach it.
     */
    $gateParameters = collect(
        (new ReflectionClass(DoctorAppLoginGate::class))->getConstructor()?->getParameters() ?? []
    )->map(fn (ReflectionParameter $p): ?string => $p->getType() instanceof ReflectionNamedType
        ? $p->getType()->getName()
        : null);

    expect($gateParameters)->not->toContain(
        DoctorDeviceRolloutReadinessRepositoryInterface::class
    );

    $source = grrHygieneSource('app/Modules/DoctorDevice/Interfaces/DoctorDeviceWebAuthnCredentialRepositoryInterface.php');

    // The original narrowing is still there, in its own words.
    expect($source)->toContain('no "all credentials" accessor');
});

it('exposes exactly one public entry point, so there is no side door', function () {
    $methods = collect(
        (new ReflectionClass(DoctorGlobalRolloutReadinessService::class))
            ->getMethods(ReflectionMethod::IS_PUBLIC)
    )
        ->map(fn (ReflectionMethod $m): string => $m->getName())
        ->reject(fn (string $name): bool => $name === '__construct')
        ->values()
        ->all();

    expect($methods)->toBe(['build']);
});
