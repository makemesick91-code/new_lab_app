<?php

/**
 * FIX-READINESS-ESTATE-SNAPSHOT-INTEGRITY — one readiness report reads the
 * hardware estate ONCE, and the next report reads it again.
 *
 * THE DEFECT THIS FILE EXISTS TO CATCH
 *
 * DoctorFleetReadinessService memoises the device estate because three separate
 * gates need it: eligibleDeviceIds() resolves the authorization target,
 * activeAuthorizationPairs() drops a grant that names an ineligible device, and
 * deviceCoverage() builds the estate table. The memo was cleared inside
 * activeAuthorizationPairs() — which runs BETWEEN the first and third of those.
 * So a single build() loaded the estate twice and could answer one question
 * about two different moments: a 15x3 authorization matrix reported complete
 * against a coverage table listing four eligible tablets.
 *
 * That is an integrity defect, not a performance one. The report an activation
 * sprint will read has to be internally consistent before it is fast.
 *
 * WHY THE EXISTING QUERY BUDGET DID NOT CATCH IT
 *
 * DoctorFleetReadinessNonMutationTest measures two things and neither can see
 * this. CONSTANCY (`$large === $small`) is blind to it because a second estate
 * read is a CONSTANT — it does not scale with the fleet, so both sides of the
 * comparison move together. The CEILING (`<= 24`) is blind to it because one
 * extra query fits inside the headroom, and the headroom is deliberate. Its
 * comment nonetheless said the sprint added "ONE memoised estate read", which
 * was the property everybody believed was pinned and nothing was asserting.
 *
 * So this file counts LOADS, at the loader, rather than inferring them from SQL.
 *
 * WHY THE SPY IS INJECTED RATHER THAN BOUND IN THE CONTAINER
 *
 * DoctorGlobalRolloutReadinessService — which this engine COMPOSES — takes the
 * same repository and loads the estate TWICE itself, unmemoised
 * (DoctorGlobalRolloutReadinessService.php:417 and :471). A container-wide bind
 * would therefore count THREE loads per report, two of which belong to a
 * different engine, and "exactly one" would be unassertable.
 *
 * So the property this file pins is precise and narrow: ONE build of
 * DoctorFleetReadinessService loads the estate once THROUGH ITS OWN
 * DEPENDENCY. The composed engine's two reads are real, are out of this
 * sprint's scope, and are not claimed to be fixed — they feed only values the
 * fleet report discards (its `branches` and `devices` blocks are dropped; it
 * carries forward verdict, counts, blocking_reasons, findings and runtime,
 * none of which read the estate). The spy is handed to the service under test
 * and to nothing else, so the number this file reports is that service's own.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 *
 * That the numbers in the report are right — that is DoctorFleetReadinessTest's
 * job. This proves only that however they are computed, one report computes
 * them against one estate.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Interfaces\DoctorBranchLockRepositoryInterface;
use App\Modules\DoctorAccess\Interfaces\DoctorFleetReadinessRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorBranchLock;
use App\Modules\DoctorAccess\Services\DoctorFleetReadinessService;
use App\Modules\DoctorAccess\Support\DoctorFleetReadinessVerdict;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceRolloutReadinessRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorDeviceIdentityProofPolicy;
use App\Modules\DoctorDevice\Services\DoctorGlobalRolloutReadinessService;
use App\Modules\LabOrder\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // The population is keyed on the Doctor ROLE. Without the real roles every
    // fixture is a user with no role, the engine correctly reports an empty
    // fleet, and every load count below would be a count of nothing.
    seedAccessControl();
});

/*
 * FIXTURES CARRY A FILE-UNIQUE `snapshot` PREFIX.
 *
 * Pest shares declared functions across the files it has already loaded, which
 * makes borrowing a sibling suite's fixtures look like it works — right up
 * until this file is run on its own, or first, and every test errors on an
 * undefined function. DoctorFleetReadinessNonMutationTest declares its own for
 * the same reason. These are deliberately minimal: this suite needs a doctor
 * the engine will count, not the full ceremony.
 */

function snapshotBranch(string $code): Branch
{
    return Branch::factory()->create([
        'code' => $code,
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
}

/** @return array{0: User, 1: Doctor} */
function snapshotDoctor(string $name): array
{
    $user = User::factory()->create(['name' => $name]);
    $user->assignRole('Doctor');

    $doctor = Doctor::factory()->create([
        'user_id' => $user->id,
        'name' => $name,
        'is_active' => true,
    ]);

    return [$user, $doctor];
}

/** An ACTIVE, cryptographically verified tablet carrying a usable credential. */
function snapshotDevice(): DoctorDevice
{
    $device = DoctorDevice::factory()->create([
        'status' => DoctorDevice::STATUS_ACTIVE,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'branch_id' => snapshotBranch('SNB'.Str::random(4))->id,
    ]);

    // The credential belongs to the DEVICE, never to a doctor — which is why a
    // shared clinic tablet is representable at all.
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

    return $device;
}

function snapshotAuthorize(Doctor $doctor, DoctorDevice $device): DoctorDeviceAuthorization
{
    return DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);
}

function snapshotLock(Doctor $doctor, Branch $branch): DoctorBranchLock
{
    $lock = new DoctorBranchLock;

    // `$fillable` is deliberately empty on this model, so the repository writes
    // with forceFill and so does this fixture.
    $lock->forceFill([
        'doctor_id' => $doctor->id,
        'home_branch_id' => $branch->id,
        'established_via' => DoctorBranchLock::VIA_INITIAL_ASSIGNMENT,
        'established_at' => now(),
        'transfer_count' => 0,
    ])->save();

    return $lock;
}

/** A durable, doctor-attributed record that a device login actually succeeded. */
function snapshotProof(User $user, DoctorDevice $device, Doctor $doctor): AuditLog
{
    return AuditLog::query()->create([
        'entity_type' => 'trx_doctor_device_webauthn_credentials',
        'entity_id' => 1,
        'action' => DoctorFleetReadinessRepositoryInterface::PROOF_ACTIONS[1],
        'new_values' => [
            'doctor_device_id' => (int) $device->id,
            'doctor_id' => (int) $doctor->id,
        ],
        'performed_by' => $user->id,
        'performed_at' => now(),
    ]);
}

function snapshotRowFor(array $report, User $user): array
{
    $row = collect($report['doctors'])->firstWhere('user_id', (int) $user->id);

    expect($row)->not->toBeNull();

    return $row;
}

/** Every gate cleared: linked, active, locked, fully authorized, proven. */
function snapshotReadyDoctor(string $name): array
{
    [$user, $doctor] = snapshotDoctor($name);
    $device = snapshotDevice();

    snapshotAuthorize($doctor, $device);
    snapshotLock($doctor, snapshotBranch('SNH'.Str::random(3)));
    snapshotProof($user, $device, $doctor);

    return [$user, $doctor, $device];
}

/**
 * Counts deviceEstate() loads and can replay a SCRIPTED sequence of snapshots.
 *
 * Everything else is delegated to the real repository, so the engine under test
 * is reading real rows through its real collaborators — only the estate read is
 * observed, and only its RETURN is scripted.
 *
 * The script is indexed by call number, not by wall-clock or by a race, so a
 * mixed-snapshot failure is deterministic: with two loads the engine sees entry
 * 0 then entry 1, every run, on every machine.
 */
final class SnapshotSpyEstateRepository implements DoctorDeviceRolloutReadinessRepositoryInterface
{
    public int $deviceEstateLoads = 0;

    /** @var list<Collection<int, DoctorDevice>> */
    private array $script;

    /** @param list<Collection<int, DoctorDevice>> $script */
    public function __construct(
        private readonly DoctorDeviceRolloutReadinessRepositoryInterface $inner,
        array $script = [],
    ) {
        $this->script = $script;
    }

    public function doctorAccounts(): Collection
    {
        return $this->inner->doctorAccounts();
    }

    public function doctorRecordsForUsers(array $userIds): Collection
    {
        return $this->inner->doctorRecordsForUsers($userIds);
    }

    public function authorizationsForDoctors(array $doctorIds): Collection
    {
        return $this->inner->authorizationsForDoctors($doctorIds);
    }

    public function deviceEstate(): Collection
    {
        $index = $this->deviceEstateLoads;
        $this->deviceEstateLoads++;

        if ($this->script === []) {
            return $this->inner->deviceEstate();
        }

        // Past the end of the script the LAST snapshot repeats. A scripted test
        // is asserting how many loads happen and what the first ones see; it
        // must not also fail with an undefined index if the count regresses.
        return $this->script[$index] ?? $this->script[array_key_last($this->script)];
    }
}

/**
 * The service under test, wired with a spy for its OWN estate dependency and
 * the real container bindings for everything else.
 *
 * @param  list<Collection<int, DoctorDevice>>  $script
 * @return array{0: DoctorFleetReadinessService, 1: SnapshotSpyEstateRepository}
 */
function snapshotServiceWithSpy(array $script = []): array
{
    $spy = new SnapshotSpyEstateRepository(
        app(DoctorDeviceRolloutReadinessRepositoryInterface::class),
        $script,
    );

    $service = new DoctorFleetReadinessService(
        app(DoctorGlobalRolloutReadinessService::class),
        app(DoctorBranchLockRepositoryInterface::class),
        app(DoctorFleetReadinessRepositoryInterface::class),
        $spy,
        app(DoctorDeviceIdentityProofPolicy::class),
    );

    return [$service, $spy];
}

/** A live estate snapshot, taken from the real repository at this instant. */
function snapshotEstateNow(): Collection
{
    return app(DoctorDeviceRolloutReadinessRepositoryInterface::class)->deviceEstate();
}

/** The eligible device ids the coverage table reports, ascending. */
function snapshotCoverageDeviceIds(array $report): array
{
    $ids = array_map(
        static fn (array $row): int => (int) $row['device_id'],
        $report['devices']['rows'] ?? [],
    );

    sort($ids);

    return $ids;
}

/**
 * The eligible device ids the PER-DOCTOR gates were computed against.
 *
 * Every doctor row carries the whole eligible estate, split into the tablets
 * they hold and the ones they do not, so their union is the population
 * eligibleDeviceIds() returned — read back out of the report rather than
 * recomputed, which is the point.
 */
function snapshotDoctorGateDeviceIds(array $report): array
{
    $ids = [];

    foreach ($report['doctors'] as $row) {
        foreach ([...$row['authorized_device_ids'], ...$row['unauthorized_eligible_device_ids']] as $id) {
            $ids[(int) $id] = true;
        }
    }

    $ids = array_keys($ids);
    sort($ids);

    return $ids;
}

// ---------------------------------------------------------------------------
// A + B — one build, one load, shared by every consumer
// ---------------------------------------------------------------------------

it('loads the device estate exactly once for one readiness report', function () {
    snapshotReadyDoctor('drg One Load');

    [$service, $spy] = snapshotServiceWithSpy();

    $report = $service->build();

    /*
     * The whole defect in one number. Before the fix this was 2: the memo was
     * cleared by activeAuthorizationPairs(), which runs after the first reader
     * and before the last one.
     */
    expect($spy->deviceEstateLoads)->toBe(1);

    // And the report is real, so the count above is not one on an empty path.
    expect($report['eligible_doctor_count'])->toBe(1);
});

it('serves every estate consumer in one build from the same load', function () {
    // Three eligible tablets means all three consumers have something to
    // disagree about: the target matrix, the pair filter and the coverage table.
    $devices = [snapshotDevice(), snapshotDevice(), snapshotDevice()];

    [$user, $doctor] = snapshotDoctor('drg Shared Estate');
    snapshotLock($doctor, snapshotBranch('SHR1'));

    foreach ($devices as $device) {
        snapshotAuthorize($doctor, $device);
        snapshotProof($user, $device, $doctor);
    }

    [$service, $spy] = snapshotServiceWithSpy();

    $report = $service->build();

    expect($spy->deviceEstateLoads)->toBe(1);

    // The two independently-derived views of "which tablets are eligible" —
    // one from the per-doctor gates, one from the coverage table — are the
    // same list, which is what a single snapshot guarantees.
    expect(snapshotDoctorGateDeviceIds($report))->toBe(snapshotCoverageDeviceIds($report));
    expect($report['devices']['eligible_count'])->toBe(3);
    expect($report['authorization_matrix']['target_pairs'])->toBe(3);
});

// ---------------------------------------------------------------------------
// D — the adversarial mixed-snapshot probe
// ---------------------------------------------------------------------------

it('never mixes two estate snapshots inside one report', function () {
    $stays = snapshotDevice();
    $revoked = snapshotDevice();

    [$user, $doctor] = snapshotDoctor('drg Mixed Snapshot');
    snapshotLock($doctor, snapshotBranch('MIX1'));
    snapshotAuthorize($doctor, $stays);
    snapshotAuthorize($doctor, $revoked);
    snapshotProof($user, $stays, $doctor);

    // Snapshot A: both tablets eligible.
    $a = snapshotEstateNow();

    $revoked->forceFill(['status' => DoctorDevice::STATUS_REVOKED])->save();

    // Snapshot B: one tablet eligible. A report that loads twice sees A then B.
    $b = snapshotEstateNow();

    [$service, $spy] = snapshotServiceWithSpy([$a, $b]);

    $report = $service->build();

    expect($spy->deviceEstateLoads)->toBe(1);

    /*
     * THE ASSERTION THE DEFECT FAILED.
     *
     * With two loads the per-doctor gates were computed against A (two eligible
     * tablets, so a target of two pairs) while the coverage table was built
     * from B (one eligible tablet). Both numbers were individually correct and
     * the report was incoherent.
     *
     * These agree by construction the moment both come from one snapshot, and
     * they cannot agree by accident here: A and B differ.
     */
    expect(snapshotDoctorGateDeviceIds($report))->toBe(snapshotCoverageDeviceIds($report));
    expect($report['authorization_matrix']['target_pairs'])
        ->toBe($report['devices']['eligible_count'] * 1);

    // Pinning WHICH snapshot won as well, so a future "fix" that consistently
    // used the LAST load would not pass this test by being uniformly late.
    expect($report['devices']['eligible_count'])->toBe(2);
});

// ---------------------------------------------------------------------------
// C + F — the memo is scoped to one build, not to the service
// ---------------------------------------------------------------------------

it('reloads the estate for the next report rather than serving a stale one', function () {
    $stays = snapshotDevice();
    $revoked = snapshotDevice();

    [$user, $doctor] = snapshotDoctor('drg Fresh Build');
    snapshotLock($doctor, snapshotBranch('FRS1'));
    snapshotAuthorize($doctor, $stays);
    snapshotAuthorize($doctor, $revoked);
    snapshotProof($user, $stays, $doctor);

    $a = snapshotEstateNow();

    $revoked->forceFill(['status' => DoctorDevice::STATUS_REVOKED])->save();

    $b = snapshotEstateNow();

    [$service, $spy] = snapshotServiceWithSpy([$a, $b]);

    $first = $service->build();
    $second = $service->build();

    /*
     * One load PER BUILD, two builds, two loads. Caching the estate forever
     * would also make the mixed-snapshot test above pass, and would be a worse
     * bug than the one being fixed: a long-lived process would report the
     * hardware it saw the first time, indefinitely.
     */
    expect($spy->deviceEstateLoads)->toBe(2);

    expect($first['devices']['eligible_count'])->toBe(2);
    expect($second['devices']['eligible_count'])->toBe(1);

    // Each report is internally consistent on its own terms.
    expect(snapshotDoctorGateDeviceIds($first))->toBe(snapshotCoverageDeviceIds($first));
    expect(snapshotDoctorGateDeviceIds($second))->toBe(snapshotCoverageDeviceIds($second));
});

it('keeps the duplicate-pair tally per report, and the database keeps it at zero', function () {
    [$user, $doctor] = snapshotDoctor('drg Duplicate Tally');
    $device = snapshotDevice();

    snapshotLock($doctor, snapshotBranch('DUP1'));
    snapshotAuthorize($doctor, $device);
    snapshotProof($user, $device, $doctor);

    /*
     * A SECOND ACTIVE ROW FOR THE PAIR IS NOT REPRESENTABLE.
     *
     * `mst_dd_authorizations_pair_unique` is a FULL unique on
     * (doctor_id, doctor_device_id) — not partial, not status-scoped — on
     * PostgreSQL and SQLite alike. The service's own comment used to say no
     * such index existed, which is why the tally was described as load-bearing.
     * It is defence in depth, and this is what actually holds the line.
     *
     * Asserting the REJECTION rather than the index name keeps this honest: if
     * somebody drops that index, this test turns red and the counter below
     * stops being decorative.
     *
     * THE VIOLATION MUST BE CONTAINED IN A SAVEPOINT, AND THAT IS NOT OPTIONAL.
     *
     * PostgreSQL aborts the WHOLE transaction on any failed statement —
     * SQLSTATE 25P02, "current transaction is aborted, commands ignored until
     * end of transaction block". Under RefreshDatabase this test body already
     * runs inside one transaction, so a bare violating insert poisons it and
     * every later query here dies, build() included. SQLite does not behave
     * that way and hides the entire problem locally: an earlier revision of
     * this test passed on SQLite and failed in CI on postgres:16 for precisely
     * that reason.
     *
     * A nested DB::transaction() compiles to SAVEPOINT / ROLLBACK TO SAVEPOINT,
     * and rolling back to a savepoint taken BEFORE the failing statement
     * returns the transaction to a usable state. The rejection is therefore
     * still exercised against the real constraint, and the connection survives
     * it on both drivers.
     */
    expect(fn () => DB::transaction(fn () => snapshotAuthorize($doctor, $device)))
        ->toThrow(QueryException::class);

    // The connection is still usable — the half SQLite would never have told
    // us about, and the reason the assertion above is wrapped rather than bare.
    expect(DoctorDeviceAuthorization::query()->count())->toBe(1);

    [$service, $spy] = snapshotServiceWithSpy();

    $first = $service->build();
    $second = $service->build();

    /*
     * The duplicate reset STAYED in activeAuthorizationPairs() when the estate
     * reset moved out to build(). Two builds on one instance must therefore
     * still report a per-report figure rather than an accumulating one — the
     * property that reset exists for, and the one a careless "move both" would
     * have broken.
     */
    expect($first['authorization_matrix']['duplicate_active_pairs'])->toBe(0);
    expect($second['authorization_matrix']['duplicate_active_pairs'])->toBe(0);
    expect($spy->deviceEstateLoads)->toBe(2);
});

// ---------------------------------------------------------------------------
// E + G + I — the gates the snapshot feeds still say what they said
// ---------------------------------------------------------------------------

it('still refuses a proof on a tablet that is ineligible in the one snapshot it reads', function () {
    [$user, $doctor] = snapshotDoctor('drg Revoked Proof');
    $revoked = snapshotDevice();
    $usable = snapshotDevice();

    snapshotLock($doctor, snapshotBranch('REV1'));
    snapshotAuthorize($doctor, $revoked);
    snapshotAuthorize($doctor, $usable);

    // The ONLY proof names the tablet that is about to be revoked.
    snapshotProof($user, $revoked, $doctor);

    $revoked->forceFill(['status' => DoctorDevice::STATUS_REVOKED])->save();

    [$service, $spy] = snapshotServiceWithSpy();

    $report = $service->build();
    $row = snapshotRowFor($report, $user);

    expect($spy->deviceEstateLoads)->toBe(1);
    expect($row['proven_device_ids'])->toContain((int) $revoked->id);
    expect($row['qualifying_device_ids'])->toBe([]);
    expect($row['real_device_login_proven'])->toBeFalse();
    expect($row['blockers'])->toContain(DoctorFleetReadinessVerdict::BLOCKER_LOGIN_NOT_PROVEN);
    expect($row['state'])->toBe(DoctorFleetReadinessVerdict::STATE_NOT_READY);
});

it('discards the proofs on a revoked tablet without disqualifying the doctor', function () {
    /*
     * THE PRODUCTION SHAPE, AND THE DIRECTION NOTHING ELSE ASSERTED.
     *
     * Every other proof test in both suites gives its doctor proofs on ONE
     * tablet, so they only ever exercise "all proofs qualify" or "no proof
     * qualifies". The estate this engine actually reports on is mixed: measured
     * 2026-09-13, user 18 held 23 rows across three tablets — 19 on hardware
     * that still qualifies and 4 on revoked device 1 — and stays READY.
     *
     * A gate that discarded the revoked rows by disqualifying the doctor would
     * pass every single-device test and be wrong on the only estate that
     * matters. The service comment calls this "the shape this gate has to get
     * right in both directions"; this is the other direction.
     */
    [$user, $doctor] = snapshotDoctor('drg Mixed Proof');
    $keeps = snapshotDevice();
    $revoked = snapshotDevice();

    snapshotLock($doctor, snapshotBranch('MXP1'));
    snapshotAuthorize($doctor, $keeps);
    snapshotAuthorize($doctor, $revoked);

    // Two proofs on the tablet that will be revoked, one on the tablet that
    // survives — so the DISCARDED rows outnumber the qualifying one, and a gate
    // that counted rows rather than hardware would reach the wrong answer.
    snapshotProof($user, $revoked, $doctor);
    snapshotProof($user, $revoked, $doctor);
    snapshotProof($user, $keeps, $doctor);

    $revoked->forceFill(['status' => DoctorDevice::STATUS_REVOKED])->save();

    [$service, $spy] = snapshotServiceWithSpy();

    $report = $service->build();
    $row = snapshotRowFor($report, $user);

    expect($spy->deviceEstateLoads)->toBe(1);

    // The raw count still reports all three: "logged in only on hardware we no
    // longer trust" and "never logged in" must stay distinguishable.
    expect($row['real_device_login_count'])->toBe(3);
    expect($row['proven_device_ids'])->toContain((int) $revoked->id, (int) $keeps->id);

    // But only the surviving tablet qualifies, and the doctor is still READY.
    expect($row['qualifying_device_ids'])->toBe([(int) $keeps->id]);
    expect($row['real_device_login_proven'])->toBeTrue();
    expect($row['blockers'])->not->toContain(DoctorFleetReadinessVerdict::BLOCKER_LOGIN_NOT_PROVEN);

    // The revoked tablet also leaves the eligible estate, so the matrix narrows
    // with it rather than reporting a gap the doctor cannot close.
    expect($report['devices']['eligible_count'])->toBe(1);
    expect($row['unauthorized_eligible_device_ids'])->toBe([]);
    expect($row['state'])->toBe(DoctorFleetReadinessVerdict::STATE_READY);
});

it('still counts the authorization matrix against the whole eligible estate', function () {
    $a = snapshotDevice();
    $b = snapshotDevice();

    [$user, $doctor] = snapshotDoctor('drg Matrix');
    snapshotLock($doctor, snapshotBranch('MTX1'));
    snapshotAuthorize($doctor, $a);
    snapshotProof($user, $a, $doctor);

    [$service, $spy] = snapshotServiceWithSpy();

    $report = $service->build();
    $row = snapshotRowFor($report, $user);

    expect($spy->deviceEstateLoads)->toBe(1);
    expect($report['authorization_matrix']['target_pairs'])->toBe(2);
    expect($report['authorization_matrix']['active_pairs'])->toBe(1);
    expect($report['authorization_matrix']['missing_pairs'])->toBe(1);
    expect($row['unauthorized_eligible_device_ids'])->toBe([(int) $b->id]);
    expect($row['blockers'])->toContain(DoctorFleetReadinessVerdict::BLOCKER_AUTHORIZATION_GAP);
});

// ---------------------------------------------------------------------------
// K..Q — a snapshot-scoped report still writes nothing
// ---------------------------------------------------------------------------

it('writes nothing while reading the estate once', function () {
    [$user, $doctor, $device] = snapshotReadyDoctor('drg Read Only');

    $before = [
        'audit' => AuditLog::query()->count(),
        'devices' => DoctorDevice::query()->count(),
        'authorizations' => DoctorDeviceAuthorization::query()->count(),
        'deviceStatus' => DoctorDevice::query()->find($device->id)->status,
    ];

    [$service, $spy] = snapshotServiceWithSpy();

    $service->build();
    $service->build();

    expect($spy->deviceEstateLoads)->toBe(2);
    expect(AuditLog::query()->count())->toBe($before['audit']);
    expect(DoctorDevice::query()->count())->toBe($before['devices']);
    expect(DoctorDeviceAuthorization::query()->count())->toBe($before['authorizations']);
    expect(DoctorDevice::query()->find($device->id)->status)->toBe($before['deviceStatus']);
});
