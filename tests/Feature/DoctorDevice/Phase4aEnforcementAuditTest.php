<?php

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1 — arming the pilot has to be
 * accountable.
 *
 * The runbook lists `pilot_enforcement_scope_changed` among the audit events
 * that must be present, and nothing produced it. Arming was a host file edit
 * plus a cache rebuild: no application code ran, so no row could be written.
 * The asymmetry was stark — renaming a device LABEL produced a complete
 * before/after with a named actor, while denying a clinician their browser
 * produced nothing at all.
 *
 * WHAT THE TESTS ARE ACTUALLY GUARDING
 *
 * Every precondition below is a way a pilot quietly becomes something else: a
 * fleet lockout, a no-op that looks like protection, or a change attributed to
 * nobody. They are asserted by name rather than through one vague "is it valid"
 * check, because the failure messages are what an operator reads at the moment
 * they are about to deny a doctor access to patients.
 *
 * NOTHING HERE TOUCHES A REAL ENVIRONMENT FILE. The writer, the cache rebuild
 * and the fresh-process verifier are all injected, so these run against a temp
 * file and a stub. The live pilot is armed and must stay that way.
 */

use App\Models\User;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Support\Android\AndroidDoctorEnforcementScope;
use App\Support\Android\EnvFileWriter;
use App\Support\Android\Phase4aEnforcementSwitch;
use Illuminate\Validation\ValidationException;

function enfActor(string $role = 'Super Admin'): User
{
    seedAccessControl();

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function enfScope(array $overrides, bool $globalPermitted = false): void
{
    config()->set('doctor_device_enforcement.scope', array_replace_recursive(
        (array) config('doctor_device_enforcement.scope'),
        $overrides,
    ));

    config()->set('android_release.enforcement.scope', array_merge(
        (array) config('android_release.enforcement.scope'),
        ['global_permitted' => $globalPermitted],
    ));
}

/** A switch whose side effects are captured instead of performed. */
function enfSwitch(array $postChange, ?array &$calls = null): Phase4aEnforcementSwitch
{
    $path = tempnam(sys_get_temp_dir(), 'phase4a-env-');

    // Owned, and cleaned up: the repository has delta-based tempfile leak tests
    // and a suite that quietly litters /tmp is a suite that reddens them.
    $GLOBALS['__phase4a_env_tmp'][] = $path;

    file_put_contents($path, "APP_ENV=testing\n");

    $calls = ['rebuilt' => 0, 'verified' => 0, 'path' => $path];

    return new Phase4aEnforcementSwitch(
        app(AuditLogService::class),
        app(AndroidDoctorEnforcementScope::class),
        app(DoctorAppLoginGate::class),
        new EnvFileWriter($path, base_path(), enfEnvKey()),
        function () use (&$calls, $postChange): array {
            $calls['verified']++;

            return $postChange;
        },
        function () use (&$calls): void {
            $calls['rebuilt']++;
        },
    );
}

/** The env key, via the gate's constant — the flag key itself has one permitted reader. */
function enfEnvKey(): string
{
    $flags = (array) config('feature_flags.flags', []);

    return (string) ($flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_key'] ?? '');
}

function enfGoodPost(int $target): array
{
    return [
        'verdict' => 'GO',
        'enforcement_scope_mode' => 'pilot',
        'declared_pilot_doctor_user_id' => $target,
        'declared_pilot_branch_code' => 'SPN4',
        'global_enforcement_active' => false,
        'covered_doctor_count' => 1,
        'covered_doctor_user_ids' => [$target],
        'browser_denied_doctor_count' => 1,
        'browser_allowed_doctor_count' => 14,
    ];
}

function enfAuditRows(): array
{
    return DB::table('sys_audit_logs')
        ->where('action', Phase4aEnforcementSwitch::AUDIT_ACTION)
        ->orderBy('id')
        ->get()
        ->all();
}

afterEach(function () {
    foreach ($GLOBALS['__phase4a_env_tmp'] ?? [] as $path) {
        @unlink($path);
    }

    $GLOBALS['__phase4a_env_tmp'] = [];
});

// ---------------------------------------------------------------------------
// status is read-only
// ---------------------------------------------------------------------------

it('reports status without writing, changing or auditing anything', function () {
    $actor = enfActor();
    enfScope(['mode' => 'pilot', 'pilot' => ['doctor_user_id' => $actor->id]]);

    $switch = enfSwitch(enfGoodPost($actor->id), $calls);

    $status = $switch->status();

    expect($status['scope_mode'])->toBe('pilot');
    expect($calls['rebuilt'])->toBe(0);
    expect($calls['verified'])->toBe(0);
    expect(enfAuditRows())->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Every way arming must be refused
// ---------------------------------------------------------------------------

it('refuses to arm without a reason that explains anything', function () {
    $actor = enfActor();
    enfScope(['mode' => 'pilot', 'pilot' => ['doctor_user_id' => $actor->id]]);
    $switch = enfSwitch(enfGoodPost($actor->id));

    foreach (['', '   ', 'fix', 'test'] as $reason) {
        expect(fn () => $switch->arm($actor, $reason, enfGoodPost($actor->id)))
            ->toThrow(ValidationException::class);
    }

    expect(enfAuditRows())->toBeEmpty();
});

it('refuses to arm while fleet-wide enforcement is permitted', function () {
    $actor = enfActor();
    enfScope(['mode' => 'unscoped'], globalPermitted: true);
    $switch = enfSwitch(enfGoodPost($actor->id));

    expect(fn () => $switch->arm($actor, 'membuka pilot untuk cabang sunu', enfGoodPost($actor->id)))
        ->toThrow(ValidationException::class);

    expect(enfAuditRows())->toBeEmpty();
});

it('refuses to arm a pilot scope while fleet-wide permission is on, which nothing else catches', function () {
    $actor = enfActor();

    // Deliberately a VALID pilot scope, so the mode check cannot fire and the
    // fleet-permission check has to stand on its own. The previous test set
    // mode=unscoped, which meant it passed on the mode check and left this
    // branch untested — a mutation campaign found exactly that.
    enfScope(['mode' => 'pilot', 'pilot' => ['doctor_user_id' => $actor->id]], globalPermitted: true);

    $switch = enfSwitch(enfGoodPost($actor->id));

    expect(fn () => $switch->arm($actor, 'pilot valid tetapi fleet diizinkan', enfGoodPost($actor->id)))
        ->toThrow(ValidationException::class);

    expect(enfAuditRows())->toBeEmpty();
});

it('refuses to arm with no pilot target, which would enforce nobody', function () {
    $actor = enfActor();
    enfScope(['mode' => 'pilot', 'pilot' => ['doctor_user_id' => null]]);
    $switch = enfSwitch(enfGoodPost($actor->id));

    expect(fn () => $switch->arm($actor, 'mengaktifkan pilot tanpa target', [
        'covered_doctor_user_ids' => [],
    ]))->toThrow(ValidationException::class);
});

it('refuses to arm when the covered doctor is not the declared target', function () {
    $actor = enfActor();
    $other = enfActor('Doctor');
    enfScope(['mode' => 'pilot', 'pilot' => ['doctor_user_id' => $actor->id]]);
    $switch = enfSwitch(enfGoodPost($actor->id));

    // The users.id / mst_doctors.id mix-up, caught at the moment of arming.
    expect(fn () => $switch->arm($actor, 'target salah harus ditolak', [
        'covered_doctor_user_ids' => [$other->id],
    ]))->toThrow(ValidationException::class);

    expect(enfAuditRows())->toBeEmpty();
});

it('refuses to arm when more than one doctor is covered', function () {
    $actor = enfActor();
    enfScope(['mode' => 'pilot', 'pilot' => ['doctor_user_id' => $actor->id]]);
    $switch = enfSwitch(enfGoodPost($actor->id));

    expect(fn () => $switch->arm($actor, 'dua dokter tercakup harus ditolak', [
        'covered_doctor_user_ids' => [$actor->id, $actor->id + 1],
    ]))->toThrow(ValidationException::class);
});

it('refuses to arm from an unrecognised scope mode', function () {
    $actor = enfActor();
    enfScope(['mode' => 'piolt', 'pilot' => ['doctor_user_id' => $actor->id]]);
    $switch = enfSwitch(enfGoodPost($actor->id));

    expect(fn () => $switch->arm($actor, 'mode salah ketik harus ditolak', enfGoodPost($actor->id)))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// The successful path, and what it records
// ---------------------------------------------------------------------------

it('arms, verifies through a fresh process, then writes exactly one audit event', function () {
    $actor = enfActor();
    enfScope(['mode' => 'pilot', 'pilot' => ['doctor_user_id' => $actor->id, 'branch_code' => 'SPN4']]);

    $switch = enfSwitch(enfGoodPost($actor->id), $calls);

    $result = $switch->arm($actor, 'mengaktifkan pilot untuk drg Karmila di Cabang Sunu', enfGoodPost($actor->id));

    expect($result['after_armed'])->toBeTrue();
    expect($calls['rebuilt'])->toBeGreaterThan(0);
    expect($calls['verified'])->toBe(1);

    $rows = enfAuditRows();
    expect($rows)->toHaveCount(1);

    $new = json_decode($rows[0]->new_values, true);
    $old = json_decode($rows[0]->old_values, true);

    expect($rows[0]->performed_by)->toBe($actor->id);
    expect($old['armed'])->toBeFalse();
    expect($new['armed'])->toBeTrue();
    expect($new['reason'])->toContain('Karmila');
    expect($new['post_change_scope_verdict'])->toBe('GO');
    expect($new['covered_doctor_count'])->toBe(1);
    expect($new['browser_denied_doctor_count'])->toBe(1);
    expect($new['browser_allowed_doctor_count'])->toBe(14);
    expect($new['global_enforcement_active'])->toBeFalse();
    expect($new['target_branch_code_advisory'])->toBe('SPN4');
});

it('records a disarm too, with the direction the right way round', function () {
    $actor = enfActor();
    enfScope(['mode' => 'pilot', 'pilot' => ['doctor_user_id' => $actor->id]]);

    $switch = enfSwitch(enfGoodPost($actor->id));

    $switch->disarm($actor, 'menonaktifkan pilot untuk pemeliharaan', enfGoodPost($actor->id));

    $rows = enfAuditRows();
    expect($rows)->toHaveCount(1);

    $new = json_decode($rows[0]->new_values, true);
    expect($new['armed'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// The failure that must never survive
// ---------------------------------------------------------------------------

it('rolls the environment back and records nothing when verification is not GO', function () {
    $actor = enfActor();
    enfScope(['mode' => 'pilot', 'pilot' => ['doctor_user_id' => $actor->id]]);

    $bad = enfGoodPost($actor->id);
    $bad['verdict'] = 'FAIL';

    $switch = enfSwitch($bad, $calls);
    $before = file_get_contents($calls['path']);

    expect(fn () => $switch->arm($actor, 'verifikasi gagal harus mengembalikan', enfGoodPost($actor->id)))
        ->toThrow(ValidationException::class);

    // A half-applied clinical lockout is the worst outcome available.
    expect(file_get_contents($calls['path']))->toBe($before);
    expect(enfAuditRows())->toBeEmpty();
});

it('rolls back when the post-change report says the fleet became enforced', function () {
    $actor = enfActor();
    enfScope(['mode' => 'pilot', 'pilot' => ['doctor_user_id' => $actor->id]]);

    $bad = enfGoodPost($actor->id);
    $bad['global_enforcement_active'] = true;

    $switch = enfSwitch($bad, $calls);
    $before = file_get_contents($calls['path']);

    expect(fn () => $switch->arm($actor, 'fleet aktif harus dikembalikan', enfGoodPost($actor->id)))
        ->toThrow(ValidationException::class);

    expect(file_get_contents($calls['path']))->toBe($before);
    expect(enfAuditRows())->toBeEmpty();
});

// ---------------------------------------------------------------------------
// What must never appear in the trail
// ---------------------------------------------------------------------------

it('writes no secret, no key material and no credential into the audit trail', function () {
    $actor = enfActor();
    enfScope(['mode' => 'pilot', 'pilot' => ['doctor_user_id' => $actor->id]]);

    $switch = enfSwitch(enfGoodPost($actor->id));
    $switch->arm($actor, 'memastikan jejak audit bersih dari rahasia', enfGoodPost($actor->id));

    $row = enfAuditRows()[0];
    $blob = strtolower((string) $row->new_values.(string) $row->old_values);

    foreach (['password', 'secret', 'token', 'private', 'ktp', 'nik', 'begin rsa', 'app_key'] as $forbidden) {
        expect($blob)->not->toContain($forbidden);
    }
});

it('only writes the environment key the flag registry declares', function () {
    $actor = enfActor();
    enfScope(['mode' => 'pilot', 'pilot' => ['doctor_user_id' => $actor->id]]);

    $switch = enfSwitch(enfGoodPost($actor->id), $calls);
    $switch->arm($actor, 'memastikan hanya satu kunci env ditulis', enfGoodPost($actor->id));

    $written = file_get_contents($calls['path']);

    // The key comes from the registry, so this cannot be pointed elsewhere.
    expect($written)->toContain(enfEnvKey().'=true');
    expect($written)->toContain('APP_ENV=testing');
});
