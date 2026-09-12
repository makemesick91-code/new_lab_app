<?php

declare(strict_types=1);

use App\Modules\DoctorAccess\Services\DoctorDeviceBulkAuthorizationService;
use App\Modules\DoctorAccess\Support\DoctorDeviceBulkAuthorizationOutcome;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 PR-C — the write path
|--------------------------------------------------------------------------
|
| What actually lands, what is refused, and — just as load-bearing — what is
| left untouched. The non-mutation cases are not decoration: reusing
| DoctorDeviceAuthorizationService::approve() means PR-C runs code that CAN
| promote a device, and these are the tests that prove the guard holds.
*/

function dbaApplyService(): DoctorDeviceBulkAuthorizationService
{
    return app(DoctorDeviceBulkAuthorizationService::class);
}

it('creates then activates a missing pair, stamping the actor on both halves', function (): void {
    $branch = daBranch('Cabang Apply');
    $account = daDoctorAccount([$branch]);
    $device = dbaTrustedDevice([], $branch);
    $actor = daSupervisorRme();

    $service = dbaApplyService();
    $result = $service->apply($service->plan(), $actor, 'Provisioning armada tablet klinik.');

    $row = DoctorDeviceAuthorization::query()
        ->where('doctor_id', $account['doctor']->id)
        ->where('doctor_device_id', $device->id)
        ->firstOrFail();

    expect($result['created'])->toBe(1)
        ->and($result['refused'])->toBe(0)
        ->and($row->status)->toBe(DoctorDeviceAuthorization::STATUS_ACTIVE)
        // All three asserted, because the factory's active() state sets none of
        // them — an assertion built on factory state would be vacuous.
        ->and($row->request_source)->toBe(DoctorDeviceAuthorization::SOURCE_ADMIN)
        ->and((int) $row->requested_by)->toBe((int) $actor->id)
        ->and((int) $row->approved_by)->toBe((int) $actor->id);
});

it('approves an existing PENDING row in place without creating a second one', function (): void {
    $branch = daBranch('Cabang Adopt');
    $account = daDoctorAccount([$branch]);
    $device = dbaTrustedDevice([], $branch);
    $pending = dbaAuthorization($account['doctor'], $device, DoctorDeviceAuthorization::STATUS_PENDING);

    $service = dbaApplyService();
    $result = $service->apply($service->plan(), daSupervisorRme(), 'Mengadopsi permintaan dokter yang tertunda.');

    $pending->refresh();

    expect($result['approved_existing'])->toBe(1)
        ->and($result['created'])->toBe(0)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(1)
        ->and($pending->status)->toBe(DoctorDeviceAuthorization::STATUS_ACTIVE)
        // The doctor's own provenance survives: this row records that THEY
        // asked, and approving it must not rewrite that into an admin action.
        ->and($pending->request_source)->toBe(DoctorDeviceAuthorization::SOURCE_APP_LOGIN)
        ->and($pending->requested_at)->not->toBeNull();
});

it('is idempotent: a second apply proposes nothing and writes nothing', function (): void {
    $branch = daBranch('Cabang Idempotent');
    daDoctorAccount([$branch]);
    daDoctorAccount([$branch]);
    dbaTrustedDevice([], $branch);

    $service = dbaApplyService();
    $service->apply($service->plan(), daSupervisorRme(), 'Provisioning putaran pertama armada.');

    $authorizationsAfterFirst = DB::table('mst_doctor_device_authorizations')->count();
    $auditAfterFirst = DB::table('sys_audit_logs')->count();

    $second = $service->plan();

    expect($second->proposedCreate())->toBe(0)
        ->and($second->proposedApproveExisting())->toBe(0)
        ->and($second->hasWork())->toBeFalse()
        ->and($second->existingActive())->toBe(2)
        ->and(DB::table('mst_doctor_device_authorizations')->count())->toBe($authorizationsAfterFirst)
        // Planning a closed matrix is a read. Not one audit row.
        ->and(DB::table('sys_audit_logs')->count())->toBe($auditAfterFirst);
});

it('leaves every device row byte-identical and admits no hardware', function (): void {
    $branch = daBranch('Cabang Non Mutation');
    daDoctorAccount([$branch]);
    dbaTrustedDevice([], $branch);
    dbaTrustedDevice([], $branch);

    $before = dbaDeviceSnapshot();

    $service = dbaApplyService();
    $service->apply($service->plan(), daSupervisorRme(), 'Provisioning tanpa menyentuh registri perangkat.');

    expect(dbaDeviceSnapshot())->toBe($before)
        // approve() promotes a pending_approval device and writes this action.
        // Zero of them is the proof that the branch never fired.
        ->and(dbaAuditCount('DOCTOR_DEVICE_ADMITTED'))->toBe(0);
});

it('refuses under the lock when a device stops being ACTIVE after the plan was built', function (): void {
    $branch = daBranch('Cabang Drift Device');
    daDoctorAccount([$branch]);
    $device = dbaTrustedDevice([], $branch);

    $service = dbaApplyService();
    $plan = $service->plan();

    // The estate moves between preview and write. This is exactly the state
    // approve() would promote back to ACTIVE if PR-C let it through.
    $device->forceFill(['status' => DoctorDevice::STATUS_PENDING_APPROVAL])->save();
    $snapshot = dbaDeviceSnapshot();

    $result = $service->apply($plan, daSupervisorRme(), 'Mencoba provisioning saat perangkat berubah status.');

    expect($result['refused'])->toBe(1)
        ->and($result['created'])->toBe(0)
        ->and($result['outcomes'][0]['outcome'])
        ->toBe(DoctorDeviceBulkAuthorizationOutcome::REFUSED_DEVICE_NOT_ACTIVE)
        // The guard held: the device was not promoted.
        ->and(dbaDeviceSnapshot())->toBe($snapshot)
        ->and($device->fresh()->status)->toBe(DoctorDevice::STATUS_PENDING_APPROVAL)
        ->and(dbaAuditCount('DOCTOR_DEVICE_ADMITTED'))->toBe(0)
        ->and(dbaActiveMatrix())->toBe([]);
});

it('refuses when a doctor is deactivated after the plan was built', function (): void {
    $branch = daBranch('Cabang Drift Doctor');
    $account = daDoctorAccount([$branch]);
    dbaTrustedDevice([], $branch);

    $service = dbaApplyService();
    $plan = $service->plan();

    $account['doctor']->forceFill(['is_active' => false])->save();

    $result = $service->apply($plan, daSupervisorRme(), 'Mencoba provisioning saat dokter dinonaktifkan.');

    expect($result['refused'])->toBe(1)
        ->and(dbaActiveMatrix())->toBe([]);
});

it('refuses a pair another operator rejected between the plan and the write', function (): void {
    $branch = daBranch('Cabang Race');
    $account = daDoctorAccount([$branch]);
    $device = dbaTrustedDevice([], $branch);
    $pending = dbaAuthorization($account['doctor'], $device, DoctorDeviceAuthorization::STATUS_PENDING);

    $service = dbaApplyService();
    $plan = $service->plan();

    $pending->forceFill([
        'status' => DoctorDeviceAuthorization::STATUS_REJECTED,
        'rejected_at' => now(),
        'rejected_reason' => 'Bukan perangkat milik klinik.',
    ])->save();

    $result = $service->apply($plan, daSupervisorRme(), 'Mencoba provisioning setelah penolakan operator lain.');

    // The human decision wins. A synchroniser that overrode it would make a
    // refusal meaningless.
    expect($result['refused'])->toBe(1)
        ->and($result['outcomes'][0]['outcome'])
        ->toBe(DoctorDeviceBulkAuthorizationOutcome::REFUSED_RACED_TO_NON_PENDING)
        ->and($pending->fresh()->status)->toBe(DoctorDeviceAuthorization::STATUS_REJECTED);
});

it('never creates a duplicate row when the pair appears between the plan and the write', function (): void {
    $branch = daBranch('Cabang Unique');
    $account = daDoctorAccount([$branch]);
    $device = dbaTrustedDevice([], $branch);

    $service = dbaApplyService();
    $plan = $service->plan();

    expect($plan->proposedCreate())->toBe(1);

    // A doctor taps login on the same tablet while the operator is reading the
    // preview: the row this run meant to create now already exists.
    dbaAuthorization($account['doctor'], $device, DoctorDeviceAuthorization::STATUS_PENDING);

    $result = $service->apply($plan, daSupervisorRme(), 'Provisioning saat dokter membuat permintaan sendiri.');

    $rows = DoctorDeviceAuthorization::query()
        ->where('doctor_id', $account['doctor']->id)
        ->where('doctor_device_id', $device->id)
        ->get();

    // One row per pair is a DATABASE guarantee, not an application one:
    // mst_dd_authorizations_pair_unique is an unconditional UNIQUE.
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->status)->toBe(DoctorDeviceAuthorization::STATUS_ACTIVE)
        ->and($result['refused'])->toBe(0);
});

it('adopts an orphan PENDING row left by a crash between the two transactions', function (): void {
    $branch = daBranch('Cabang Orphan');
    $account = daDoctorAccount([$branch]);
    $device = dbaTrustedDevice([], $branch);

    // Byte-identical to what a half-finished run leaves behind, and to what a
    // doctor tapping login produces. Self-healing on the next run is the whole
    // reason per-pair atomicity across both halves is not claimed.
    dbaAuthorization($account['doctor'], $device, DoctorDeviceAuthorization::STATUS_PENDING);

    $service = dbaApplyService();
    $result = $service->apply($service->plan(), daSupervisorRme(), 'Melanjutkan provisioning yang terputus.');

    expect($result['approved_existing'])->toBe(1)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(1)
        ->and(dbaActiveMatrix())->toBe([$account['doctor']->id.':'.$device->id]);
});

it('writes exactly one run-level audit row, attributed to a real actor', function (): void {
    $branch = daBranch('Cabang Audit');
    daDoctorAccount([$branch]);
    dbaTrustedDevice([], $branch);
    $actor = daSupervisorRme();

    $service = dbaApplyService();
    $plan = $service->plan();
    $digest = $plan->digest();
    $service->apply($plan, $actor, 'Provisioning armada dengan jejak audit.');

    $rows = DB::table('sys_audit_logs')->where('action', 'DOCTOR_DEVICE_BULK_AUTHORIZATION_RUN')->get();

    expect($rows)->toHaveCount(1);

    $payload = json_decode((string) $rows->first()->new_values, true);

    expect($payload['digest'])->toBe($digest)
        ->and($payload['created'])->toBe(1)
        ->and($payload['reason'])->toBe('Provisioning armada dengan jejak audit.')
        // AuditLogService falls back to auth()->user(), which is null from an
        // unauthenticated console process. A forgotten actor would leave an
        // unattributable security-history row.
        ->and((int) $rows->first()->performed_by)->toBe((int) $actor->id);
});

it('touches no branch lock, cover, session lease or credential', function (): void {
    $home = daBranch('Cabang Home');
    $account = daDoctorAccount([$home]);
    daGrantHomeLock($account['doctor'], $home);
    dbaTrustedDevice([], daBranch('Cabang Lain'));

    $locksBefore = DB::table('mst_doctor_branch_locks')->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();
    $coversBefore = DB::table('trx_doctor_branch_covers')->count();
    $leasesBefore = DB::table('trx_doctor_session_leases')->count();
    $credentialsBefore = DB::table('trx_doctor_device_webauthn_credentials')->count();

    $service = dbaApplyService();
    $service->apply($service->plan(), daSupervisorRme(), 'Provisioning lintas cabang tanpa menyentuh kunci cabang.');

    expect(DB::table('mst_doctor_branch_locks')->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all())
        ->toBe($locksBefore)
        ->and(DB::table('trx_doctor_branch_covers')->count())->toBe($coversBefore)
        ->and(DB::table('trx_doctor_session_leases')->count())->toBe($leasesBefore)
        ->and(DB::table('trx_doctor_device_webauthn_credentials')->count())->toBe($credentialsBefore)
        // And the cross-branch pair it was supposed to write did land, so this
        // is not passing because nothing happened at all.
        ->and(dbaActiveMatrix())->toHaveCount(1);
});

it('closes the whole matrix for a multi-doctor multi-device estate', function (): void {
    $one = daBranch('Cabang A');
    $two = daBranch('Cabang B');

    $doctors = [daDoctorAccount([$one]), daDoctorAccount([$one]), daDoctorAccount([$two])];
    $devices = [dbaTrustedDevice([], $one), dbaTrustedDevice([], $two)];

    // One pair pre-satisfied, so the run has to distinguish work from no-work.
    dbaAuthorization($doctors[0]['doctor'], $devices[0], DoctorDeviceAuthorization::STATUS_ACTIVE);

    $service = dbaApplyService();
    $plan = $service->plan();

    expect($plan->matrixSize())->toBe(6)
        ->and($plan->existingActive())->toBe(1)
        ->and($plan->proposedCreate())->toBe(5);

    $result = $service->apply($plan, daSupervisorRme(), 'Menutup seluruh matriks otorisasi armada.');

    expect($result['created'])->toBe(5)
        ->and($result['refused'])->toBe(0)
        ->and(dbaActiveMatrix())->toHaveCount(6)
        ->and($service->plan()->unreachable())->toBe(0);
});
