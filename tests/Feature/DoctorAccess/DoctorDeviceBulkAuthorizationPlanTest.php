<?php

declare(strict_types=1);

use App\Modules\DoctorAccess\Services\DoctorDeviceBulkAuthorizationService;
use App\Modules\DoctorAccess\Support\DoctorDeviceBulkAuthorizationOutcome;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Services\DoctorGlobalRolloutReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 PR-C — the plan
|--------------------------------------------------------------------------
|
| What the tool SEES and what it would therefore do. Every assertion here runs
| against a plan built from reads alone, so this suite is also where the
| zero-write guarantee of a dry run is pinned.
|
| The eligibility cases are deliberately written one predicate at a time. A
| fixture that was ineligible for two reasons at once would pass whichever
| predicate the code checked first and prove nothing about the other.
*/

function dbaService(): DoctorDeviceBulkAuthorizationService
{
    return app(DoctorDeviceBulkAuthorizationService::class);
}

it('a dry run writes absolutely nothing', function (): void {
    $branch = daBranch('Cabang Sunu');
    $account = daDoctorAccount([$branch]);
    dbaTrustedDevice([], $branch);
    dbaTrustedDevice([], $branch);

    $devicesBefore = dbaDeviceSnapshot();
    $authorizationsBefore = DB::table('mst_doctor_device_authorizations')->count();
    $auditBefore = DB::table('sys_audit_logs')->count();

    $plan = dbaService()->plan();

    expect($plan->proposedCreate())->toBe(2)
        ->and(DB::table('mst_doctor_device_authorizations')->count())->toBe($authorizationsBefore)
        ->and(DB::table('sys_audit_logs')->count())->toBe($auditBefore)
        // Snapshot, not count: a run that promoted one device would leave the
        // count identical and only the column values would betray it.
        ->and(dbaDeviceSnapshot())->toBe($devicesBefore);

    expect($account['doctor']->id)->toBeInt();
});

it('classifies a never-seen eligible pair as CREATE', function (): void {
    $branch = daBranch('Cabang Landak');
    daDoctorAccount([$branch]);
    dbaTrustedDevice([], $branch);

    $plan = dbaService()->plan();

    expect($plan->pairs)->toHaveCount(1)
        ->and($plan->pairs[0]->bucket)->toBe(DoctorDeviceBulkAuthorizationOutcome::BUCKET_CREATE)
        ->and($plan->pairs[0]->authorizationId)->toBeNull()
        ->and($plan->proposedCreate())->toBe(1)
        ->and($plan->finalExpectedActive())->toBe(1);
});

it('classifies an existing ACTIVE pair as already satisfied and proposes nothing', function (): void {
    $branch = daBranch('Cabang Antang');
    $account = daDoctorAccount([$branch]);
    $device = dbaTrustedDevice([], $branch);
    $existing = dbaAuthorization($account['doctor'], $device, DoctorDeviceAuthorization::STATUS_ACTIVE);

    $plan = dbaService()->plan();

    expect($plan->existingActive())->toBe(1)
        ->and($plan->proposedCreate())->toBe(0)
        ->and($plan->proposedApproveExisting())->toBe(0)
        ->and($plan->actionable())->toBe([])
        ->and($plan->pairs[0]->authorizationId)->toBe((int) $existing->id)
        ->and($plan->unreachable())->toBe(0);
});

it('classifies an existing PENDING pair as an adoption, counted apart from creates', function (): void {
    $branch = daBranch('Cabang Telkomas');
    $account = daDoctorAccount([$branch]);
    $device = dbaTrustedDevice([], $branch);
    dbaAuthorization($account['doctor'], $device, DoctorDeviceAuthorization::STATUS_PENDING);

    $plan = dbaService()->plan();

    expect($plan->proposedApproveExisting())->toBe(1)
        // Not folded into creates: bucket C drains a human approval inbox and
        // an operator has to be able to see that separately.
        ->and($plan->proposedCreate())->toBe(0)
        ->and($plan->pendingInboxBefore)->toBe(1)
        ->and($plan->summary()['pending_inbox_after_expected'])->toBe(0);
});

it('leaves a REJECTED pair alone and preserves every refusal stamp', function (): void {
    $branch = daBranch('Cabang Rejected');
    $account = daDoctorAccount([$branch]);
    $device = dbaTrustedDevice([], $branch);
    $rejected = dbaAuthorization($account['doctor'], $device, DoctorDeviceAuthorization::STATUS_REJECTED);

    $before = (array) DB::table('mst_doctor_device_authorizations')->where('id', $rejected->id)->first();

    $plan = dbaService()->plan();

    expect($plan->countIn(DoctorDeviceBulkAuthorizationOutcome::BUCKET_BLOCKED_REJECTED))->toBe(1)
        ->and($plan->actionable())->toBe([])
        ->and($plan->blocked())->toBe(1)
        // The refusal is not folded into the expected total: a tool that
        // counted rows it cannot touch would report a number it can never reach.
        ->and($plan->finalExpectedActive())->toBe(0)
        ->and($plan->unreachable())->toBe(1)
        ->and((array) DB::table('mst_doctor_device_authorizations')->where('id', $rejected->id)->first())->toBe($before);
});

it('leaves a REVOKED pair alone and preserves every revocation stamp', function (): void {
    $branch = daBranch('Cabang Revoked');
    $account = daDoctorAccount([$branch]);
    $device = dbaTrustedDevice([], $branch);
    $revoked = dbaAuthorization($account['doctor'], $device, DoctorDeviceAuthorization::STATUS_REVOKED);

    $before = (array) DB::table('mst_doctor_device_authorizations')->where('id', $revoked->id)->first();

    $plan = dbaService()->plan();

    expect($plan->countIn(DoctorDeviceBulkAuthorizationOutcome::BUCKET_BLOCKED_REVOKED))->toBe(1)
        ->and($plan->actionable())->toBe([])
        ->and((array) DB::table('mst_doctor_device_authorizations')->where('id', $revoked->id)->first())->toBe($before);
});

it('excludes a device nobody has admitted yet', function (): void {
    $branch = daBranch('Cabang Pending');
    daDoctorAccount([$branch]);
    dbaPendingApprovalDevice($branch);

    $plan = dbaService()->plan();

    expect($plan->eligibleDeviceCount())->toBe(0)
        ->and($plan->excludedDevices)->toHaveCount(1)
        ->and($plan->excludedDevices[0]['reason'])
        ->toBe(DoctorDeviceBulkAuthorizationOutcome::REASON_DEVICE_PENDING_APPROVAL);
});

it('excludes a disabled device and a revoked device, each with its own reason', function (): void {
    $branch = daBranch('Cabang Withdrawn');
    daDoctorAccount([$branch]);
    dbaTrustedDevice(['status' => DoctorDevice::STATUS_DISABLED], $branch);
    dbaTrustedDevice(['status' => DoctorDevice::STATUS_REVOKED], $branch);

    $plan = dbaService()->plan();

    $reasons = array_column($plan->excludedDevices, 'reason');

    expect($plan->eligibleDeviceCount())->toBe(0)
        ->and($reasons)->toContain(DoctorDeviceBulkAuthorizationOutcome::REASON_DEVICE_DISABLED)
        ->and($reasons)->toContain(DoctorDeviceBulkAuthorizationOutcome::REASON_DEVICE_REVOKED);
});

it('excludes a device that has never proved possession of its key', function (): void {
    $branch = daBranch('Cabang Unverified');
    daDoctorAccount([$branch]);
    dbaUnverifiedDevice($branch);

    $plan = dbaService()->plan();

    expect($plan->eligibleDeviceCount())->toBe(0)
        ->and($plan->excludedDevices[0]['reason'])
        // The bar is set by approve(), which hard-throws on this. Proposing a
        // write that is certain to be refused would be a lie in the preview.
        ->toBe(DoctorGlobalRolloutReadinessService::REASON_DEVICE_IDENTITY_UNVERIFIED);
});

it('excludes a doctor whose USER ACCOUNT is switched off even though the doctor record is active', function (): void {
    $branch = daBranch('Cabang Inactive User');
    $account = daDoctorAccount([$branch]);
    dbaTrustedDevice([], $branch);

    $account['user']->forceFill(['is_active' => false])->save();

    $plan = dbaService()->plan();

    // The readiness engine reports this account READY: doctorAccounts() does
    // not filter users.is_active and the engine only inspects the doctor row.
    // Provisioning a tablet for an account that cannot authenticate is noise.
    expect($plan->eligibleDoctorCount())->toBe(0)
        ->and($plan->excludedDoctors[0]['reason'])
        ->toBe(DoctorDeviceBulkAuthorizationOutcome::REASON_USER_ACCOUNT_INACTIVE)
        ->and($plan->proposedCreate())->toBe(0);
});

it('excludes a Doctor-role account with no doctor record behind it', function (): void {
    $branch = daBranch('Cabang Unlinked');
    $account = daDoctorAccount([$branch]);
    dbaTrustedDevice([], $branch);

    $account['doctor']->forceFill(['user_id' => null])->save();

    $plan = dbaService()->plan();

    expect($plan->eligibleDoctorCount())->toBe(0)
        ->and($plan->excludedDoctors[0]['reason'])
        ->toBe(DoctorGlobalRolloutReadinessService::REASON_DOCTOR_NOT_LINKED);
});

it('excludes a doctor whose record is switched off', function (): void {
    $branch = daBranch('Cabang Inactive Doctor');
    $account = daDoctorAccount([$branch]);
    dbaTrustedDevice([], $branch);

    $account['doctor']->forceFill(['is_active' => false])->save();

    $plan = dbaService()->plan();

    expect($plan->eligibleDoctorCount())->toBe(0)
        ->and($plan->excludedDoctors[0]['reason'])
        ->toBe(DoctorGlobalRolloutReadinessService::REASON_DOCTOR_INACTIVE);
});

it('proposes a doctor home-locked to one branch for a device in a DIFFERENT branch', function (): void {
    $home = daBranch('Cabang Sunu');
    $elsewhere = daBranch('Cabang Antang');

    $account = daDoctorAccount([$home]);
    daGrantHomeLock($account['doctor'], $home);

    $foreign = dbaTrustedDevice([], $elsewhere);

    $plan = dbaService()->plan();

    // THE INVARIANT. Device trust and branch authority are independent
    // boundaries, and production already depends on it: drg Karmila is
    // home-locked to SPN4 and holds an ACTIVE authorization on an ATG3 tablet.
    // Anything that intersected the two would have deprovisioned her.
    expect($plan->proposedCreate())->toBe(1)
        ->and($plan->pairs[0]->deviceId)->toBe((int) $foreign->id)
        ->and($plan->pairs[0]->deviceBranchCode)->toBe($elsewhere->code);
});

it('proposes a branch-UNSET doctor for devices across every branch', function (): void {
    $one = daBranch('Cabang Satu');
    $two = daBranch('Cabang Dua');

    // No home lock granted: production has 14 of 15 doctors in exactly this
    // state, so a tool that required one would provision almost nobody.
    daDoctorAccount([$one]);

    dbaTrustedDevice([], $one);
    dbaTrustedDevice([], $two);

    $plan = dbaService()->plan();

    expect($plan->eligibleDeviceCount())->toBe(2)
        ->and($plan->proposedCreate())->toBe(2)
        ->and(DB::table('mst_doctor_branch_locks')->count())->toBe(0);
});

it('reports an ACTIVE authorization on a now-ineligible device without proposing a revoke', function (): void {
    $branch = daBranch('Cabang Drift');
    $account = daDoctorAccount([$branch]);

    $retired = dbaTrustedDevice([], $branch);
    dbaAuthorization($account['doctor'], $retired, DoctorDeviceAuthorization::STATUS_ACTIVE);
    $retired->forceFill(['status' => DoctorDevice::STATUS_REVOKED])->save();

    $plan = dbaService()->plan();

    expect($plan->activeOnIneligibleDevice)->toHaveCount(1)
        ->and($plan->activeOnIneligibleDevice[0]['device_id'])->toBe((int) $retired->id)
        // Reported, never revoked. Withdrawing trust is a lifecycle decision
        // with its own route and a mandatory reason.
        ->and($plan->actionable())->toBe([])
        ->and(DB::table('mst_doctor_device_authorizations')->where('status', 'revoked')->count())->toBe(0);
});

it('keeps its query count constant as the fleet grows', function (): void {
    $branch = daBranch('Cabang Budget Kecil');

    foreach (range(1, 2) as $ignored) {
        daDoctorAccount([$branch]);
        dbaTrustedDevice([], $branch);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    dbaService()->plan();
    $small = count(DB::getQueryLog());
    DB::disableQueryLog();

    foreach (range(1, 3) as $ignored) {
        daDoctorAccount([$branch]);
        dbaTrustedDevice([], $branch);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $plan = dbaService()->plan();
    $large = count(DB::getQueryLog());
    DB::disableQueryLog();

    // 2x2 = 4 cells, then 5x5 = 25. A findPair() per cell would show here as a
    // jump of twenty-one; assembling the matrix in PHP shows as no jump at all.
    expect($plan->matrixSize())->toBe(25)
        ->and($large)->toBe($small)
        ->and($large)->toBeLessThan(15);
});

it('narrows to the named doctors and devices without changing what it excludes', function (): void {
    $branch = daBranch('Cabang Narrow');
    $keep = daDoctorAccount([$branch]);
    $skip = daDoctorAccount([$branch]);
    $device = dbaTrustedDevice([], $branch);
    dbaTrustedDevice([], $branch);

    $full = dbaService()->plan();
    $narrow = dbaService()->plan([(int) $keep['doctor']->id], [(int) $device->id]);

    expect($full->matrixSize())->toBe(4)
        ->and($narrow->matrixSize())->toBe(1)
        ->and($narrow->pairs[0]->doctorId)->toBe((int) $keep['doctor']->id)
        ->and($narrow->pairs[0]->deviceId)->toBe((int) $device->id)
        // A different delta must be a different consent token.
        ->and($narrow->digest())->not->toBe($full->digest())
        ->and($skip['doctor']->id)->toBeInt();
});

it('gives two different EMPTY matrices two different digests', function (): void {
    $branch = daBranch('Cabang Kosong');

    // Devices, but no eligible doctor at all: the matrix has no cells.
    dbaTrustedDevice([], $branch);

    $first = dbaService()->plan();

    expect($first->pairs)->toBe([])
        ->and($first->matrixSize())->toBe(0);

    $digestOne = $first->digest();

    dbaTrustedDevice([], $branch);

    // Deriving the id lists from $pairs would hash an empty estate identically
    // to every other empty estate, and a digest taken when one tablet was
    // trusted would then satisfy the approval gate when two are. The plan
    // carries the eligible sets explicitly so the two are distinguishable.
    expect(dbaService()->plan()->digest())->not->toBe($digestOne);
});

it('does not call a device ineligible merely because --device= narrowed past it', function (): void {
    $branch = daBranch('Cabang Sempit');
    $account = daDoctorAccount([$branch]);

    $named = dbaTrustedDevice([], $branch);
    $other = dbaTrustedDevice([], $branch);

    dbaAuthorization($account['doctor'], $other, DoctorDeviceAuthorization::STATUS_ACTIVE);

    $plan = dbaService()->plan([], [(int) $named->id]);

    // The other tablet is still trusted; it is simply outside this run's scope.
    // Reporting its authorization as pointing at retired hardware would be a
    // false alarm on the one line an operator is meant to act on.
    expect($plan->eligibleDeviceCount())->toBe(1)
        ->and($plan->activeOnIneligibleDevice)->toBe([]);
});

it('produces a stable digest for an unchanged estate and a different one when the estate moves', function (): void {
    $branch = daBranch('Cabang Digest');
    daDoctorAccount([$branch]);
    dbaTrustedDevice([], $branch);

    $first = dbaService()->plan()->digest();
    $second = dbaService()->plan()->digest();

    expect($first)->toBe($second)->and($first)->toHaveLength(12);

    dbaTrustedDevice([], $branch);

    expect(dbaService()->plan()->digest())->not->toBe($first);
});
