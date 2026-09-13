<?php

/**
 * DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1 — the fleet readiness engine cannot
 * act, and does not get slower as the fleet grows.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM DoctorFleetReadinessTest
 *
 * That suite proves the engine computes the right answer. This one proves two
 * properties that correctness tests cannot reach: that the engine is INCAPABLE
 * of provisioning what it reports on, and that it reads the fleet in a fixed
 * number of queries however many doctors there are.
 *
 * The first is a STRUCTURAL claim, deliberately. A behavioural test proves the
 * code did not mutate on the paths it happened to take today; a source scan
 * proves the primitives are not in the file at all, so a future edit that adds
 * one fails here instead of on production. It is the same argument its sibling
 * DoctorGlobalRolloutReadinessNonMutationTest makes, and it applies at least as
 * hard: this engine is the one an activation sprint will read.
 *
 * THE SPECIFIC TRAP
 *
 * A readiness report is exactly the tool somebody later "improves" by having it
 * fix what it found — assign the missing branch, write the missing
 * authorization, widen the cohort so the last doctor can be proven. The moment
 * it can do any of that, its output stops being evidence about the fleet and
 * becomes a description of its own side effects.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 *
 * That the numbers are right. Read-only and wrong is entirely possible.
 */

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Services\DoctorFleetReadinessService;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\LabOrder\Models\AuditLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedAccessControl();
});

/** File-unique `fleetHygiene` prefix: Pest shares helpers across files. */
function fleetHygieneSource(string $relative): string
{
    $path = base_path($relative);

    expect(file_exists($path))->toBeTrue("Expected {$relative} to exist");

    return (string) file_get_contents($path);
}

/** Everything this sprint added that participates in building the report. */
function fleetHygieneEngineFiles(): array
{
    return [
        'app/Modules/DoctorAccess/Services/DoctorFleetReadinessService.php',
        'app/Modules/DoctorAccess/Repositories/DoctorFleetReadinessRepository.php',
        'app/Modules/DoctorAccess/Support/DoctorFleetReadinessVerdict.php',
        'app/Console/Commands/DoctorFleetReadinessCommand.php',
    ];
}

// ---------------------------------------------------------------------------
// Structural: the primitives are absent
// ---------------------------------------------------------------------------

it('contains no persistence call, so it cannot provision what it measures', function () {
    $forbidden = ['->save(', '->update(', '->delete(', '->forceDelete(', '->insert(', '->forceFill(', 'DB::'];

    foreach (fleetHygieneEngineFiles() as $file) {
        $source = fleetHygieneSource($file);

        foreach ($forbidden as $primitive) {
            expect($source)->not->toContain($primitive, "{$file} must not contain {$primitive}");
        }
    }
});

it('contains none of the primitives that could spawn, fetch or execute', function () {
    $forbidden = ['exec(', 'shell_exec(', 'proc_open(', 'system(', 'passthru(', 'file_put_contents(', 'Http::', 'curl_'];

    foreach (fleetHygieneEngineFiles() as $file) {
        $source = fleetHygieneSource($file);

        foreach ($forbidden as $primitive) {
            expect($source)->not->toContain($primitive, "{$file} must not contain {$primitive}");
        }
    }
});

it('never reads a request, so a widened fleet report cannot be asked for over HTTP', function () {
    $forbidden = ['request(', '$_GET', '$_POST', '$_REQUEST', '->input('];

    foreach (fleetHygieneEngineFiles() as $file) {
        $source = fleetHygieneSource($file);

        foreach ($forbidden as $primitive) {
            expect($source)->not->toContain($primitive, "{$file} must not contain {$primitive}");
        }
    }
});

it('cannot arm enforcement, move a flag or widen the pilot cohort', function () {
    /*
     * The engine REPORTS the runtime posture by carrying the provisioning
     * engine's own reading of it. It must never be able to change one.
     */
    $forbidden = ['putenv(', 'config([', 'Config::set', 'EnvFileWriter', 'enable(', 'disable('];

    foreach (fleetHygieneEngineFiles() as $file) {
        $source = fleetHygieneSource($file);

        foreach ($forbidden as $primitive) {
            expect($source)->not->toContain($primitive, "{$file} must not contain {$primitive}");
        }
    }
});

it('registers no route, so the fleet report stays a console tool', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => (string) $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'fleet-readiness'));

    expect($routes)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Behavioural: nothing moved
// ---------------------------------------------------------------------------

it('leaves every identity, authorization and evidence row byte-identical after a full run', function () {
    $user = User::factory()->create();
    $user->assignRole('Doctor');

    $doctor = Doctor::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
    ]);

    $device = DoctorDevice::factory()->create([
        'status' => DoctorDevice::STATUS_ACTIVE,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
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

    $authorization = DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    $before = [
        'devices' => DoctorDevice::query()->orderBy('id')->get()->toJson(),
        'authorizations' => DoctorDeviceAuthorization::query()->orderBy('id')->get()->toJson(),
        'credentials' => DoctorDeviceWebAuthnCredential::query()->orderBy('id')->get()->toJson(),
        'locks' => DB::table('mst_doctor_branch_locks')->orderBy('id')->get()->toJson(),
        'requests' => DB::table('trx_doctor_branch_lock_requests')->orderBy('id')->get()->toJson(),
        'covers' => DB::table('trx_doctor_branch_covers')->orderBy('id')->get()->toJson(),
        'leases' => DB::table('trx_doctor_session_leases')->orderBy('id')->get()->toJson(),
        'audit' => DB::table('sys_audit_logs')->orderBy('id')->get()->toJson(),
    ];

    app(DoctorFleetReadinessService::class)->build();
    Artisan::call('doctor:fleet-readiness');

    $after = [
        'devices' => DoctorDevice::query()->orderBy('id')->get()->toJson(),
        'authorizations' => DoctorDeviceAuthorization::query()->orderBy('id')->get()->toJson(),
        'credentials' => DoctorDeviceWebAuthnCredential::query()->orderBy('id')->get()->toJson(),
        'locks' => DB::table('mst_doctor_branch_locks')->orderBy('id')->get()->toJson(),
        'requests' => DB::table('trx_doctor_branch_lock_requests')->orderBy('id')->get()->toJson(),
        'covers' => DB::table('trx_doctor_branch_covers')->orderBy('id')->get()->toJson(),
        'leases' => DB::table('trx_doctor_session_leases')->orderBy('id')->get()->toJson(),
        'audit' => DB::table('sys_audit_logs')->orderBy('id')->get()->toJson(),
    ];

    expect($after)->toBe($before);

    // Named explicitly as well as compared wholesale, so a failure says which
    // boundary moved rather than only that something did.
    expect($credential->fresh()->last_used_at)->toBeNull();
    expect($authorization->fresh()->last_authorized_login_at)->toBeNull();
});

it('writes no audit row, because reading the fleet is not an event', function () {
    $user = User::factory()->create();
    $user->assignRole('Doctor');

    Doctor::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
    ]);

    $before = AuditLog::query()->count();

    Artisan::call('doctor:fleet-readiness');

    expect(AuditLog::query()->count())->toBe($before);
});

// ---------------------------------------------------------------------------
// Query budget
// ---------------------------------------------------------------------------

it('reads the whole fleet in a fixed number of queries however many doctors there are', function () {
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

    $seed = function (int $count) use ($device): void {
        for ($i = 0; $i < $count; $i++) {
            $user = User::factory()->create();
            $user->assignRole('Doctor');

            $doctor = Doctor::factory()->create([
                'user_id' => $user->id,
                'is_active' => true,
            ]);

            DoctorDeviceAuthorization::factory()->active()->create([
                'doctor_id' => $doctor->id,
                'doctor_device_id' => $device->id,
            ]);
        }
    };

    $measure = function (): int {
        // A fresh instance each time: the estate memo is per-report, and
        // measuring a warm one would hide a query the first caller pays.
        $service = app()->makeWith(DoctorFleetReadinessService::class, []);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->build();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $seed(3);
    $small = $measure();

    $seed(12);
    $large = $measure();

    /*
     * The whole point of the bulk-loaded repositories. A per-doctor lookup
     * would make fifteen doctors fifteen queries and a national estate
     * thousands — and the regression would be invisible on a fixture of three.
     */
    expect($large)->toBe($small);

    /*
     * CONSTANCY above is the invariant; this ceiling is the secondary guard.
     *
     * Measured at 21 on this fixture, and that number is mostly not this
     * sprint's: the composed provisioning engine does its own four fleet reads
     * plus the flag and enforcement-scope lookups, and the role seeding sits
     * underneath all of it. This sprint adds the branch locks, the audit
     * evidence, the authorization pairs and ONE memoised estate read.
     *
     * The headroom is deliberate rather than a pin at 21 — a pin that tight
     * fails on an unrelated framework or seeder change and gets raised without
     * being read, which is how a budget stops meaning anything. An N+1 does not
     * hide under this ceiling anyway: it would scale with the fleet and the
     * assertion above catches it first.
     */
    expect($small)->toBeLessThanOrEqual(24);
});
