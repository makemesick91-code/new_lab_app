<?php

/**
 * DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1 — is the FLEET ready, as opposed to
 * merely provisioned?
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM DoctorGlobalRolloutReadinessTest
 *
 * That suite proves the five conditions of a trusted PATH. This one proves the
 * three things a path does not tell you: that the doctor has an approved home
 * branch, that they are authorized across the whole eligible estate, and that
 * somebody has actually walked the path.
 *
 * THE SPECIFIC TRAP THIS FILE EXISTS TO CATCH
 *
 * On 2026-09-12 a bulk authorization run wrote 45 correct rows and the
 * provisioning verdict went from PARTIAL to its top value — while twelve
 * clinicians who had never logged in on a tablet were exactly as unready as the
 * day before. Every assertion below that looks pedantic is guarding that shape:
 * evidence that is ABSENT must never read as evidence that PASSED, and an
 * empty fleet must never satisfy "every doctor is ready" for free.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 *
 * Nothing about a real tablet. An audit row saying a login succeeded is a row.
 * And even on production, that row records that an ACCOUNT reached a session
 * through a TABLET — the clinician is identified by the password step, never by
 * the credential, which has no doctor column by design.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Interfaces\DoctorFleetReadinessRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorBranchCover;
use App\Modules\DoctorAccess\Models\DoctorBranchLock;
use App\Modules\DoctorAccess\Services\DoctorFleetReadinessService;
use App\Modules\DoctorAccess\Support\DoctorFleetReadinessVerdict;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\LabOrder\Models\AuditLog;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // The population is keyed on the Doctor ROLE. Without the real roles, every
    // fixture is a user with no role, the engine correctly reports an empty
    // fleet, and the suite goes green having proven nothing.
    seedAccessControl();
});

/** Helpers carry a file-unique `fleet` prefix: Pest shares these across files. */
function fleetReadiness(): array
{
    return app(DoctorFleetReadinessService::class)->build();
}

function fleetDoctor(string $name = 'drg Fleet', array $doctorAttributes = []): array
{
    $user = User::factory()->create(['name' => $name]);
    $user->assignRole('Doctor');

    $doctor = Doctor::factory()->create(array_merge([
        'user_id' => $user->id,
        'name' => $name,
        'is_active' => true,
    ], $doctorAttributes));

    return [$user, $doctor];
}

function fleetBranch(string $code): Branch
{
    return Branch::factory()->create([
        'code' => $code,
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
}

function fleetDevice(array $attributes = [], ?Branch $branch = null): DoctorDevice
{
    $device = DoctorDevice::factory()->create(array_merge([
        'status' => DoctorDevice::STATUS_ACTIVE,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'branch_id' => $branch?->id ?? fleetBranch('B'.Str::random(4))->id,
    ], $attributes));

    // A credential belongs to the DEVICE, never to a doctor — that is the whole
    // reason a shared clinic tablet is representable. Inserted directly because
    // this suite needs the row, not the ceremony that mints it.
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

function fleetAuthorize(Doctor $doctor, DoctorDevice $device): DoctorDeviceAuthorization
{
    return DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);
}

function fleetLock(Doctor $doctor, Branch $branch): DoctorBranchLock
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
function fleetProof(User $user, DoctorDevice $device, ?Doctor $doctor = null, ?string $action = null): AuditLog
{
    return AuditLog::query()->create([
        'entity_type' => 'trx_doctor_device_webauthn_credentials',
        'entity_id' => 1,
        'action' => $action ?? DoctorFleetReadinessRepositoryInterface::PROOF_ACTIONS[1],
        'new_values' => [
            'doctor_device_id' => (int) $device->id,
            'doctor_id' => $doctor === null ? null : (int) $doctor->id,
        ],
        'performed_by' => $user->id,
        'performed_at' => now(),
    ]);
}

function fleetRowFor(array $report, User $user): array
{
    $row = collect($report['doctors'])->firstWhere('user_id', (int) $user->id);

    expect($row)->not->toBeNull();

    return $row;
}

/** Every gate cleared: linked, active, locked, fully authorized, proven. */
function fleetReadyDoctor(string $name = 'drg Ready', ?Branch $home = null, ?DoctorDevice $device = null): array
{
    [$user, $doctor] = fleetDoctor($name);
    $device ??= fleetDevice();
    $home ??= fleetBranch('HOME'.Str::random(3));

    fleetAuthorize($doctor, $device);
    fleetLock($doctor, $home);
    fleetProof($user, $device, $doctor);

    return [$user, $doctor, $device, $home];
}

// ---------------------------------------------------------------------------
// The complete fleet gate
// ---------------------------------------------------------------------------

it('marks a doctor fleet-ready only when branch, authorization and a real login all hold', function () {
    [$user] = fleetReadyDoctor();

    $report = fleetReadiness();
    $row = fleetRowFor($report, $user);

    expect($row['state'])->toBe(DoctorFleetReadinessVerdict::STATE_READY);
    expect($row['blockers'])->toBe([]);
    expect($row['primary_blocker'])->toBeNull();
    expect($report['verdict'])->toBe(DoctorFleetReadinessVerdict::READY);
});

// ---------------------------------------------------------------------------
// Branch
// ---------------------------------------------------------------------------

it('refuses a doctor with no home branch lock even when every other gate passes', function () {
    [$user, $doctor] = fleetDoctor();
    $device = fleetDevice();
    fleetAuthorize($doctor, $device);
    fleetProof($user, $device, $doctor);
    // Deliberately no lock: UNSET is the owner's compatibility state.

    $row = fleetRowFor(fleetReadiness(), $user);

    expect($row['home_branch_locked'])->toBeFalse();
    expect($row['home_branch_code'])->toBeNull();
    expect($row['blockers'])->toContain(DoctorFleetReadinessVerdict::BLOCKER_HOME_BRANCH_UNSET);
    expect($row['state'])->toBe(DoctorFleetReadinessVerdict::STATE_NOT_READY);
});

it('never lets the tablet\'s branch become the doctor\'s home branch', function () {
    $tabletBranch = fleetBranch('ATG3');
    $homeBranch = fleetBranch('SPN4');

    $device = fleetDevice([], $tabletBranch);
    [$user] = fleetReadyDoctor('drg Cross', $homeBranch, $device);

    $row = fleetRowFor(fleetReadiness(), $user);

    // The live shape of drg Karmila: home SPN4, authorized on an ATG3 tablet.
    expect($row['home_branch_code'])->toBe('SPN4');
    expect($row['home_branch_id'])->toBe((int) $homeBranch->id);
    expect($row['state'])->toBe(DoctorFleetReadinessVerdict::STATE_READY);
});

it('does not let a temporary cover rewrite the reported home branch', function () {
    $home = fleetBranch('SPN4');
    $covered = fleetBranch('LDK2');

    [$user, $doctor] = fleetReadyDoctor('drg Covered', $home);

    DoctorBranchCover::query()->create([
        'doctor_id' => $doctor->id,
        'requester_user_id' => $user->id,
        'source_home_branch_id' => $home->id,
        'target_branch_id' => $covered->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'reason' => 'Cover for readiness regression',
        'requested_at' => now()->subDay(),
        'status' => DoctorBranchCover::STATUS_APPROVED,
        'decided_at' => now()->subDay(),
    ]);

    $row = fleetRowFor(fleetReadiness(), $user);

    // A cover moves where a doctor WORKS today. It never moves where they BELONG.
    expect($row['home_branch_code'])->toBe('SPN4');
});

// ---------------------------------------------------------------------------
// Authorization matrix
// ---------------------------------------------------------------------------

it('recognises a complete authorization matrix across every eligible tablet', function () {
    $a = fleetDevice();
    $b = fleetDevice();

    [$user, $doctor] = fleetDoctor();
    fleetAuthorize($doctor, $a);
    fleetAuthorize($doctor, $b);
    fleetLock($doctor, fleetBranch('SPN4'));
    fleetProof($user, $a, $doctor);

    $report = fleetReadiness();
    $row = fleetRowFor($report, $user);

    expect($row['unauthorized_eligible_device_ids'])->toBe([]);
    expect($row['blockers'])->not->toContain(DoctorFleetReadinessVerdict::BLOCKER_AUTHORIZATION_GAP);
    expect($report['authorization_matrix']['target_pairs'])->toBe(2);
    expect($report['authorization_matrix']['active_pairs'])->toBe(2);
    expect($report['authorization_matrix']['missing_pairs'])->toBe(0);
});

it('blocks a doctor who is missing an authorization on one eligible tablet', function () {
    $a = fleetDevice();
    $b = fleetDevice();

    [$user, $doctor] = fleetDoctor();
    fleetAuthorize($doctor, $a);
    // No authorization on $b.
    fleetLock($doctor, fleetBranch('SPN4'));
    fleetProof($user, $a, $doctor);

    $report = fleetReadiness();
    $row = fleetRowFor($report, $user);

    expect($row['unauthorized_eligible_device_ids'])->toBe([(int) $b->id]);
    expect($row['blockers'])->toContain(DoctorFleetReadinessVerdict::BLOCKER_AUTHORIZATION_GAP);
    expect($row['state'])->toBe(DoctorFleetReadinessVerdict::STATE_NOT_READY);
    expect($report['authorization_matrix']['missing_pairs'])->toBe(1);
});

it('never lets an authorization on a revoked tablet close a matrix gap', function () {
    $live = fleetDevice();
    $revoked = fleetDevice(['status' => DoctorDevice::STATUS_REVOKED, 'revoked_at' => now()]);

    [$user, $doctor] = fleetReadyDoctor('drg Revoked', null, $live);
    fleetAuthorize($doctor, $revoked);

    $report = fleetReadiness();
    $row = fleetRowFor($report, $user);

    // The revoked tablet is in the estate and contributes to neither side.
    expect($report['devices']['estate_count'])->toBe(2);
    expect($report['devices']['eligible_count'])->toBe(1);
    expect($report['authorization_matrix']['target_pairs'])->toBe(1);
    expect($row['authorized_device_ids'])->toBe([(int) $live->id]);
});

it('excludes a device whose identity was never cryptographically verified', function () {
    fleetReadyDoctor();
    fleetDevice(['identity_state' => DoctorDevice::IDENTITY_UNVERIFIED]);

    $report = fleetReadiness();

    expect($report['devices']['estate_count'])->toBe(2);
    expect($report['devices']['eligible_count'])->toBe(1);
    expect($report['verdict'])->toBe(DoctorFleetReadinessVerdict::READY);
});

// ---------------------------------------------------------------------------
// Real-device login proof
// ---------------------------------------------------------------------------

it('refuses a doctor whose trusted path has never been walked', function () {
    [$user, $doctor] = fleetDoctor();
    $device = fleetDevice();
    fleetAuthorize($doctor, $device);
    fleetLock($doctor, fleetBranch('SPN4'));
    // Deliberately no audit proof.

    $row = fleetRowFor(fleetReadiness(), $user);

    expect($row['real_device_login_proven'])->toBeFalse();
    expect($row['real_device_login_count'])->toBe(0);
    expect($row['real_device_login_last_at'])->toBeNull();
    expect($row['blockers'])->toContain(DoctorFleetReadinessVerdict::BLOCKER_LOGIN_NOT_PROVEN);
    expect($row['state'])->toBe(DoctorFleetReadinessVerdict::STATE_NOT_READY);
});

it('accepts a proof from either login path', function (string $action) {
    [$user, $doctor] = fleetDoctor();
    $device = fleetDevice();
    fleetAuthorize($doctor, $device);
    fleetLock($doctor, fleetBranch('SPN4'));
    fleetProof($user, $device, $doctor, $action);

    $row = fleetRowFor(fleetReadiness(), $user);

    expect($row['real_device_login_proven'])->toBeTrue();
    expect($row['real_device_login_paths'])->toBe([$action]);
    expect($row['state'])->toBe(DoctorFleetReadinessVerdict::STATE_READY);
})->with(DoctorFleetReadinessRepositoryInterface::PROOF_ACTIONS);

it('never counts an attempt or a refusal as a successful login', function (string $action) {
    [$user, $doctor] = fleetDoctor();
    $device = fleetDevice();
    fleetAuthorize($doctor, $device);
    fleetLock($doctor, fleetBranch('SPN4'));
    fleetProof($user, $device, $doctor, $action);

    $row = fleetRowFor(fleetReadiness(), $user);

    // DOCTOR_DEVICE_LOGIN_REQUESTED in particular looks like a login and is
    // written on every attempt that got past the password — including the ones
    // that were then refused.
    expect($row['real_device_login_proven'])->toBeFalse();
    expect($row['blockers'])->toContain(DoctorFleetReadinessVerdict::BLOCKER_LOGIN_NOT_PROVEN);
})->with([
    'DOCTOR_DEVICE_LOGIN_REQUESTED',
    'DOCTOR_APP_LOGIN_AUTHORIZATION_REJECTED',
    'DOCTOR_DEVICE_WEBAUTHN_LOGIN_REJECTED',
    'DOCTOR_DEVICE_PROOF_VERIFIED',
]);

it('never credits one doctor\'s login to another sharing the same tablet', function () {
    $shared = fleetDevice();

    [$proven, $provenDoctor] = fleetDoctor('drg Proven');
    [$unproven, $unprovenDoctor] = fleetDoctor('drg Unproven');

    foreach ([$provenDoctor, $unprovenDoctor] as $doctor) {
        fleetAuthorize($doctor, $shared);
        fleetLock($doctor, fleetBranch('SPN'.Str::random(3)));
    }

    fleetProof($proven, $shared, $provenDoctor);

    $report = fleetReadiness();

    // The credential on that tablet is usable by BOTH — which is exactly why
    // proof must be keyed on the acting user and never on the credential.
    expect(fleetRowFor($report, $proven)['real_device_login_proven'])->toBeTrue();
    expect(fleetRowFor($report, $unproven)['real_device_login_proven'])->toBeFalse();
    expect($report['verdict'])->toBe(DoctorFleetReadinessVerdict::PARTIAL);
});

it('keeps an old proof valid, because a walked path does not expire with time', function () {
    [$user, $doctor] = fleetDoctor();
    $device = fleetDevice();
    fleetAuthorize($doctor, $device);
    fleetLock($doctor, fleetBranch('SPN4'));

    $proof = fleetProof($user, $device, $doctor);
    $proof->forceFill(['performed_at' => now()->subYear(), 'created_at' => now()->subYear()])->save();

    $row = fleetRowFor(fleetReadiness(), $user);

    /*
     * THE EXPLICIT STALENESS POLICY: there is no expiry window, and adding one
     * would be arbitrary. What invalidates a proof is the path underneath it
     * being withdrawn — a revoked device or credential fails the provisioning
     * gate and blocks the doctor there, which is where that decision belongs.
     */
    expect($row['real_device_login_proven'])->toBeTrue();
    expect($row['state'])->toBe(DoctorFleetReadinessVerdict::STATE_READY);
});

it('withdraws readiness when the path under an existing proof is revoked', function () {
    [$user, $doctor, $device] = fleetReadyDoctor();

    $device->forceFill(['status' => DoctorDevice::STATUS_REVOKED, 'revoked_at' => now()])->save();

    $row = fleetRowFor(fleetReadiness(), $user);

    /*
     * TWO blockers now, and the second one is the point.
     *
     * This assertion originally read `real_device_login_proven === true` — the
     * row survives, only the path underneath fails. An adversarial review showed
     * that is not enough: `pathFor()` returns on the FIRST complete
     * authorization, so a doctor with a SECOND live tablet keeps
     * PATH_INCOMPLETE off while their only proof still names the revoked one.
     * The proof itself has to stop qualifying, which is what the sibling test
     * `refuses to count a login on a tablet that has since been revoked` pins.
     */
    expect($row['real_device_login_count'])->toBe(1);
    expect($row['real_device_login_proven'])->toBeFalse();
    expect($row['blockers'])->toContain(DoctorFleetReadinessVerdict::BLOCKER_PATH_INCOMPLETE);
    expect($row['blockers'])->toContain(DoctorFleetReadinessVerdict::BLOCKER_LOGIN_NOT_PROVEN);
    expect($row['state'])->toBe(DoctorFleetReadinessVerdict::STATE_NOT_READY);
});

// ---------------------------------------------------------------------------
// Device coverage
// ---------------------------------------------------------------------------

it('refuses a fleet READY verdict while an eligible tablet carries no proof at all', function () {
    $proven = fleetDevice();
    $unproven = fleetDevice();

    [$user, $doctor] = fleetDoctor();
    fleetAuthorize($doctor, $proven);
    fleetAuthorize($doctor, $unproven);
    fleetLock($doctor, fleetBranch('SPN4'));
    fleetProof($user, $proven, $doctor);

    $report = fleetReadiness();

    // Every DOCTOR clears their gates, and the fleet still is not ready:
    // a tablet nobody has ever proven is an untested piece of the estate.
    expect(fleetRowFor($report, $user)['state'])->toBe(DoctorFleetReadinessVerdict::STATE_READY);
    expect($report['devices']['without_readiness_proof_ids'])->toBe([(int) $unproven->id]);
    expect($report['verdict'])->toBe(DoctorFleetReadinessVerdict::PARTIAL);
    expect(collect($report['findings'])->pluck('finding'))
        ->toContain(DoctorFleetReadinessVerdict::FINDING_DEVICE_UNPROVEN);
});

// ---------------------------------------------------------------------------
// Fail closed
// ---------------------------------------------------------------------------

it('reports NO-GO rather than a vacuous pass when there is no doctor to measure', function () {
    fleetDevice();

    $report = fleetReadiness();

    // "Zero doctors, zero blockers" is arithmetically a pass. This programme
    // has already shipped one gate that read silence as success.
    expect($report['eligible_doctor_count'])->toBe(0);
    expect($report['verdict'])->toBe(DoctorFleetReadinessVerdict::NO_GO);
    expect(collect($report['findings'])->pluck('finding'))
        ->toContain(DoctorFleetReadinessVerdict::FINDING_NOTHING_TO_MEASURE);
});

it('reports NO-GO when no eligible tablet exists at all', function () {
    [$user, $doctor] = fleetDoctor();
    fleetLock($doctor, fleetBranch('SPN4'));

    $report = fleetReadiness();

    expect($report['devices']['eligible_count'])->toBe(0);
    expect($report['verdict'])->toBe(DoctorFleetReadinessVerdict::NO_GO);
    expect(fleetRowFor($report, $user)['state'])->toBe(DoctorFleetReadinessVerdict::STATE_NOT_READY);
});

it('counts locked, unset, proven and unproven doctors against independent fixtures', function () {
    $device = fleetDevice();

    // Ready: locked + authorized + proven.
    [$ready, $readyDoctor] = fleetDoctor('drg A');
    fleetAuthorize($readyDoctor, $device);
    fleetLock($readyDoctor, fleetBranch('SPN4'));
    fleetProof($ready, $device, $readyDoctor);

    // Locked and authorized, never logged in.
    [, $silentDoctor] = fleetDoctor('drg B');
    fleetAuthorize($silentDoctor, $device);
    fleetLock($silentDoctor, fleetBranch('LDK2'));

    // Authorized and proven, no home branch.
    [$unset, $unsetDoctor] = fleetDoctor('drg C');
    fleetAuthorize($unsetDoctor, $device);
    fleetProof($unset, $device, $unsetDoctor);

    $report = fleetReadiness();

    expect($report['eligible_doctor_count'])->toBe(3);
    expect($report['locked_doctor_count'])->toBe(2);
    expect($report['unset_doctor_count'])->toBe(1);
    expect($report['real_device_ready_doctor_count'])->toBe(2);
    expect($report['real_device_not_ready_doctor_count'])->toBe(1);
    expect($report['fleet_ready_doctor_count'])->toBe(1);
    expect($report['fleet_ready_doctor_user_ids'])->toBe([(int) $ready->id]);
    expect($report['home_branch_matrix'])->toBe(['LDK2' => 1, 'SPN4' => 1, 'UNSET' => 1]);
    expect($report['verdict'])->toBe(DoctorFleetReadinessVerdict::PARTIAL);
});

it('states in the payload that a device proof is not proof of which clinician was present', function () {
    fleetReadyDoctor();

    $report = fleetReadiness();

    expect($report['authorizes_activation'])->toBeFalse();
    expect($report['proof_semantics'])->toContain('never which human');
});

it('never marks an inactive doctor record ready', function () {
    [$user, $doctor] = fleetDoctor('drg Inactive', ['is_active' => false]);
    $device = fleetDevice();
    fleetAuthorize($doctor, $device);
    fleetLock($doctor, fleetBranch('SPN4'));
    fleetProof($user, $device, $doctor);

    $row = fleetRowFor(fleetReadiness(), $user);

    expect($row['blockers'])->toContain(DoctorFleetReadinessVerdict::BLOCKER_PATH_INCOMPLETE);
    expect($row['state'])->toBe(DoctorFleetReadinessVerdict::STATE_NOT_READY);
});

// ---------------------------------------------------------------------------
// Adversarial review regressions — every one of these was a real defect
// ---------------------------------------------------------------------------

it('refuses to count a login on a tablet that has since been revoked', function () {
    $live = fleetDevice();
    $revoked = fleetDevice();

    [$user, $doctor] = fleetDoctor('drg Stale');
    fleetAuthorize($doctor, $live);
    fleetAuthorize($doctor, $revoked);
    fleetLock($doctor, fleetBranch('SPN4'));

    // The only login this doctor has ever performed was on the tablet that was
    // later revoked. Production carries exactly this shape: user 18 holds
    // thirteen success rows naming device 1, which is revoked.
    fleetProof($user, $revoked, $doctor);

    $revoked->forceFill(['status' => DoctorDevice::STATUS_REVOKED, 'revoked_at' => now()])->save();

    $row = fleetRowFor(fleetReadiness(), $user);

    expect($row['real_device_login_count'])->toBe(1);
    expect($row['qualifying_device_ids'])->toBe([]);
    expect($row['real_device_login_proven'])->toBeFalse();
    expect($row['blockers'])->toContain(DoctorFleetReadinessVerdict::BLOCKER_LOGIN_NOT_PROVEN);
});

it('refuses to count a login on a tablet the doctor is no longer authorized on', function () {
    $kept = fleetDevice();
    $lost = fleetDevice();

    [$user, $doctor] = fleetDoctor('drg Dropped');
    fleetAuthorize($doctor, $kept);
    $dropped = fleetAuthorize($doctor, $lost);
    fleetLock($doctor, fleetBranch('SPN4'));
    fleetProof($user, $lost, $doctor);

    $dropped->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();

    $row = fleetRowFor(fleetReadiness(), $user);

    // Both tablets are still trusted hardware; this doctor may only use one.
    expect($row['authorized_device_ids'])->toBe([(int) $kept->id]);
    expect($row['real_device_login_proven'])->toBeFalse();
});

it('refuses to count a legacy proof row that names no device at all', function () {
    $device = fleetDevice();
    [$user, $doctor] = fleetDoctor('drg Legacy');
    fleetAuthorize($doctor, $device);
    fleetLock($doctor, fleetBranch('SPN4'));

    // The repository anticipates rows predating the doctor_device_id key.
    AuditLog::query()->create([
        'entity_type' => 'trx_doctor_device_webauthn_credentials',
        'entity_id' => 1,
        'action' => DoctorFleetReadinessRepositoryInterface::PROOF_ACTIONS[1],
        'new_values' => ['doctor_id' => (int) $doctor->id],
        'performed_by' => $user->id,
        'performed_at' => now(),
    ]);

    $row = fleetRowFor(fleetReadiness(), $user);

    expect($row['real_device_login_count'])->toBe(1);
    expect($row['real_device_login_proven'])->toBeFalse();
    expect($row['blockers'])->toContain(DoctorFleetReadinessVerdict::BLOCKER_LOGIN_NOT_PROVEN);
});

it('does not call a doctor locked when the branch the lock names has been deleted', function () {
    $branch = fleetBranch('GONE');
    [$user] = fleetReadyDoctor('drg Orphaned', $branch);

    $branch->delete();

    $report = fleetReadiness();
    $row = fleetRowFor($report, $user);

    expect($row['home_branch_locked'])->toBeFalse();
    expect($row['blockers'])->toContain(DoctorFleetReadinessVerdict::BLOCKER_HOME_BRANCH_UNSET);
    // The two counters must agree: reporting a doctor as locked AND bucketing
    // them under UNSET is two answers to one question.
    expect($report['unset_doctor_count'])->toBe(1);
    expect($report['home_branch_matrix'])->toBe(['UNSET' => 1]);
});

it('never marks a doctor ready on an estate holding no eligible tablet', function () {
    [$user, $doctor] = fleetDoctor('drg NoEstate');
    fleetLock($doctor, fleetBranch('SPN4'));

    $report = fleetReadiness();
    $row = fleetRowFor($report, $user);

    // array_diff([], []) is empty, so a naive guard would read this as a
    // complete matrix and let the per-doctor state pass on its own.
    expect($row['blockers'])->toContain(DoctorFleetReadinessVerdict::BLOCKER_AUTHORIZATION_GAP);
    expect($row['state'])->toBe(DoctorFleetReadinessVerdict::STATE_NOT_READY);
    expect($report['fleet_ready_doctor_user_ids'])->toBe([]);
});
