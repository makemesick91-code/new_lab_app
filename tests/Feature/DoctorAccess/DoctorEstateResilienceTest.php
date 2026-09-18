<?php

/**
 * DOCTOR-ACCESS-TRUSTED-DEVICE-ESTATE-RESILIENCE-1 — does the hardware survive
 * losing a tablet?
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM THE TWO READINESS SUITES
 *
 * `DoctorGlobalRolloutReadinessTest` proves a trusted PATH is provisioned.
 * `DoctorFleetReadinessTest` proves the path has been WALKED. Both are green on
 * production today — 45/45 authorizations, 15/15 doctors with a proven login —
 * and neither looks at whether a branch owns a SECOND tablet. Both would keep
 * saying READY on the morning the only tablet at a branch is dropped.
 *
 * THE SPECIFIC TRAP THIS FILE EXISTS TO CATCH
 *
 * `spare_device_available_per_branch` has been a declared prerequisite of
 * global doctor enforcement since Phase 3.5 and never had an implementation —
 * a string in a list and a hand-signed boolean beside it. Nothing in the system
 * could tell the signer whether what they were signing was true. Every
 * assertion below that looks pedantic is guarding one property: an input the
 * system does not have must produce UNVERIFIED, never a PASS.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 *
 * That a tablet is charged, present, or unbroken. This engine reads a registry.
 * It also never claims a device's branch is an access boundary — cross-branch
 * trusted-device use is valid and proven, and the per-branch numbers are
 * operational resilience only.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Models\DoctorBranchCover;
use App\Modules\DoctorAccess\Services\DoctorEstateResilienceService;
use App\Modules\DoctorAccess\Services\DoctorFleetReadinessService;
use App\Modules\DoctorAccess\Support\DoctorEstateCapacityLevel;
use App\Modules\DoctorAccess\Support\DoctorEstateResilienceVerdict;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Support\Android\Phase4aPilotPreparationScanner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    // The population is keyed on the Doctor ROLE. Without the real roles every
    // fixture is a user with no role, the engine reports an empty fleet, and
    // the suite goes green having proven nothing.
    seedAccessControl();

    // Ships empty in config; pinned empty here so a future default cannot make
    // these tests pass for a reason they are not testing.
    config()->set('android_release.enforcement.concurrent_doctor_stations_per_branch', []);
});

/*
| Helpers carry a file-unique `esr` prefix. This directory's fixtures live in
| the GLOBAL namespace beside roughly a hundred others, several unguarded, so a
| collision is a fatal redeclare rather than a shadowed helper.
*/

function esrReport(): array
{
    return app(DoctorEstateResilienceService::class)->build();
}

function esrBranch(string $code): Branch
{
    return Branch::factory()->create([
        'code' => $code,
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
}

/**
 * @return array{0:User,1:Doctor}
 */
function esrDoctor(Branch $homeBranch, string $name = 'drg Estate'): array
{
    $user = User::factory()->create(['name' => $name]);
    $user->assignRole('Doctor');

    $doctor = Doctor::factory()->create([
        'user_id' => $user->id,
        'name' => $name,
        'is_active' => true,
    ]);

    daGrantHomeLock($doctor, $homeBranch);

    return [$user, $doctor];
}

/**
 * An ELIGIBLE tablet: active AND cryptographically verified, carrying one
 * unrevoked credential.
 *
 * TRAP: DoctorDeviceFactory defaults identity_state to UNVERIFIED, and an
 * unverified device is not eligible for anything. A fixture taking the default
 * would build hardware the engine correctly ignores and then "prove" an
 * exclusion it never meant to write.
 */
function esrDevice(Branch $branch, array $attributes = [], bool $withCredential = true): DoctorDevice
{
    $device = DoctorDevice::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'status' => DoctorDevice::STATUS_ACTIVE,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED,
        'public_key_fingerprint' => hash('sha256', (string) Str::uuid()),
    ], $attributes));

    if ($withCredential) {
        esrCredential($device);
    }

    return $device;
}

function esrCredential(DoctorDevice $device, bool $revoked = false): DoctorDeviceWebAuthnCredential
{
    // A credential belongs to the DEVICE, never to a doctor — the credentials
    // table has no doctor column and deliberately never will. That is what
    // makes one enrolment per shared tablet sufficient.
    return DoctorDeviceWebAuthnCredential::query()->create([
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
        'revoked_at' => $revoked ? now() : null,
    ]);
}

function esrGate(array $report, string $gate): array
{
    foreach ($report['gates'] as $row) {
        if ($row['gate'] === $gate) {
            return $row;
        }
    }

    throw new RuntimeException("gate {$gate} absent from report");
}

function esrBranchRow(array $report, string $code): array
{
    foreach ($report['branches'] as $row) {
        if ($row['branch_code'] === $code) {
            return $row;
        }
    }

    throw new RuntimeException("branch {$code} absent from report");
}

/*
|--------------------------------------------------------------------------
| Eligibility
|--------------------------------------------------------------------------
*/

it('counts only active hardware holding an accepted identity proof as eligible', function (): void {
    $branch = esrBranch('ELG1');
    esrDoctor($branch);

    $eligible = esrDevice($branch);
    // No keystore proof AND no credential — proved by neither protocol.
    // REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1: this used to carry a
    // device-bound credential and was still excluded, which was the defect.
    $unverified = esrDevice($branch, ['identity_state' => DoctorDevice::IDENTITY_UNVERIFIED], withCredential: false);
    $pending = esrDevice($branch, ['status' => DoctorDevice::STATUS_PENDING_APPROVAL]);
    $disabled = esrDevice($branch, ['status' => DoctorDevice::STATUS_DISABLED]);

    $report = esrReport();

    expect($report['estate_totals']['total_devices'])->toBe(4)
        ->and($report['estate_totals']['eligible_device_ids'])->toBe([(int) $eligible->id]);

    // Every excluded device is still VISIBLE in the estate — an ignored row and
    // an absent row are different facts to an operator counting tablets.
    $ids = array_column($report['devices'], 'device_id');
    expect($ids)->toContain((int) $unverified->id, (int) $pending->id, (int) $disabled->id);
});

it('counts a pwa-only device toward capacity, which is what a spare can now be', function (): void {
    $branch = esrBranch('ELG2');
    esrDoctor($branch);

    $keystore = esrDevice($branch);
    $pwaOnly = esrDevice($branch, ['identity_state' => DoctorDevice::IDENTITY_UNVERIFIED]);

    $report = esrReport();

    // The whole point of Stage 2: until the identity predicate accepted a
    // device-bound WebAuthn credential, no WebAuthn-only tablet could ever
    // count here — so no quantity of new hardware could satisfy
    // spare_device_available_per_branch.
    expect($report['estate_totals']['eligible_device_ids'])
        ->toContain((int) $keystore->id, (int) $pwaOnly->id);
});

it('never counts a revoked device toward capacity, however recently it worked', function (): void {
    $branch = esrBranch('RVK1');
    esrDoctor($branch);

    esrDevice($branch);
    $revoked = esrDevice($branch, [
        'status' => DoctorDevice::STATUS_REVOKED,
        'revoked_at' => now(),
    ]);

    $report = esrReport();
    $row = esrBranchRow($report, 'RVK1');

    expect($row['eligible_device_count'])->toBe(1)
        ->and($row['revoked_device_ids'])->toBe([(int) $revoked->id])
        ->and($row['total_device_count'])->toBe(2)
        // One eligible tablet is not a spare, whatever the estate total says.
        ->and($row['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($row['gaps'])->toContain(DoctorEstateResilienceVerdict::GAP_NO_SPARE);
});

/*
|--------------------------------------------------------------------------
| The spare gate, and its refusal to guess
|--------------------------------------------------------------------------
*/

it('fails a branch holding exactly one eligible device under every station count', function (): void {
    $branch = esrBranch('ONE1');
    esrDoctor($branch);
    esrDevice($branch);

    $report = esrReport();

    expect(esrBranchRow($report, 'ONE1')['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and(esrGate($report, DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE)['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($report['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL);
});

it('reports UNVERIFIED, never PASS, when two devices meet an unrecorded station count', function (): void {
    $branch = esrBranch('TWO1');
    [, $doctor] = esrDoctor($branch);

    // Authorized across the whole estate on purpose: with every OTHER gate
    // green, the overall verdict is decided by the undeclared station count
    // alone, which is what this test is about.
    dbaAuthorization($doctor, esrDevice($branch));
    dbaAuthorization($doctor, esrDevice($branch));

    $report = esrReport();
    $row = esrBranchRow($report, 'TWO1');

    expect($row['verdict'])->toBe(DoctorEstateResilienceVerdict::UNVERIFIED)
        ->and($row['gaps'])->toContain(DoctorEstateResilienceVerdict::GAP_STATION_COUNT_UNDECLARED)
        // The whole point: an unknown input must not be reported as a satisfied
        // gate, and must not be silently defaulted to a number that passes.
        ->and($report['verdict'])->toBe(DoctorEstateResilienceVerdict::UNVERIFIED)
        ->and($report['required_capacity']['missing_input_state'])->toBe('NOT_MEASURED')
        ->and($report['required_capacity']['branches_with_undeclared_stations'])->toBe(['TWO1']);
});

it('passes only once the station count is recorded and the estate clears it', function (): void {
    $branch = esrBranch('DEC1');
    esrDoctor($branch);
    esrDevice($branch);
    esrDevice($branch);

    config()->set('android_release.enforcement.concurrent_doctor_stations_per_branch', ['DEC1' => 1]);

    $report = esrReport();

    expect(esrBranchRow($report, 'DEC1')['verdict'])->toBe(DoctorEstateResilienceVerdict::PASS)
        ->and(esrGate($report, DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE)['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::PASS)
        ->and($report['required_capacity']['missing_input_state'])->toBe('DECLARED');
});

it('fails a two-device branch that declares two concurrent stations', function (): void {
    $branch = esrBranch('BUSY');
    esrDoctor($branch);
    esrDevice($branch);
    esrDevice($branch);

    // Two chairs running at once need three tablets, not two.
    config()->set('android_release.enforcement.concurrent_doctor_stations_per_branch', ['BUSY' => 2]);

    expect(esrBranchRow(esrReport(), 'BUSY')['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL);
});

it('treats a zero or negative declared station count as undeclared, never as headroom', function (): void {
    $branch = esrBranch('ZERO');
    esrDoctor($branch);
    esrDevice($branch);
    esrDevice($branch);

    // Zero stations would make one device a spare and turn the gate green on a
    // branch nobody has looked at. A typo must not manufacture capacity.
    config()->set('android_release.enforcement.concurrent_doctor_stations_per_branch', ['ZERO' => 0]);

    expect(esrBranchRow(esrReport(), 'ZERO')['verdict'])->toBe(DoctorEstateResilienceVerdict::UNVERIFIED);
});

/*
|--------------------------------------------------------------------------
| Local coverage — the TLK1 shape, generalised
|--------------------------------------------------------------------------
*/

it('fails local coverage for a branch that homes doctors and holds no device', function (): void {
    $stranded = esrBranch('STR1');
    $equipped = esrBranch('EQP1');

    esrDoctor($stranded, 'drg Stranded');
    esrDoctor($equipped, 'drg Equipped');
    esrDevice($equipped);

    $report = esrReport();
    $gate = esrGate($report, 'local_trusted_device_coverage');

    expect($gate['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($gate['stranded_branches'])->toBe(['STR1'])
        ->and(esrBranchRow($report, 'STR1')['gaps'])
        ->toContain(DoctorEstateResilienceVerdict::GAP_NO_LOCAL_DEVICE)
        // Named as a PHYSICAL gap. The stranded doctor can still authenticate
        // on the other branch's tablet, and the report must not imply otherwise.
        ->and($gate['detail'])->toContain('physical availability gap');
});

it('includes a branch holding hardware but homing nobody, and does not fail it', function (): void {
    $homed = esrBranch('HOM1');
    $idle = esrBranch('IDL1');

    esrDoctor($homed);
    esrDevice($homed);
    esrDevice($idle);

    $report = esrReport();
    $row = esrBranchRow($report, 'IDL1');

    // The branch universe is a UNION of "holds hardware" and "homes a doctor".
    // Taking either half alone would have hidden exactly one real branch.
    expect($row['home_doctor_count'])->toBe(0)
        ->and($row['eligible_device_count'])->toBe(1)
        ->and($row['gaps'])->not->toContain(DoctorEstateResilienceVerdict::GAP_NO_LOCAL_DEVICE)
        // A spare protects a clinic day. Where nobody is homed there is no
        // clinic day and no doctor to strand, so one tablet is not a shortfall.
        ->and($row['gaps'])->not->toContain(DoctorEstateResilienceVerdict::GAP_NO_SPARE)
        ->and($row['verdict'])->toBe(DoctorEstateResilienceVerdict::PASS);

    $findings = array_column($report['findings'], 'finding');
    expect($findings)->toContain('eligible_device_at_branch_homing_no_doctor');
});

it('does not make an unstaffed branch worse by standing a tablet in it', function (): void {
    /*
     * THE CONTRADICTION THIS PINS. An earlier revision passed a branch with zero
     * devices and zero home doctors, and FAILED the same branch once it held one
     * device — so putting hardware in an unstaffed room made the gate worse, and
     * would have held spare_device_available_per_branch at FAIL forever, after
     * every staffed branch was provisioned, over a branch that cannot strand
     * anyone.
     */
    $staffed = esrBranch('STF1');
    [, $doctor] = esrDoctor($staffed);
    dbaAuthorization($doctor, esrDevice($staffed));
    dbaAuthorization($doctor, esrDevice($staffed));
    config()->set('android_release.enforcement.concurrent_doctor_stations_per_branch', ['STF1' => 1]);

    $empty = esrBranch('EMP1');
    DoctorDevice::factory()->create([
        'branch_id' => $empty->id,
        'status' => DoctorDevice::STATUS_REVOKED,
        'revoked_at' => now(),
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
    ]);

    $before = esrReport();

    // Now stand a working tablet in the unstaffed branch.
    esrDevice($empty);

    $after = esrReport();

    // The row itself must not degrade either — the earlier guard asserted only
    // the GATE, and EMP1 is excluded from that gate's population under both
    // readings, so it would have stayed green if the row flipped PASS -> FAIL.
    expect(esrGate($before, DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE)['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::PASS)
        ->and(esrGate($after, DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE)['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::PASS)
        ->and(esrBranchRow($after, 'EMP1')['verdict'])->toBe(DoctorEstateResilienceVerdict::PASS);
});

it('reddens an unstaffed branch whose tablet carries no credential, without touching the spare gate', function (): void {
    /*
     * The monotonicity above is scoped to the SPARE requirement, not to the row.
     * An eligible device nobody can log into is a defect wherever it sits, so
     * the row and device_credential_coverage both go red — and the spare gate,
     * whose population excludes unstaffed branches, does not.
     */
    $staffed = esrBranch('STF2');
    [, $doctor] = esrDoctor($staffed);
    dbaAuthorization($doctor, esrDevice($staffed));
    dbaAuthorization($doctor, esrDevice($staffed));
    config()->set('android_release.enforcement.concurrent_doctor_stations_per_branch', ['STF2' => 1]);

    $idle = esrBranch('IDL2');
    esrDevice($idle, [], withCredential: false);

    $report = esrReport();

    expect(esrBranchRow($report, 'IDL2')['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and(esrBranchRow($report, 'IDL2')['gaps'])
        ->toContain(DoctorEstateResilienceVerdict::GAP_DEVICE_WITHOUT_CREDENTIAL)
        ->and(esrGate($report, DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE)['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::PASS)
        ->and(esrGate($report, 'device_credential_coverage')['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::FAIL);
});

/*
|--------------------------------------------------------------------------
| Credential coverage
|--------------------------------------------------------------------------
*/

it('fails an eligible device carrying no unrevoked credential', function (): void {
    $branch = esrBranch('CRD1');
    esrDoctor($branch);

    $bare = esrDevice($branch, [], withCredential: false);
    esrCredential($bare, revoked: true);

    $report = esrReport();
    $gate = esrGate($report, 'device_credential_coverage');

    expect($gate['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($gate['eligible_devices_without_credential'])->toBe([(int) $bare->id]);
});

it('never prints a gate detail that contradicts its own verdict', function (): void {
    /*
     * device_credential_coverage printed "Every eligible device carries at least
     * one UNREVOKED credential" on a FAIL, contradicted by the device list
     * beside it. That is the same defect that forced a sibling gate's rename
     * one round earlier, surviving in an untouched gate because only the
     * sibling was being looked at. Asserted across EVERY gate, so the next one
     * cannot repeat it.
     */
    $branch = esrBranch('DTL1');
    esrDoctor($branch);
    $bare = esrDevice($branch, [], withCredential: false);

    foreach (esrReport()['gates'] as $gate) {
        if ($gate['verdict'] === DoctorEstateResilienceVerdict::PASS) {
            continue;
        }

        expect($gate['detail'])
            ->not->toContain('Every eligible device carries')
            ->not->toContain('Every branch that homes a doctor holds')
            ->not->toContain('resolved an identical');
    }

    expect(esrGate(esrReport(), 'device_credential_coverage')['detail'])
        ->toContain('cannot be logged into')
        ->and($bare->id)->toBeGreaterThan(0);
});

it('names the branches behind an UNVERIFIED spare gate, not only the failing ones', function (): void {
    $branch = esrBranch('UNV1');
    [, $doctor] = esrDoctor($branch);
    dbaAuthorization($doctor, esrDevice($branch));
    dbaAuthorization($doctor, esrDevice($branch));

    $gate = esrGate(esrReport(), DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE);

    // An UNVERIFIED verdict used to name no branch at all: branches_failing
    // matches FAIL only, so the reader got a verdict they could not act on.
    expect($gate['verdict'])->toBe(DoctorEstateResilienceVerdict::UNVERIFIED)
        ->and($gate['branches_failing'])->toBe([])
        ->and($gate['branches_unverified'])->toBe(['UNV1'])
        ->and($gate['detail'])->toContain('undecidable');
});

it('reads the attestation from the same gate key the gate publishes', function (): void {
    $branch = esrBranch('KEY1');
    esrDoctor($branch);
    esrDevice($branch);

    $report = esrReport();

    /*
     * Producer and consumer were two copies of one string literal, and this
     * sprint had already renamed a sibling gate. A rename would have dropped
     * attestation() to its UNVERIFIED default in silence, and the contradiction
     * test would have stayed green because UNVERIFIED is also !== PASS. Pinned
     * as an equality against the live gate rather than against a literal.
     */
    expect($report['attestation']['prerequisite'])
        ->toBe(DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE)
        ->and($report['attestation']['measured'])
        ->toBe(esrGate($report, DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE)['verdict']);
});

it('passes credential coverage when every eligible device carries one', function (): void {
    $branch = esrBranch('CRD2');
    esrDoctor($branch);
    esrDevice($branch);
    esrDevice($branch);

    expect(esrGate(esrReport(), 'device_credential_coverage')['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::PASS);
});

/*
|--------------------------------------------------------------------------
| Authorization, recalculated against the CURRENT estate
|--------------------------------------------------------------------------
*/

it('raises the authorization target when the estate grows, and reports the gap', function (): void {
    $branch = esrBranch('AUT1');
    [, $doctor] = esrDoctor($branch);

    $first = esrDevice($branch);
    dbaAuthorization($doctor, $first);

    expect(esrGate(esrReport(), 'authorization_coverage')['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::PASS);

    // A new tablet does not close a gap — it opens one, until every doctor is
    // authorized on it. Carrying the old 45/45 forward would have hidden this.
    esrDevice($branch);

    $gate = esrGate(esrReport(), 'authorization_coverage');

    expect($gate['target_pairs'])->toBe(2)
        ->and($gate['active_pairs'])->toBe(1)
        ->and($gate['missing_pairs'])->toBe(1)
        ->and($gate['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL);
});

it('cannot be shown a duplicate active pair, because the schema refuses one', function (): void {
    $branch = esrBranch('DUP1');
    [, $doctor] = esrDoctor($branch);
    $device = esrDevice($branch);

    dbaAuthorization($doctor, $device);

    /*
     * MEASURED, not assumed. `mst_dd_authorizations_pair_unique` is a FULL
     * unique on (doctor_id, doctor_device_id) — not partial, not scoped to
     * ACTIVE — so a second row for the same pair is unrepresentable in any
     * status. The engine's duplicate tally is therefore DEFENCE IN DEPTH
     * against an index that already holds the line, and this test pins that
     * relationship rather than pretending to exercise a state the database
     * will not store. A sibling service's comment once claimed no such index
     * existed; it does.
     */
    /*
     * WRAPPED IN A NESTED TRANSACTION, WHICH IS A SAVEPOINT.
     *
     * PostgreSQL aborts the ENTIRE transaction the moment any statement raises,
     * and every later statement returns 25P02 until a rollback. RefreshDatabase
     * wraps each test in one transaction, so a bare deliberate violation is
     * green on SQLite and poisons the connection on PG — which is exactly what
     * happened here: this test passed locally and failed CI with
     * `1 failed, 4013 passed`. A test that PROVES a constraint exists has to
     * violate it, so every such test needs this wrapper.
     */
    expect(fn () => DB::transaction(fn () => dbaAuthorization($doctor, $device)))
        ->toThrow(UniqueConstraintViolationException::class);

    /*
     * Assertions that mean something after the savepoint: the ORIGINAL grant
     * must still be there, still ACTIVE, and still the only one. A filler count
     * here is the tell that nobody thought about what survives the violation.
     */
    $rows = DoctorDeviceAuthorization::query()
        ->where('doctor_id', $doctor->id)
        ->where('doctor_device_id', $device->id)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->status)->toBe(DoctorDeviceAuthorization::STATUS_ACTIVE);

    $gate = esrGate(esrReport(), 'authorization_coverage');

    expect($gate['duplicate_active_pairs'])->toBe(0)
        ->and($gate['verdict'])->toBe(DoctorEstateResilienceVerdict::PASS);
});

it('does not let an authorization on a revoked device close a matrix gap', function (): void {
    $branch = esrBranch('RVK2');
    [, $doctor] = esrDoctor($branch);

    $live = esrDevice($branch);
    $dead = esrDevice($branch, ['status' => DoctorDevice::STATUS_REVOKED, 'revoked_at' => now()]);

    dbaAuthorization($doctor, $dead);

    $report = esrReport();
    $gate = esrGate($report, 'authorization_coverage');

    // Target counts the ONE eligible tablet; the grant on the revoked one is
    // dropped rather than credited.
    expect($gate['target_pairs'])->toBe(1)
        ->and($gate['active_pairs'])->toBe(0)
        ->and($gate['missing_pairs'])->toBe(1)
        ->and($report['estate_totals']['eligible_device_ids'])->toBe([(int) $live->id]);
});

/*
|--------------------------------------------------------------------------
| Physical branch is not an access boundary
|--------------------------------------------------------------------------
*/

it('does not require a doctor to be authorized on a device at their own branch', function (): void {
    $home = esrBranch('HME1');
    $elsewhere = esrBranch('ELS1');

    [, $doctor] = esrDoctor($home);
    esrDevice($home);
    $remote = esrDevice($elsewhere);

    dbaAuthorization($doctor, DoctorDevice::query()->where('branch_id', $home->id)->firstOrFail());
    dbaAuthorization($doctor, $remote);

    $report = esrReport();

    // Cross-branch trust is valid: the matrix is doctors x the WHOLE eligible
    // estate, never doctors x their own branch's hardware.
    expect(esrGate($report, 'authorization_coverage')['target_pairs'])->toBe(2)
        ->and(esrGate($report, 'authorization_coverage')['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::PASS)
        ->and($report['resilience_semantics'])->toContain('not an access boundary');
});

/*
|--------------------------------------------------------------------------
| Failure domain
|--------------------------------------------------------------------------
*/

it('names the branches that stop working after one device is lost', function (): void {
    $fragile = esrBranch('FRG1');
    $resilient = esrBranch('RES1');

    esrDoctor($fragile, 'drg Fragile A');
    esrDoctor($fragile, 'drg Fragile B');
    esrDoctor($resilient, 'drg Resilient');

    esrDevice($fragile);
    esrDevice($resilient);
    esrDevice($resilient);

    $failure = esrReport()['failure_domain'];

    expect($failure['branches_serviceable_after_one_loss'])->toBe(['RES1'])
        ->and($failure['branches_that_stop'])->toHaveCount(1)
        ->and($failure['branches_that_stop'][0]['branch_code'])->toBe('FRG1')
        ->and($failure['home_doctors_affected_total'])->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Empty population
|--------------------------------------------------------------------------
*/

it('fails rather than passes when there is nothing to measure', function (): void {
    $report = esrReport();

    // "Zero branches, zero gaps" is arithmetically a pass and operationally a
    // broken query. This programme has already shipped one gate that read
    // silence as success.
    expect($report['branches'])->toBe([])
        ->and($report['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL);
});

/*
|--------------------------------------------------------------------------
| Attestation is observed, never enforced or written
|--------------------------------------------------------------------------
*/

it('reports a contradiction between what is signed and what is measured, and never overwrites either', function (): void {
    $branch = esrBranch('ATT1');
    esrDoctor($branch);
    esrDevice($branch);

    config()->set('android_release.enforcement.global_prerequisites_attested.spare_device_available_per_branch', true);

    $report = esrReport();

    expect($report['attestation']['attested'])->toBeTrue()
        ->and($report['attestation']['measured'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($report['attestation']['contradiction'])->toBeTrue();

    /*
     * A signature cannot rescue the measurement, and the measurement does not
     * overwrite the signature — the engine still writes nothing.
     *
     * WHAT REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1 NARROWED.
     * The original title of this test said the contradiction "gates nothing on
     * it", and that is no longer true: a signature standing against a
     * measurement now fails a gate of its own. The absence of a signature still
     * gates nothing, which is the half the owner chose measurement-over-
     * coupling for.
     */
    expect(config('android_release.enforcement.global_prerequisites_attested.spare_device_available_per_branch'))
        ->toBeTrue()
        ->and($report['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and(esrGate($report, DoctorEstateResilienceVerdict::GATE_ATTESTATION_NO_CONTRADICTION)['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::FAIL);
});

/*
|--------------------------------------------------------------------------
| Read-only, and activating nothing
|--------------------------------------------------------------------------
*/

it('writes nothing at all', function (): void {
    $branch = esrBranch('RDO1');
    [, $doctor] = esrDoctor($branch);
    $device = esrDevice($branch);
    dbaAuthorization($doctor, $device);

    $before = [
        'devices' => DoctorDevice::query()->count(),
        'authorizations' => DoctorDeviceAuthorization::query()->count(),
        'credentials' => DoctorDeviceWebAuthnCredential::query()->count(),
        'locks' => DB::table('mst_doctor_branch_locks')->count(),
        'audit' => DB::table('sys_audit_logs')->count(),
    ];

    esrReport();
    esrReport();

    expect([
        'devices' => DoctorDevice::query()->count(),
        'authorizations' => DoctorDeviceAuthorization::query()->count(),
        'credentials' => DoctorDeviceWebAuthnCredential::query()->count(),
        'locks' => DB::table('mst_doctor_branch_locks')->count(),
        'audit' => DB::table('sys_audit_logs')->count(),
    ])->toBe($before);
});

it('never reports itself as authorising activation, even when every gate passes', function (): void {
    $branch = esrBranch('ACT1');
    [, $doctor] = esrDoctor($branch);
    $a = esrDevice($branch);
    $b = esrDevice($branch);
    dbaAuthorization($doctor, $a);
    dbaAuthorization($doctor, $b);

    config()->set('android_release.enforcement.concurrent_doctor_stations_per_branch', ['ACT1' => 1]);

    $report = esrReport();

    expect($report['verdict'])->toBe(DoctorEstateResilienceVerdict::PASS)
        ->and($report['authorizes_activation'])->toBeFalse()
        ->and($report['runtime']['global_enforcement_active'])->toBeFalse()
        ->and($report['runtime']['global_scope_permitted'])->toBeFalse();
});

it('serves a second build from freshly read hardware', function (): void {
    $branch = esrBranch('SNP1');
    esrDoctor($branch);
    esrDevice($branch);

    $service = app(DoctorEstateResilienceService::class);

    expect($service->build()['estate_totals']['eligible_devices'])->toBe(1);

    esrDevice($branch);

    // The sibling engine shipped a memo whose reset sat inside a consumer, so a
    // second report was served the first one's estate. One build, one snapshot.
    expect($service->build()['estate_totals']['eligible_devices'])->toBe(2);
});

it('agrees with the fleet engine about which devices are eligible, and claims only that', function (): void {
    $branch = esrBranch('AGR1');
    esrDoctor($branch);
    esrDevice($branch);
    esrDevice($branch, ['status' => DoctorDevice::STATUS_REVOKED, 'revoked_at' => now()]);

    $gate = esrGate(esrReport(), 'eligible_device_set_agreement');

    // The gate compares the eligible device id list and NOTHING else. It was
    // once called estate_snapshot_agreement and its PASS text said the two
    // engines had read the same hardware — which it has never established, since
    // each reads locks and credentials separately. Narrowing the verdict has to
    // narrow the message with it.
    expect($gate['verdict'])->toBe(DoctorEstateResilienceVerdict::PASS)
        ->and($gate['detail'])->toContain('ELIGIBLE DEVICE ID LIST')
        ->and($gate['detail'])->toContain('not detected here');
});

it('fails the spare gate when no branch homes a doctor, rather than passing over an empty population', function (): void {
    /*
     * THE FALSE GREEN THIS PINS. Taking the worst over ALL branches let an
     * estate made only of unstaffed branches report
     * spare_device_available_per_branch as PASS while holding no usable tablet
     * — and attestation() reads its verdict from that gate, so a signed `true`
     * came back as "no contradiction".
     */
    $idle = esrBranch('IDLE');
    esrDevice($idle);

    config()->set('android_release.enforcement.global_prerequisites_attested.spare_device_available_per_branch', true);

    $report = esrReport();

    expect(esrGate($report, DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE)['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($report['attestation']['measured'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($report['attestation']['contradiction'])->toBeTrue()
        ->and($report['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL);
});

it('does not count a soft-deleted doctor as a home doctor', function (): void {
    $branch = esrBranch('ORPH');
    [, $living] = esrDoctor($branch, 'drg Living');
    [, $retired] = esrDoctor($branch, 'drg Retired');

    dbaAuthorization($living, esrDevice($branch));

    expect(esrBranchRow(esrReport(), 'ORPH')['home_doctor_count'])->toBe(2);

    // The lock FK is restrictOnDelete, so retiring a doctor leaves the row
    // behind forever. Counting rows would keep sizing this branch for someone
    // who no longer practises, and would silently disagree with the fleet
    // engine, which resolves doctors through a soft-delete-scoped query.
    $retired->delete();

    $report = esrReport();

    expect(esrBranchRow($report, 'ORPH')['home_doctor_count'])->toBe(1)
        ->and(array_column($report['findings'], 'finding'))
        ->toContain('home_lock_names_a_doctor_that_no_longer_exists');
});

it('reports a doctor who belongs to no branch instead of discarding the signal', function (): void {
    $branch = esrBranch('UNST');
    [, $doctor] = esrDoctor($branch);
    dbaAuthorization($doctor, esrDevice($branch));

    // A Doctor-role account with no home lock at all: no branch counts them and
    // no branch is sized for them. The composed fleet report already knows.
    $stray = User::factory()->create(['name' => 'drg Unset']);
    $stray->assignRole('Doctor');
    Doctor::factory()->create(['user_id' => $stray->id, 'name' => 'drg Unset', 'is_active' => true]);

    $findings = array_column(esrReport()['findings'], 'finding');

    expect($findings)->toContain('doctors_without_a_home_branch');
});

/*
|--------------------------------------------------------------------------
| Treatment rooms are an advisory reference, never the station count
|--------------------------------------------------------------------------
*/

it('reports active treatment rooms without letting them decide a level 3 verdict', function (): void {
    $branch = esrBranch('ROOM');
    [, $doctor] = esrDoctor($branch);
    dbaAuthorization($doctor, esrDevice($branch));
    dbaAuthorization($doctor, esrDevice($branch));

    // One active treatment room. If the engine were quietly using rooms as the
    // station count it would now compute stations=1, require 2, hold 2 and
    // report PASS. It must not: a room inventory is not a peak concurrent
    // staffed station count, and substituting one for the other is the same
    // failure as guessing, only with a more plausible number.
    //
    // REVISION-1 gave LEVEL 2 a room-based denominator of its own, which is a
    // different question the owner defined outright. Rooms decide Level 2 and
    // still decide nothing here, so this assertion is narrowed to Level 3
    // rather than deleted — the trap it guards is unchanged.
    ClinicRoom::factory()->create([
        'branch_id' => $branch->id,
        'type' => ClinicRoom::TYPE_TREATMENT_ROOM,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);

    // A non-treatment room and an inactive one must not be counted either.
    ClinicRoom::factory()->create([
        'branch_id' => $branch->id,
        'type' => ClinicRoom::TYPE_XRAY_ROOM,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);
    ClinicRoom::factory()->create([
        'branch_id' => $branch->id,
        'type' => ClinicRoom::TYPE_TREATMENT_ROOM,
        'status' => ClinicRoom::STATUS_INACTIVE,
    ]);

    $report = esrReport();

    expect(esrBranchRow($report, 'ROOM')['active_treatment_rooms'])->toBe(1)
        ->and(esrBranchRow($report, 'ROOM')['verdict'])->toBe(DoctorEstateResilienceVerdict::UNVERIFIED)
        ->and($report['required_capacity']['missing_input_state'])->toBe('NOT_MEASURED')
        ->and($report['verdict'])->toBe(DoctorEstateResilienceVerdict::UNVERIFIED);
});

it('reports null rather than zero for a branch with no configured rooms', function (): void {
    $branch = esrBranch('NORM');
    esrDoctor($branch);
    esrDevice($branch);

    // A branch nobody has configured rooms for and a branch measured as having
    // none are different facts, and the report must not flatten them.
    expect(esrBranchRow(esrReport(), 'NORM')['active_treatment_rooms'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The command surface
|--------------------------------------------------------------------------
*/

it('renders the human report and exits 0 on a measured FAIL', function (): void {
    $branch = esrBranch('CMD1');
    esrDoctor($branch);
    esrDevice($branch);

    /*
     * A measured FAIL exits 0 without --strict on purpose. The estate is short
     * of hardware today and will be until tablets are bought, and a gate that
     * reddens a deploy chain for months gets deleted rather than fixed.
     */
    $this->artisan('doctor:estate-resilience')
        ->assertExitCode(0)
        ->expectsOutputToContain('ESTATE_RESILIENCE=FAIL');
});

it('exits non-zero under --strict while the estate is short', function (): void {
    $branch = esrBranch('CMD2');
    esrDoctor($branch);
    esrDevice($branch);

    $this->artisan('doctor:estate-resilience --strict')->assertExitCode(1);
});

it('exits non-zero with nothing to measure, with or without --strict', function (): void {
    // A broken query must never read as a clean estate.
    $this->artisan('doctor:estate-resilience')->assertExitCode(1);
});

it('emits parseable JSON carrying the verdict and the activation disclaimer', function (): void {
    $branch = esrBranch('CMD3');
    [, $doctor] = esrDoctor($branch);
    dbaAuthorization($doctor, esrDevice($branch));

    Artisan::call('doctor:estate-resilience', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($payload['authorizes_activation'])->toBeFalse()
        ->and($payload['branch_scope'])->toBe(DoctorEstateResilienceVerdict::BRANCH_SCOPE)
        // The one line an operator reading only the tail must still see.
        ->and($payload['resilience_semantics'])->toContain('not an access boundary');
});

/*
|--------------------------------------------------------------------------
| REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1
|
| THREE REQUIREMENTS THAT WERE ONE GATE.
|
| Every assertion below guards one property: separating the levels must never
| turn a measured falsehood into a PASS. Lowering what ACTIVATION TESTING
| requires does not lower what HIGH AVAILABILITY requires, and the three
| statuses must be free to disagree — because on the production estate they do.
|--------------------------------------------------------------------------
*/

function esrLevel(array $report, string $code, string $level): string
{
    return (string) esrBranchRow($report, $code)['capacity_levels'][$level];
}

function esrFleetLevel(array $report, int $level): string
{
    foreach ($report['capacity_policy']['levels'] as $row) {
        if ((int) $row['level'] === $level) {
            return (string) $row['status'];
        }
    }

    throw new RuntimeException("level {$level} absent from report");
}

function esrRoom(Branch $branch, string $type, string $status = ClinicRoom::STATUS_ACTIVE): ClinicRoom
{
    return ClinicRoom::factory()->create([
        'branch_id' => $branch->id,
        'type' => $type,
        'status' => $status,
    ]);
}

/*
| Level 1 — activation test coverage
*/

it('fails level 1 for a staffed branch holding no eligible device', function (): void {
    $branch = esrBranch('L1A');
    esrDoctor($branch);

    $report = esrReport();

    expect(esrLevel($report, 'L1A', DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE))
        ->toBe(DoctorEstateCapacityLevel::FAIL)
        ->and(esrFleetLevel($report, 1))->toBe(DoctorEstateCapacityLevel::FAIL);
});

it('passes level 1 for a staffed branch holding one usable device', function (): void {
    $branch = esrBranch('L1B');
    esrDoctor($branch);
    esrDevice($branch);

    expect(esrFleetLevel(esrReport(), 1))->toBe(DoctorEstateCapacityLevel::PASS);
});

it('passes level 1 only when EVERY staffed branch is covered', function (): void {
    $a = esrBranch('L1C');
    $b = esrBranch('L1D');
    esrDoctor($a, 'drg One');
    esrDoctor($b, 'drg Two');
    esrDevice($a);

    // A holds a tablet, B does not. One covered branch is not a covered fleet.
    expect(esrFleetLevel(esrReport(), 1))->toBe(DoctorEstateCapacityLevel::FAIL)
        ->and(esrLevel(esrReport(), 'L1C', DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE))
        ->toBe(DoctorEstateCapacityLevel::PASS)
        ->and(esrLevel(esrReport(), 'L1D', DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE))
        ->toBe(DoctorEstateCapacityLevel::FAIL);

    esrDevice($b);

    expect(esrFleetLevel(esrReport(), 1))->toBe(DoctorEstateCapacityLevel::PASS);
});

it('counts an eligible tablet nobody can log into as NO coverage at all', function (): void {
    /*
     * THE HOLE AN ADVERSARIAL REVIEW FOUND BEFORE THIS SHIPPED.
     *
     * Level 1's first draft counted ELIGIBLE devices — active and
     * cryptographically verified — which says nothing about credentials. A
     * branch receiving one tablet whose only credential is revoked would have
     * turned the activation-testing prerequisite GREEN at a branch where no
     * doctor can sign in. device_credential_coverage FAILs beside it, but a
     * reader told to watch one field would not have been looking there.
     */
    $branch = esrBranch('L1E');
    esrDoctor($branch);
    $device = esrDevice($branch, [], withCredential: false);
    esrCredential($device, revoked: true);

    $report = esrReport();
    $row = esrBranchRow($report, 'L1E');

    expect($row['eligible_device_count'])->toBe(1)
        ->and($row['locally_usable_device_count'])->toBe(0)
        ->and(esrLevel($report, 'L1E', DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE))
        ->toBe(DoctorEstateCapacityLevel::FAIL);
});

it('counts neither a revoked nor an inactive tablet toward level 1', function (): void {
    $branch = esrBranch('L1F');
    esrDoctor($branch);
    esrDevice($branch, ['status' => DoctorDevice::STATUS_REVOKED]);
    esrDevice($branch, ['status' => DoctorDevice::STATUS_DISABLED]);

    expect(esrFleetLevel(esrReport(), 1))->toBe(DoctorEstateCapacityLevel::FAIL);
});

it('holds an unstaffed branch outside every level rather than passing it', function (): void {
    /*
     * NOT_APPLICABLE IS NOT A PASS. The previous sprint shipped a gate that
     * took the worst over ALL branches and reported PASS on an estate made only
     * of unstaffed ones, holding zero usable tablets. Here the row is removed
     * from the population instead of ranked, so the population arrives empty
     * and an empty population FAILS.
     */
    $idle = esrBranch('L1G');
    esrDevice($idle);

    $report = esrReport();

    expect(esrLevel($report, 'L1G', DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE))
        ->toBe(DoctorEstateCapacityLevel::NOT_APPLICABLE)
        ->and(esrLevel($report, 'L1G', DoctorEstateCapacityLevel::ROOM_CAPACITY))
        ->toBe(DoctorEstateCapacityLevel::NOT_APPLICABLE)
        ->and(esrLevel($report, 'L1G', DoctorEstateCapacityLevel::FAILURE_RESILIENCE))
        ->toBe(DoctorEstateCapacityLevel::NOT_APPLICABLE)
        ->and(esrFleetLevel($report, 1))->toBe(DoctorEstateCapacityLevel::FAIL)
        ->and(esrFleetLevel($report, 2))->toBe(DoctorEstateCapacityLevel::FAIL)
        ->and(esrFleetLevel($report, 3))->toBe(DoctorEstateCapacityLevel::FAIL);
});

it('does not let a tablet at another branch cover a staffed branch locally', function (): void {
    /*
     * Cross-branch authentication is valid and PROVEN — that is not what this
     * asserts. Level 1 is LOCAL OPERATIONAL COVERAGE: can this room open in the
     * morning? A tablet two cities away cannot answer yes.
     */
    $bare = esrBranch('L1H');
    $stocked = esrBranch('L1I');
    esrDoctor($bare);
    esrDoctor($stocked, 'drg Elsewhere');
    esrDevice($stocked);

    expect(esrLevel(esrReport(), 'L1H', DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE))
        ->toBe(DoctorEstateCapacityLevel::FAIL);
});

it('refuses to pass level 1 while a doctor belongs to no branch at all', function (): void {
    /*
     * THE POPULATION IS SHRINKABLE, AND SHRINKING IT USED TO TURN THE GATE
     * GREEN. An UNSET doctor is invisible to every per-branch count, so an
     * approved transfer moving the last locked doctor off a tablet-less branch
     * would have flipped this gate FAIL -> PASS with no hardware bought. The
     * count is published on the gate and any doctor outside it holds the
     * verdict at UNVERIFIED.
     */
    $branch = esrBranch('L1J');
    esrDoctor($branch);
    esrDevice($branch);

    $unhomed = User::factory()->create(['name' => 'drg Unhomed']);
    $unhomed->assignRole('Doctor');
    Doctor::factory()->create(['user_id' => $unhomed->id, 'name' => 'drg Unhomed', 'is_active' => true]);

    $gate = esrGate(esrReport(), DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE);

    expect($gate['doctors_without_home_branch'])->toBe(1)
        ->and($gate['verdict'])->toBe(DoctorEstateResilienceVerdict::UNVERIFIED);
});

it('treats a branch hosting an active cover as staffed', function (): void {
    /*
     * A cover grants a doctor time-boxed authority to work away from home and
     * deliberately does NOT move their home lock, so the covered branch counts
     * zero home doctors while a doctor stands in it. Keyed on home locks alone,
     * every level would have answered NOT_APPLICABLE for a branch seeing
     * patients on no tablet.
     */
    $home = esrBranch('L1K');
    $covered = esrBranch('L1L');
    [, $doctor] = esrDoctor($home);
    esrDevice($home);

    /*
     * Before the cover exists the branch is not in the universe at all: it
     * holds no device and homes no doctor, so nothing can reach it. That is
     * correct, and it is exactly why a covered branch has to be added by the
     * cover read — otherwise the branch seeing patients on no tablet is the one
     * branch the report cannot show.
     */
    $codes = array_map(
        static fn (array $row): ?string => $row['branch_code'],
        esrReport()['branches'],
    );

    expect($codes)->not->toContain('L1L');

    /*
     * `status` is deliberately NOT fillable — a doctor must never self-approve
     * a cover — so the approved state is set after the requester-contributed
     * create, exactly as the approval service does it.
     */
    $cover = DoctorBranchCover::query()->create([
        'doctor_id' => $doctor->id,
        'requester_user_id' => User::factory()->create()->id,
        'source_home_branch_id' => $home->id,
        'target_branch_id' => $covered->id,
        'reason' => 'Cover for capacity level test',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'requested_at' => now()->subHours(2),
    ]);

    $cover->forceFill(['status' => DoctorBranchCover::STATUS_APPROVED])->save();

    $report = esrReport();

    expect(esrBranchRow($report, 'L1L')['staffed'])->toBeTrue()
        ->and(esrLevel($report, 'L1L', DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE))
        ->toBe(DoctorEstateCapacityLevel::FAIL);
});

/*
| Level 2 — normal production room capacity
*/

it('reports level 2 PARTIAL when a staffed branch runs more doctor rooms than tablets', function (): void {
    $branch = esrBranch('L2A');
    esrDoctor($branch);
    esrDevice($branch);
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM);
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM);

    $report = esrReport();

    expect(esrBranchRow($report, 'L2A')['active_doctor_rooms'])->toBe(2)
        ->and(esrLevel($report, 'L2A', DoctorEstateCapacityLevel::ROOM_CAPACITY))
        ->toBe(DoctorEstateCapacityLevel::PARTIAL)
        ->and(esrFleetLevel($report, 2))->toBe(DoctorEstateCapacityLevel::PARTIAL);
});

it('reports level 2 PASS when tablets meet the room count', function (): void {
    $branch = esrBranch('L2B');
    esrDoctor($branch);
    esrDevice($branch);
    esrDevice($branch);
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM);
    esrRoom($branch, ClinicRoom::TYPE_CONSULTATION_ROOM);

    // A consultation room is a room a doctor and a patient meet in, so it
    // counts toward the denominator exactly as a treatment room does.
    $report = esrReport();

    expect(esrBranchRow($report, 'L2B')['active_doctor_rooms'])->toBe(2)
        ->and(esrLevel($report, 'L2B', DoctorEstateCapacityLevel::ROOM_CAPACITY))
        ->toBe(DoctorEstateCapacityLevel::PASS);
});

it('excludes inactive, soft-deleted and non-doctor rooms from the level 2 denominator', function (): void {
    $branch = esrBranch('L2C');
    esrDoctor($branch);
    esrDevice($branch);

    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM);
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM, ClinicRoom::STATUS_INACTIVE);
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM, ClinicRoom::STATUS_MAINTENANCE);
    esrRoom($branch, ClinicRoom::TYPE_XRAY_ROOM);
    esrRoom($branch, ClinicRoom::TYPE_STERILIZATION_ROOM);
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM)->delete();

    $report = esrReport();
    $row = esrBranchRow($report, 'L2C');

    // One doctor-facing room survives every exclusion; the x-ray and
    // sterilization rooms are ASSIGNABLE (the room gate offers them, since its
    // query has no type clause) and are reported as such without inflating the
    // tablet requirement.
    expect($row['active_doctor_rooms'])->toBe(1)
        ->and($row['active_assignable_rooms'])->toBe(3)
        ->and(esrLevel($report, 'L2C', DoctorEstateCapacityLevel::ROOM_CAPACITY))
        ->toBe(DoctorEstateCapacityLevel::PASS);
});

it('reports level 2 UNVERIFIED rather than PASS for a branch with no configured rooms', function (): void {
    /*
     * `(int) null === 0` would make `eligible >= rooms` true for a branch
     * holding NOTHING, so the room profile is carried as an explicit known flag
     * and branched on before any arithmetic.
     */
    $branch = esrBranch('L2D');
    esrDoctor($branch);

    $report = esrReport();

    expect(esrBranchRow($report, 'L2D')['room_profile_known'])->toBeFalse()
        ->and(esrBranchRow($report, 'L2D')['active_doctor_rooms'])->toBeNull()
        ->and(esrLevel($report, 'L2D', DoctorEstateCapacityLevel::ROOM_CAPACITY))
        ->toBe(DoctorEstateCapacityLevel::UNVERIFIED);
});

it('reports level 2 UNVERIFIED when a staffed branch runs active rooms but none a doctor works in', function (): void {
    $branch = esrBranch('L2E');
    esrDoctor($branch);
    esrDevice($branch);
    esrRoom($branch, ClinicRoom::TYPE_LAB_ROOM);

    // Dividing by zero doctor rooms would make ANY tablet count sufficient,
    // including none. Somebody is working somewhere; which room is not a
    // question this engine can answer.
    expect(esrLevel(esrReport(), 'L2E', DoctorEstateCapacityLevel::ROOM_CAPACITY))
        ->toBe(DoctorEstateCapacityLevel::UNVERIFIED);
});

it('keeps level 2 out of the gate array and out of the aggregate verdict', function (): void {
    /*
     * LEVEL 2 IS A TARGET, NOT A BLOCKER. If it were a gate, every fixture
     * without a ClinicRoom would drag the aggregate to UNVERIFIED and the
     * --strict exit code — which CI and the runbooks read — would be pinned
     * non-zero by an incremental hardware rollout.
     */
    $branch = esrBranch('L2F');
    [, $doctor] = esrDoctor($branch);
    dbaAuthorization($doctor, esrDevice($branch));
    dbaAuthorization($doctor, esrDevice($branch));
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM);
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM);
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM);

    config()->set('android_release.enforcement.concurrent_doctor_stations_per_branch', ['L2F' => 1]);

    $report = esrReport();
    $gateKeys = array_map(static fn (array $g): string => (string) $g['gate'], $report['gates']);

    expect(esrFleetLevel($report, 2))->toBe(DoctorEstateCapacityLevel::PARTIAL)
        ->and($gateKeys)->not->toContain(DoctorEstateCapacityLevel::ROOM_CAPACITY)
        ->and($report['verdict'])->toBe(DoctorEstateResilienceVerdict::PASS);
});

/*
| Independence — the three levels must be free to disagree
*/

it('reports a passing level 1 beside a failing level 3 without either moving the other', function (): void {
    /*
     * THE WHOLE POINT OF THE REVISION, IN ONE ASSERTION. One tablet at a
     * staffed branch is enough to TEST on and is not enough to survive losing
     * it. Both statements are true at once, and collapsing them is what told
     * the owner to buy four tablets when one would unblock testing.
     */
    $branch = esrBranch('IND1');
    esrDoctor($branch);
    esrDevice($branch);

    $report = esrReport();

    expect(esrFleetLevel($report, 1))->toBe(DoctorEstateCapacityLevel::PASS)
        ->and(esrFleetLevel($report, 3))->toBe(DoctorEstateCapacityLevel::FAIL)
        ->and(esrGate($report, DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE)['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($report['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL);
});

it('never lets the aggregate verdict read greener than the high availability gate', function (): void {
    /*
     * ESTATE_RESILIENCE is cited by a runbook, a closure record and a test, and
     * it currently means "worst of every gate" — never greener than Level 3.
     * Adding levels beside it must not quietly redefine it to the weaker
     * question, which is exactly how a reader gets a green on a FAILing estate.
     */
    $branch = esrBranch('AGG1');
    esrDoctor($branch);
    esrDevice($branch);

    $report = esrReport();
    $spare = esrGate($report, DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE)['verdict'];

    if ($spare !== DoctorEstateResilienceVerdict::PASS) {
        expect($report['verdict'])->not->toBe(DoctorEstateResilienceVerdict::PASS);
    }

    expect($report['verdict_semantics'])->toContain(DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE);
});

it('never reports level 1 greener than the legacy local coverage gate', function (): void {
    /*
     * The two are NOT aliases and must never be treated as one: the legacy gate
     * counts ELIGIBLE devices, Level 1 counts devices that can be LOGGED INTO.
     * Level 1 is therefore the stricter of the two, and a future edit that
     * inverted that ordering would make the activation prerequisite the weaker
     * of a pair of near-identically named gates.
     */
    $branch = esrBranch('ORD1');
    esrDoctor($branch);
    $device = esrDevice($branch, [], withCredential: false);
    esrCredential($device, revoked: true);

    $report = esrReport();

    expect(esrGate($report, 'local_trusted_device_coverage')['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::PASS)
        ->and(esrGate($report, DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE)['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::FAIL);
});

it('never marks a branch level 1 PASS while its own gaps say it holds no usable device', function (): void {
    $bare = esrBranch('CON1');
    esrDoctor($bare);

    $credentialless = esrBranch('CON2');
    esrDoctor($credentialless, 'drg Cred');
    $device = esrDevice($credentialless, [], withCredential: false);
    esrCredential($device, revoked: true);

    foreach (esrReport()['branches'] as $row) {
        $blocking = array_intersect($row['gaps'], [
            DoctorEstateResilienceVerdict::GAP_NO_LOCAL_DEVICE,
            DoctorEstateResilienceVerdict::GAP_DEVICE_WITHOUT_CREDENTIAL,
        ]);

        if ($blocking !== []) {
            expect($row['capacity_levels'][DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE])
                ->not->toBe(DoctorEstateCapacityLevel::PASS);
        }
    }
});

it('emits no gate verdict outside the three-word vocabulary', function (): void {
    /*
     * worst() fails closed on anything it does not recognise, so a PARTIAL or a
     * NOT_APPLICABLE leaking into a gate array would turn the aggregate FAIL
     * for a reason no reader could trace. The five-word capacity vocabulary
     * stops at the level block by construction; this pins it.
     */
    $branch = esrBranch('VOC1');
    esrDoctor($branch);
    esrDevice($branch);
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM);
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM);

    foreach (esrReport()['gates'] as $gate) {
        expect($gate['verdict'])->toBeIn([
            DoctorEstateResilienceVerdict::PASS,
            DoctorEstateResilienceVerdict::FAIL,
            DoctorEstateResilienceVerdict::UNVERIFIED,
        ]);
    }
});

/*
| The activation-testing prerequisite, and the attestation that may not beat it
*/

it('fails the activation prerequisite while level 1 is measured false', function (): void {
    // Pinned UNSIGNED so this isolates the MEASURED-false path. The shipped
    // config now carries a real signature (the estate was provisioned), and a
    // test that leaned on the shipped value would be testing config, not
    // behaviour — and would flip meaning the next time the estate moves.
    config()->set(
        DoctorEstateResilienceVerdict::CONFIG_ACTIVATION_TEST_ATTESTED,
        [DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE => false],
    );

    $branch = esrBranch('PRQ1');
    esrDoctor($branch);

    $report = esrReport();

    expect($report['activation_test_prerequisite']['measured'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($report['activation_test_prerequisite']['attested'])->toBeFalse()
        ->and($report['activation_test_prerequisite']['status'])->toBe(DoctorEstateResilienceVerdict::FAIL);
});

it('holds the activation prerequisite at UNVERIFIED when measured true but unsigned', function (): void {
    // "Unsigned" is the subject of this test, so it is set here rather than
    // inherited from a config that now ships signed.
    config()->set(
        DoctorEstateResilienceVerdict::CONFIG_ACTIVATION_TEST_ATTESTED,
        [DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE => false],
    );

    $branch = esrBranch('PRQ2');
    [, $doctor] = esrDoctor($branch);
    dbaAuthorization($doctor, esrDevice($branch));

    $report = esrReport();

    expect($report['activation_test_prerequisite']['measured'])->toBe(DoctorEstateResilienceVerdict::PASS)
        ->and($report['activation_test_prerequisite']['attested'])->toBeFalse()
        ->and($report['activation_test_prerequisite']['status'])->toBe(DoctorEstateResilienceVerdict::UNVERIFIED);
});

it('satisfies the activation prerequisite only when measured true AND signed', function (): void {
    $branch = esrBranch('PRQ3');
    [, $doctor] = esrDoctor($branch);
    dbaAuthorization($doctor, esrDevice($branch));

    config()->set(
        DoctorEstateResilienceVerdict::CONFIG_ACTIVATION_TEST_ATTESTED,
        [DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE => true],
    );

    expect(esrReport()['activation_test_prerequisite']['status'])
        ->toBe(DoctorEstateResilienceVerdict::PASS);
});

it('does not let a failing high availability level block the activation prerequisite', function (): void {
    /*
     * THE OWNER'S POLICY CHANGE, PINNED. One tablet at every staffed branch is
     * enough to begin controlled activation TESTING. The estate still fails
     * one-device-loss survival, that failure is still reported, and it no
     * longer stands in the way of the lower bar.
     */
    $branch = esrBranch('PRQ4');
    [, $doctor] = esrDoctor($branch);
    dbaAuthorization($doctor, esrDevice($branch));

    config()->set(
        DoctorEstateResilienceVerdict::CONFIG_ACTIVATION_TEST_ATTESTED,
        [DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE => true],
    );

    $report = esrReport();

    expect(esrFleetLevel($report, 3))->toBe(DoctorEstateCapacityLevel::FAIL)
        ->and($report['activation_test_prerequisite']['status'])->toBe(DoctorEstateResilienceVerdict::PASS)
        ->and($report['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL);
});

it('requires an unauthorized tablet to close the authorization gap before the prerequisite passes', function (): void {
    /*
     * Level 1 asks whether a branch holds a tablet somebody COULD log into. It
     * does not ask whether any doctor is authorized on it, and an unauthorized
     * tablet is a tablet nobody can use — so the prerequisite is the worst of
     * three gates rather than Level 1 alone.
     */
    $branch = esrBranch('PRQ5');
    esrDoctor($branch);
    esrDevice($branch);

    config()->set(
        DoctorEstateResilienceVerdict::CONFIG_ACTIVATION_TEST_ATTESTED,
        [DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE => true],
    );

    $report = esrReport();

    expect(esrFleetLevel($report, 1))->toBe(DoctorEstateCapacityLevel::PASS)
        ->and(esrGate($report, 'authorization_coverage')['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($report['activation_test_prerequisite']['status'])->toBe(DoctorEstateResilienceVerdict::FAIL);
});

it('fails a gate when a signature stands against a measurement', function (): void {
    /*
     * NEVER TRUST AN ATTESTATION OVER A MEASUREMENT. The previous sprint chose
     * measurement over coupling and reported a contradiction without failing
     * anything; this revision narrows that in ONE direction only — a recorded
     * `true` may no longer sit beside a measured falsehood and be reported as
     * agreement. An ABSENT signature still fails nothing.
     */
    $branch = esrBranch('ATT2');
    esrDoctor($branch);

    config()->set(
        DoctorEstateResilienceVerdict::CONFIG_ACTIVATION_TEST_ATTESTED,
        [DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE => true],
    );

    $report = esrReport();
    $gate = esrGate($report, DoctorEstateResilienceVerdict::GATE_ATTESTATION_NO_CONTRADICTION);

    expect($gate['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($gate['contradicting_prerequisites'])
        ->toContain(DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE)
        ->and($report['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL);
});

it('passes the contradiction gate on an estate where nothing is signed, and says why', function (): void {
    // An estate where NOTHING is signed is the subject here; pin it.
    config()->set(
        DoctorEstateResilienceVerdict::CONFIG_ACTIVATION_TEST_ATTESTED,
        [DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE => false],
    );

    $branch = esrBranch('ATT3');
    esrDoctor($branch);

    $gate = esrGate(esrReport(), DoctorEstateResilienceVerdict::GATE_ATTESTATION_NO_CONTRADICTION);

    // A green row here must never read as "the prerequisites are satisfied".
    expect($gate['verdict'])->toBe(DoctorEstateResilienceVerdict::PASS)
        ->and($gate['detail'])->toContain('No estate prerequisite is attested')
        ->and($gate['detail'])->toContain('NOT a statement');
});

it('flags a declared prerequisite whose signature slot has gone missing', function (): void {
    $branch = esrBranch('ATT4');
    esrDoctor($branch);
    esrDevice($branch);

    // The list still declares it; the signature block no longer has a slot for
    // it. That reads identically to "nobody has signed yet" and is in fact
    // "the list and the signatures have drifted apart".
    config()->set(DoctorEstateResilienceVerdict::CONFIG_ACTIVATION_TEST_ATTESTED, []);

    $report = esrReport();

    expect($report['attestation']['prerequisites'][DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE]['signature_slot_missing'])
        ->toBeTrue()
        ->and(esrGate($report, DoctorEstateResilienceVerdict::GATE_ATTESTATION_NO_CONTRADICTION)['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::FAIL);
});

it('keeps the spare prerequisite reading the spare gate, not the new one', function (): void {
    /*
     * `attestation.measured` is the SHIPPED shape and is cited by a test and by
     * the command. Adding a second prerequisite must not quietly repoint the
     * top-level fields at the weaker question.
     */
    $branch = esrBranch('ATT5');
    [, $doctor] = esrDoctor($branch);
    dbaAuthorization($doctor, esrDevice($branch));

    $report = esrReport();

    expect($report['attestation']['prerequisite'])->toBe(DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE)
        ->and($report['attestation']['measured'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and(esrFleetLevel($report, 1))->toBe(DoctorEstateCapacityLevel::PASS);
});

/*
| Governance surface and the command
*/

it('declares the activation prerequisite in a list the phase scanner actually reads', function (): void {
    /*
     * A DECLARED-BUT-UNREAD LIST IS THE DEFECT THIS REVISION EXISTS TO AVOID
     * REPEATING. `global_prerequisites` sat in config for two phases as five
     * strings nothing consumed. The new list is asserted by the Phase-4A
     * scanner AND measured by this engine, so neither surface is silent.
     */
    $declared = (array) config(DoctorEstateResilienceVerdict::CONFIG_ACTIVATION_TEST_PREREQUISITES);

    expect($declared)->toContain(DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE);

    $checks = collect(app(Phase4aPilotPreparationScanner::class)->scan()['checks'])
        ->keyBy('id');

    /*
     * The scanner asserts the list's INTEGRITY, not its satisfaction. A first
     * draft asserted every entry was signed `true` and turned this scanner red
     * for months over hardware that has not arrived — reddening the BOUNDED
     * PHASE-4A PILOT scanner because a LATER rung is short of tablets, which is
     * the same conflation this revision exists to end. Whether the prerequisite
     * is TRUE is measured by doctor:estate-resilience.
     */
    expect($checks->keys()->all())->toContain('activation_test_prerequisites_declared')
        ->and($checks['activation_test_prerequisites_declared']['status'])->toBe('PASS')
        ->and($checks['activation_test_prerequisites_declared']['detail'])
        ->toContain('NOT a statement that the prerequisite is satisfied');

    // Drift — declared with no signature slot — is what this check CAN catch.
    config()->set(DoctorEstateResilienceVerdict::CONFIG_ACTIVATION_TEST_ATTESTED, []);

    $after = collect(app(Phase4aPilotPreparationScanner::class)->scan()['checks'])
        ->keyBy('id');

    expect($after['activation_test_prerequisites_declared']['status'])->toBe('FAIL');
});

it('signs only the estate prerequisite whose measurement turned true', function (): void {
    /*
     * A measured falsehood may never be recorded as an attested truth — the
     * rule has not moved. What moved is the MEASUREMENT.
     *
     * DOCTOR-ACCESS-TRUSTED-DEVICE-ESTATE-PROVISIONING-1 provisioned TLK1's
     * first trusted device, Level 1 measured PASS on production, and the owner
     * signed THAT and nothing else. `spare_device_available_per_branch` is
     * still measured FAIL and is therefore still unsigned — signing it would
     * be recording something untrue, and the contradiction gate would say so.
     */
    expect(config(DoctorEstateResilienceVerdict::CONFIG_ACTIVATION_TEST_ATTESTED))
        ->toBe([DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE => true])
        ->and(config('android_release.enforcement.global_prerequisites_attested.spare_device_available_per_branch'))
        ->toBeFalse();
});

it('turns the signed prerequisite into a FAILING gate the moment the estate degrades', function (): void {
    /*
     * THE SAFETY NET BEHIND SIGNING A REAL MEASUREMENT.
     *
     * The signature now shipped is true today. If the estate later loses the
     * coverage it attests — a device revoked, a credential withdrawn, a branch
     * newly staffed with nothing in it — the signature must not go on standing.
     * It becomes a contradiction, and a contradiction fails a gate.
     */
    $branch = esrBranch('DEG1');
    esrDoctor($branch);

    // Staffed, no device: Level 1 is measured FAIL beneath a shipped `true`.
    $report = esrReport();
    $record = $report['attestation']['prerequisites'][DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE];

    expect($record['attested'])->toBeTrue()
        ->and($record['measured'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($record['contradiction'])->toBeTrue()
        ->and(esrGate($report, DoctorEstateResilienceVerdict::GATE_ATTESTATION_NO_CONTRADICTION)['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::FAIL);
});

it('prints all three capacity levels separately from the aggregate', function (): void {
    $branch = esrBranch('CMD2');
    esrDoctor($branch);
    esrDevice($branch);
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM);
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM);

    $this->artisan('doctor:estate-resilience')
        ->expectsOutputToContain('LEVEL 1  '.strtoupper(DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE).'=PASS')
        ->expectsOutputToContain('LEVEL 2  '.strtoupper(DoctorEstateCapacityLevel::ROOM_CAPACITY).'=PARTIAL')
        ->expectsOutputToContain('LEVEL 3  '.strtoupper(DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE).'=FAIL')
        ->expectsOutputToContain('OVERALL_ACTIVATION_TEST_PREREQUISITE=')
        ->expectsOutputToContain('ESTATE_RESILIENCE=FAIL')
        ->assertExitCode(0);
});

it('writes nothing while measuring three levels', function (): void {
    $branch = esrBranch('RDO2');
    [, $doctor] = esrDoctor($branch);
    dbaAuthorization($doctor, esrDevice($branch));
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM);

    $before = [
        'devices' => DB::table('mst_doctor_devices')->count(),
        'authorizations' => DB::table('mst_doctor_device_authorizations')->count(),
        'credentials' => DB::table('trx_doctor_device_webauthn_credentials')->count(),
        'locks' => DB::table('mst_doctor_branch_locks')->count(),
        'covers' => DB::table('trx_doctor_branch_covers')->count(),
        'rooms' => DB::table('mst_clinic_rooms')->count(),
    ];

    esrReport();
    esrReport();

    expect([
        'devices' => DB::table('mst_doctor_devices')->count(),
        'authorizations' => DB::table('mst_doctor_device_authorizations')->count(),
        'credentials' => DB::table('trx_doctor_device_webauthn_credentials')->count(),
        'locks' => DB::table('mst_doctor_branch_locks')->count(),
        'covers' => DB::table('trx_doctor_branch_covers')->count(),
        'rooms' => DB::table('mst_clinic_rooms')->count(),
    ])->toBe($before);
});

/*
|--------------------------------------------------------------------------
| Defects a final adversarial review found in the code above, before merge
|--------------------------------------------------------------------------
*/

it('counts a credential the login gate would refuse as no level 1 coverage', function (): void {
    /*
     * LEVEL 1 SAID "CAN BE LOGGED INTO" AND MEASURED "NOT REVOKED".
     *
     * `DoctorAppLoginGate` admits a credential only when
     * `WebAuthnDeviceBinding::isAcceptable($verdict)` holds. A credential that
     * is never revoked but whose device-binding verdict the gate refuses is
     * usable by the weaker predicate and DENIED at every login — so a branch
     * holding only that tablet reported activation-test coverage PASS while no
     * doctor could sign in on it. The engine was already computing the stricter
     * count and throwing it away.
     *
     * The two predicates stay DIFFERENT on purpose: device_credential_coverage
     * keeps its shipped "unrevoked" meaning and passes here.
     */
    config()->set('webauthn.device_binding.require_device_bound', true);

    $branch = esrBranch('ADM1');
    esrDoctor($branch);
    $device = esrDevice($branch, [], withCredential: false);

    DoctorDeviceWebAuthnCredential::query()->create([
        'uuid' => (string) Str::uuid(),
        'doctor_device_id' => $device->id,
        'credential_id' => 'cred-'.Str::random(20),
        'public_key' => 'pk-'.Str::random(24),
        'signature_counter' => 1,
        'user_verified' => true,
        'backup_eligible' => true,
        'backup_state' => true,
        'device_bound_verdict' => DoctorDeviceWebAuthnCredential::VERDICT_BACKUP_ELIGIBLE,
        'attestation_format' => 'none',
        'registered_at' => now(),
        'revoked_at' => null,
    ]);

    $report = esrReport();
    $row = esrBranchRow($report, 'ADM1');

    expect($row['eligible_device_count'])->toBe(1)
        ->and($row['eligible_devices_without_credential'])->toBe([])
        ->and($row['eligible_devices_without_admissible_credential'])->toBe([$device->id])
        ->and($row['locally_usable_device_count'])->toBe(0)
        ->and(esrLevel($report, 'ADM1', DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE))
        ->toBe(DoctorEstateCapacityLevel::FAIL)
        ->and(esrGate($report, 'device_credential_coverage')['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::PASS);
});

it('follows the binding policy rather than restating it', function (): void {
    // One implementation, two callers. Relaxing the policy relaxes the login
    // gate and this count together, because both ask the same helper.
    config()->set('webauthn.device_binding.require_device_bound', false);

    $branch = esrBranch('ADM2');
    esrDoctor($branch);
    $device = esrDevice($branch, [], withCredential: false);

    DoctorDeviceWebAuthnCredential::query()->create([
        'uuid' => (string) Str::uuid(),
        'doctor_device_id' => $device->id,
        'credential_id' => 'cred-'.Str::random(20),
        'public_key' => 'pk-'.Str::random(24),
        'signature_counter' => 1,
        'user_verified' => true,
        'backup_eligible' => true,
        'backup_state' => true,
        'device_bound_verdict' => DoctorDeviceWebAuthnCredential::VERDICT_BACKUP_ELIGIBLE,
        'attestation_format' => 'none',
        'registered_at' => now(),
        'revoked_at' => null,
    ]);

    expect(esrLevel(esrReport(), 'ADM2', DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE))
        ->toBe(DoctorEstateCapacityLevel::PASS);
});

it('reports one level 1 answer, not a guarded gate beside an unguarded table', function (): void {
    /*
     * The gate applied the incomplete-population guard and the capacity block
     * did not, so one report printed UNVERIFIED in the gate list and PASS in
     * the capacity table three lines below it, with
     * `minimum_additional_branches_to_cover: 0` beside the PASS. The capacity
     * block is the surface an operator is told to act on, so the disagreeing
     * half was the actionable one.
     */
    $branch = esrBranch('AGR1');
    esrDoctor($branch);
    esrDevice($branch);

    $unhomed = User::factory()->create(['name' => 'drg Unhomed Two']);
    $unhomed->assignRole('Doctor');
    Doctor::factory()->create(['user_id' => $unhomed->id, 'name' => 'drg Unhomed Two', 'is_active' => true]);

    $report = esrReport();
    $gate = esrGate($report, DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE);

    expect($gate['verdict'])->toBe(DoctorEstateResilienceVerdict::UNVERIFIED)
        ->and(esrFleetLevel($report, 1))->toBe(DoctorEstateCapacityLevel::UNVERIFIED)
        ->and($report['capacity_policy']['levels'][0]['status_before_population_guard'])
        ->toBe(DoctorEstateCapacityLevel::PASS)
        ->and($report['capacity_policy']['levels'][0]['doctors_without_home_branch'])->toBe(1);
});

it('cannot be passed while the fleet engine does not say how complete the population is', function (): void {
    /*
     * `?? 0` read "the key is gone" as "there is no hole", which PERMITS the
     * pass — the green direction. This module's own constant docblock records
     * the last time a silent fallthrough like that went unnoticed for a sprint.
     */
    $branch = esrBranch('UNK1');
    esrDoctor($branch);
    esrDevice($branch);

    $real = app(DoctorFleetReadinessService::class)->build();
    unset($real['unset_doctor_count']);

    $fleet = Mockery::mock(DoctorFleetReadinessService::class);
    $fleet->shouldReceive('build')->andReturn($real);
    app()->instance(DoctorFleetReadinessService::class, $fleet);

    $report = app(DoctorEstateResilienceService::class)->build();
    $gate = esrGate($report, DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE);

    expect($gate['verdict'])->toBe(DoctorEstateResilienceVerdict::UNVERIFIED)
        ->and($gate['doctors_without_home_branch'])->toBeNull()
        ->and($gate['population_complete'])->toBeFalse()
        ->and($gate['detail'])->toContain('did not report how many doctors belong to no branch');
});

it('compares a signature against the composed prerequisite it names, not one third of it', function (): void {
    /*
     * The attestation cross-check read the single Level-1 gate while the
     * prerequisite of the IDENTICAL key composed three gates, so a signature
     * standing beside a FAILING prerequisite printed "Each agrees with what
     * this engine measured". One key, two measurements, in one report.
     */
    $branch = esrBranch('CMP1');
    esrDoctor($branch);
    esrDevice($branch);

    config()->set(
        DoctorEstateResilienceVerdict::CONFIG_ACTIVATION_TEST_ATTESTED,
        [DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE => true],
    );

    $report = esrReport();
    $record = $report['attestation']['prerequisites'][DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE];

    expect(esrFleetLevel($report, 1))->toBe(DoctorEstateCapacityLevel::PASS)
        ->and(esrGate($report, 'authorization_coverage')['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($report['activation_test_prerequisite']['status'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($record['measured'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($record['contradiction'])->toBeTrue()
        ->and(esrGate($report, DoctorEstateResilienceVerdict::GATE_ATTESTATION_NO_CONTRADICTION)['verdict'])
        ->toBe(DoctorEstateResilienceVerdict::FAIL);
});

it('reports an active room a doctor could be placed in that level 2 did not size', function (): void {
    /*
     * The repository returns two room counts and says the point of returning
     * them together is that the disagreement gets reported. It was computed and
     * read by nothing. The divergence is real: the room-assignment gate offers
     * every ACTIVE room whatever its type.
     */
    $branch = esrBranch('DIV1');
    esrDoctor($branch);
    esrDevice($branch);
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM);
    esrRoom($branch, ClinicRoom::TYPE_STERILIZATION_ROOM);

    $findings = array_column(esrReport()['findings'], 'finding');

    expect($findings)->toContain('assignable_room_a_doctor_could_be_placed_in_is_not_counted_by_level_2');
});

it('does not raise the room divergence when every active room is doctor facing', function (): void {
    $branch = esrBranch('DIV2');
    esrDoctor($branch);
    esrDevice($branch);
    esrRoom($branch, ClinicRoom::TYPE_TREATMENT_ROOM);
    esrRoom($branch, ClinicRoom::TYPE_CONSULTATION_ROOM);

    $findings = array_column(esrReport()['findings'], 'finding');

    expect($findings)->not->toContain('assignable_room_a_doctor_could_be_placed_in_is_not_counted_by_level_2');
});

it('gives the activation question its own exit code, because --strict asks a harder one', function (): void {
    /*
     * `--strict` keys on the aggregate, which is never greener than high
     * availability. An activation preflight wired to it would exit 1 even once
     * every staffed branch holds a usable, authorized tablet — the conflation
     * this revision exists to end, relocated into an exit code.
     */
    $branch = esrBranch('PRE1');
    [, $doctor] = esrDoctor($branch);
    dbaAuthorization($doctor, esrDevice($branch));

    config()->set(
        DoctorEstateResilienceVerdict::CONFIG_ACTIVATION_TEST_ATTESTED,
        [DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE => true],
    );

    // The estate still fails one-device-loss survival, and says so.
    $this->artisan('doctor:estate-resilience --strict')->assertExitCode(1);

    // The activation question is satisfied, and has an exit code that says so.
    $this->artisan('doctor:estate-resilience --activation-preflight')->assertExitCode(0);
});

it('fails the activation preflight exit code while a staffed branch holds nothing', function (): void {
    $branch = esrBranch('PRE2');
    esrDoctor($branch);

    $this->artisan('doctor:estate-resilience --activation-preflight')->assertExitCode(1);
});

it('does not describe level 1 as counting a merely unrevoked credential', function (): void {
    /*
     * THE MESSAGE HAS TO FOLLOW THE PREDICATE, NOT THE PREDICATE IT REPLACED.
     *
     * Level 1 was narrowed from "unrevoked" to "the login gate would admit",
     * and two strings kept the old claim — the gate's own detail and the
     * command's column footnote. Both were read off the PRODUCTION report after
     * deploy, which is where a reader would have believed them. This is the
     * exact defect class rule 158 already records twice (a PASS message
     * outliving a narrowed verdict), reproduced by the sprint that cites it.
     *
     * device_credential_coverage keeps saying "unrevoked", because that IS its
     * predicate; the two gates must read differently.
     */
    $bare = esrBranch('MSG1');
    esrDoctor($bare);

    // A second, stocked branch so device_credential_coverage has a population
    // and prints its own predicate rather than its empty-population message.
    $stocked = esrBranch('MSG2');
    esrDoctor($stocked, 'drg Message');
    esrDevice($stocked);

    $report = esrReport();
    $level1 = esrGate($report, DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE)['detail'];

    expect($level1)->toContain('login gate would admit')
        ->and($level1)->not->toContain('carries an unrevoked credential')
        ->and(esrGate($report, 'device_credential_coverage')['detail'])
        ->toContain('UNREVOKED credential');

    $this->artisan('doctor:estate-resilience')
        ->expectsOutputToContain('LOGIN GATE WOULD ADMIT')
        ->assertExitCode(0);
});
