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
use App\Modules\DoctorAccess\Services\DoctorEstateResilienceService;
use App\Modules\DoctorAccess\Support\DoctorEstateResilienceVerdict;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
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

it('counts only active, cryptographically verified hardware as eligible', function (): void {
    $branch = esrBranch('ELG1');
    esrDoctor($branch);

    $eligible = esrDevice($branch);
    $unverified = esrDevice($branch, ['identity_state' => DoctorDevice::IDENTITY_UNVERIFIED]);
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
    expect(fn () => dbaAuthorization($doctor, $device))
        ->toThrow(UniqueConstraintViolationException::class);

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

it('reports a contradiction between what is signed and what is measured, and gates nothing on it', function (): void {
    $branch = esrBranch('ATT1');
    esrDoctor($branch);
    esrDevice($branch);

    config()->set('android_release.enforcement.global_prerequisites_attested.spare_device_available_per_branch', true);

    $report = esrReport();

    expect($report['attestation']['attested'])->toBeTrue()
        ->and($report['attestation']['measured'])->toBe(DoctorEstateResilienceVerdict::FAIL)
        ->and($report['attestation']['contradiction'])->toBeTrue();

    // The owner chose measurement over coupling: a signature cannot rescue the
    // measurement, and the measurement does not overwrite the signature.
    expect(config('android_release.enforcement.global_prerequisites_attested.spare_device_available_per_branch'))
        ->toBeTrue()
        ->and($report['verdict'])->toBe(DoctorEstateResilienceVerdict::FAIL);
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

it('reports active treatment rooms without letting them decide any verdict', function (): void {
    $branch = esrBranch('ROOM');
    [, $doctor] = esrDoctor($branch);
    dbaAuthorization($doctor, esrDevice($branch));
    dbaAuthorization($doctor, esrDevice($branch));

    // One active treatment room. If the engine were quietly using rooms as the
    // station count it would now compute stations=1, require 2, hold 2 and
    // report PASS. It must not: a room inventory is not a peak concurrent
    // staffed station count, and substituting one for the other is the same
    // failure as guessing, only with a more plausible number.
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
