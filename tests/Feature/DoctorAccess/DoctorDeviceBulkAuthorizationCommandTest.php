<?php

declare(strict_types=1);

use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 PR-C — the operator contract
|--------------------------------------------------------------------------
|
| Every assertion goes through Artisan::call() + Artisan::output(). NOT
| expectsOutputToContain(), which consumes one writeln per expectation: against
| a command that prints eighteen counters it would silently check the first and
| report a pass.
*/

/** A minimal estate with exactly one pair waiting to be provisioned. */
function dbaEstate(): array
{
    $branch = daBranch('Cabang Perintah');
    $account = daDoctorAccount([$branch]);
    $device = dbaTrustedDevice([], $branch);

    return ['branch' => $branch, 'account' => $account, 'device' => $device];
}

it('runs as a dry run with no flags and prints every counter', function (): void {
    dbaEstate();
    $actor = daSupervisorRme();

    $run = dbaRun(['--actor' => (string) $actor->id]);

    expect($run['exit'])->toBe(0)
        ->and($run['output'])->toContain('MODE=DRY_RUN')
        ->and(dbaCounter($run['output'], 'ELIGIBLE_DOCTOR_COUNT'))->toBe(1)
        ->and(dbaCounter($run['output'], 'ELIGIBLE_DEVICE_COUNT'))->toBe(1)
        ->and(dbaCounter($run['output'], 'TARGET_PAIR_COUNT'))->toBe(1)
        ->and(dbaCounter($run['output'], 'EXISTING_ACTIVE_TARGET_PAIRS'))->toBe(0)
        ->and(dbaCounter($run['output'], 'MISSING_TARGET_PAIRS'))->toBe(1)
        ->and(dbaCounter($run['output'], 'PENDING_TARGET_PAIRS'))->toBe(0)
        ->and(dbaCounter($run['output'], 'DUPLICATE_ACTIVE_PAIRS'))->toBe(0)
        ->and(dbaCounter($run['output'], 'CONFLICTING_TARGET_PAIRS'))->toBe(0)
        ->and(dbaCounter($run['output'], 'PROPOSED_CREATE_COUNT'))->toBe(1)
        ->and(dbaCounter($run['output'], 'PROPOSED_REACTIVATE_COUNT'))->toBe(0)
        // Proof printed rather than promised. A dry run that merely claimed to
        // write nothing would be asking to be believed.
        ->and(dbaCounter($run['output'], 'BRANCH_MUTATIONS'))->toBe(0)
        ->and(dbaCounter($run['output'], 'DEVICE_MUTATIONS'))->toBe(0)
        ->and(dbaCounter($run['output'], 'CREDENTIAL_MUTATIONS'))->toBe(0)
        ->and(dbaCounter($run['output'], 'PILOT_SCOPE_MUTATIONS'))->toBe(0)
        ->and(dbaCounter($run['output'], 'FEATURE_FLAG_MUTATIONS'))->toBe(0)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('lists every proposed write with both id spaces named', function (): void {
    $estate = dbaEstate();

    $run = dbaRun(['--actor' => (string) daSupervisorRme()->id]);

    // users.id and mst_doctors.id are NOT interchangeable and have been
    // confused in this estate before: on the pilot, drg Karmila is user 18 and
    // doctor 21. The report names both rather than leaving one to be inferred.
    expect($run['output'])->toContain('doctor_id='.$estate['account']['doctor']->id)
        ->and($run['output'])->toContain('user_id='.$estate['account']['user']->id)
        ->and($run['output'])->toContain('device_id='.$estate['device']->id)
        ->and($run['output'])->toContain('branch='.$estate['branch']->code);
});

it('refuses --apply without the plan digest and writes nothing', function (): void {
    dbaEstate();

    $run = dbaRun([
        '--actor' => (string) daSupervisorRme()->id,
        '--reason' => 'Provisioning armada tablet klinik.',
        '--apply' => true,
        '--json' => true,
    ]);

    $payload = json_decode($run['output'], true);

    expect($run['exit'])->toBe(1)
        ->and($payload['code'])->toBe('PLAN_DIGEST_REQUIRED')
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('refuses a stale or mistyped plan digest and writes nothing', function (): void {
    dbaEstate();

    $run = dbaRun([
        '--actor' => (string) daSupervisorRme()->id,
        '--reason' => 'Provisioning armada tablet klinik.',
        '--apply' => true,
        '--confirm-plan' => 'deadbeefcafe',
        '--json' => true,
    ]);

    $payload = json_decode($run['output'], true);

    expect($run['exit'])->toBe(1)
        ->and($payload['code'])->toBe('PLAN_DIGEST_MISMATCH')
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('refuses a digest that was valid before the estate moved', function (): void {
    $estate = dbaEstate();
    $actor = daSupervisorRme();

    $digest = dbaDigest(dbaRun(['--actor' => (string) $actor->id])['output']);

    // Another tablet is admitted between the preview and the write, so the
    // delta the operator read is no longer the delta about to be applied.
    dbaTrustedDevice([], $estate['branch']);

    $run = dbaRun([
        '--actor' => (string) $actor->id,
        '--reason' => 'Provisioning armada tablet klinik.',
        '--apply' => true,
        '--confirm-plan' => $digest,
        '--json' => true,
    ]);

    expect($run['exit'])->toBe(1)
        ->and(json_decode($run['output'], true)['code'])->toBe('PLAN_DIGEST_MISMATCH')
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('applies when the operator hands back the digest they were shown', function (): void {
    $estate = dbaEstate();
    $actor = daSupervisorRme();

    $digest = dbaDigest(dbaRun(['--actor' => (string) $actor->id])['output']);

    $run = dbaRun([
        '--actor' => (string) $actor->id,
        '--reason' => 'Provisioning armada tablet klinik.',
        '--apply' => true,
        '--confirm-plan' => $digest,
    ]);

    expect($run['exit'])->toBe(0)
        ->and($run['output'])->toContain('MODE=APPLY')
        ->and(dbaCounter($run['output'], 'APPLIED_CREATED'))->toBe(1)
        ->and(dbaActiveMatrix())
        ->toBe([$estate['account']['doctor']->id.':'.$estate['device']->id]);
});

it('refuses --dry-run and --apply together as an argument error', function (): void {
    dbaEstate();

    $run = dbaRun([
        '--actor' => (string) daSupervisorRme()->id,
        '--apply' => true,
        '--dry-run' => true,
    ]);

    expect($run['exit'])->toBe(2)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('refuses without an actor', function (): void {
    dbaEstate();

    expect(dbaRun([])['exit'])->toBe(1)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('refuses an inactive actor', function (): void {
    dbaEstate();
    $actor = daSupervisorRme();
    $actor->forceFill(['is_active' => false])->save();

    expect(dbaRun(['--actor' => (string) $actor->id])['exit'])->toBe(1);
});

it('refuses an actor holding a real role without the permission', function (): void {
    dbaEstate();

    // A real seeded role, not a role-less user: a user with no roles at all
    // would fail every permission check and prove nothing about THIS one.
    $run = dbaRun(['--actor' => (string) daUnauthorisedActor()->id, '--json' => true]);

    expect($run['exit'])->toBe(1)
        ->and(json_decode($run['output'], true)['refused'])->toBeTrue()
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('lets Supervisor RME through, which is the grant and not the bypass', function (): void {
    dbaEstate();

    // Super Admin would pass through the single global Gate::before regardless
    // of what the command checks, so it can never prove the permission gate.
    // Supervisor RME has no bypass and holds exactly this permission.
    $actor = daSupervisorRme();

    expect($actor->hasRole('Super Admin'))->toBeFalse()
        ->and($actor->can('manage_doctor_device_authorizations'))->toBeTrue()
        ->and(dbaRun(['--actor' => (string) $actor->id])['exit'])->toBe(0);
});

it('refuses to write without a reason inside the configured bounds', function (): void {
    dbaEstate();
    $actor = daSupervisorRme();
    $digest = dbaDigest(dbaRun(['--actor' => (string) $actor->id])['output']);

    $run = dbaRun([
        '--actor' => (string) $actor->id,
        '--reason' => 'x',
        '--apply' => true,
        '--confirm-plan' => $digest,
        '--json' => true,
    ]);

    expect($run['exit'])->toBe(1)
        ->and(json_decode($run['output'], true)['code'])->toBe('REASON_REQUIRED')
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('previews without a reason, because looking is not writing', function (): void {
    dbaEstate();

    expect(dbaRun(['--actor' => (string) daSupervisorRme()->id])['exit'])->toBe(0);
});

it('emits parseable JSON carrying the counters and the digest', function (): void {
    dbaEstate();

    $run = dbaRun(['--actor' => (string) daSupervisorRme()->id, '--json' => true]);
    $payload = json_decode($run['output'], true);

    expect($payload)->toBeArray()
        ->and($payload['applied'])->toBeFalse()
        ->and($payload['doctors'])->toBe(1)
        ->and($payload['trusted_devices'])->toBe(1)
        ->and($payload['proposed_new_authorizations'])->toBe(1)
        ->and($payload['final_expected_authorizations'])->toBe(1)
        ->and($payload['plan_digest'])->toHaveLength(12)
        ->and($payload['proposed'][0]['bucket'])->toBe('create');
});

it('reports an already-closed matrix as success, not as a failure', function (): void {
    $estate = dbaEstate();
    $actor = daSupervisorRme();
    dbaAuthorization($estate['account']['doctor'], $estate['device'], DoctorDeviceAuthorization::STATUS_ACTIVE);

    $digest = dbaDigest(dbaRun(['--actor' => (string) $actor->id])['output']);

    $run = dbaRun([
        '--actor' => (string) $actor->id,
        '--reason' => 'Memastikan matriks sudah lengkap.',
        '--apply' => true,
        '--confirm-plan' => $digest,
    ]);

    // The idempotent outcome. Exit 0 and an honest message, because a second
    // run over a closed matrix succeeded at what it was asked to do.
    expect($run['exit'])->toBe(0)
        ->and($run['output'])->toContain('Tidak ada otorisasi yang perlu dibuat')
        // And no audit row, because nothing happened.
        ->and(dbaAuditCount('DOCTOR_DEVICE_BULK_AUTHORIZATION_RUN'))->toBe(0);
});

it('refuses an unreadable narrowing flag instead of silently running fleet-wide', function (): void {
    $branch = daBranch('Cabang Lebar');
    daDoctorAccount([$branch]);
    daDoctorAccount([$branch]);
    $named = dbaTrustedDevice([], $branch);
    dbaTrustedDevice([], $branch);
    dbaTrustedDevice([], $branch);

    $actor = daSupervisorRme();

    // THE ATTACK. An empty list is the "whole fleet" sentinel, so a parser that
    // dropped what it could not read would turn a one-tablet run into a
    // three-tablet one — and --confirm-plan could not catch it, because the
    // preview would be wrong in exactly the same way.
    foreach (['1;2', '1 2', '', '0', 'null', 'abc'] as $malformed) {
        $run = dbaRun([
            '--actor' => (string) $actor->id,
            '--device' => $malformed,
            '--json' => true,
        ]);

        expect($run['exit'])->toBe(1, "--device={$malformed} was not refused")
            ->and(json_decode($run['output'], true)['code'])
            ->toBeIn(['SCOPE_UNREADABLE', 'SCOPE_MATCHED_NOTHING']);
    }

    // And a well-formed id still narrows, so the refusal is not just breaking
    // the feature.
    $ok = dbaRun(['--actor' => (string) $actor->id, '--device' => (string) $named->id]);

    expect($ok['exit'])->toBe(0)
        ->and(dbaCounter($ok['output'], 'ELIGIBLE_DEVICE_COUNT'))->toBe(1);
});

it('refuses a narrowing flag naming an id that is real but ineligible', function (): void {
    $branch = daBranch('Cabang Dicabut');
    daDoctorAccount([$branch]);
    dbaTrustedDevice([], $branch);
    $revoked = dbaTrustedDevice(['status' => DoctorDevice::STATUS_REVOKED], $branch);

    $run = dbaRun([
        '--actor' => (string) daSupervisorRme()->id,
        '--device' => (string) $revoked->id,
        '--json' => true,
    ]);

    // An operator who scoped a run to a tablet that turns out to be revoked
    // must be told so, not handed a confident report about other rows.
    expect($run['exit'])->toBe(1)
        ->and(json_decode($run['output'], true)['code'])->toBe('SCOPE_MATCHED_NOTHING');
});

it('never lets a malformed scope reach the write path', function (): void {
    $branch = daBranch('Cabang Tulis');
    daDoctorAccount([$branch]);
    dbaTrustedDevice([], $branch);
    dbaTrustedDevice([], $branch);

    $actor = daSupervisorRme();
    $fleetWide = dbaDigest(dbaRun(['--actor' => (string) $actor->id])['output']);

    // The reviewer's exact reproduction: hand back the FLEET-WIDE digest
    // alongside a narrowing flag that parses to nothing.
    $run = dbaRun([
        '--actor' => (string) $actor->id,
        '--device' => '1;2',
        '--reason' => 'Provisioning dua tablet pilot cabang saja.',
        '--apply' => true,
        '--confirm-plan' => $fleetWide,
        '--json' => true,
    ]);

    expect($run['exit'])->toBe(1)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('narrows to named ids and changes the digest with the delta', function (): void {
    $branch = daBranch('Cabang Filter');
    $keep = daDoctorAccount([$branch]);
    daDoctorAccount([$branch]);
    $device = dbaTrustedDevice([], $branch);
    $actor = daSupervisorRme();

    $full = dbaRun(['--actor' => (string) $actor->id]);
    $narrow = dbaRun([
        '--actor' => (string) $actor->id,
        '--doctor' => (string) $keep['doctor']->id,
        '--device' => (string) $device->id,
    ]);

    expect(dbaCounter($full['output'], 'TARGET_PAIR_COUNT'))->toBe(2)
        ->and(dbaCounter($narrow['output'], 'TARGET_PAIR_COUNT'))->toBe(1)
        ->and(dbaDigest($narrow['output']))->not->toBe(dbaDigest($full['output']));
});

it('exits non-zero under --strict when the matrix cannot be closed', function (): void {
    $estate = dbaEstate();
    dbaAuthorization($estate['account']['doctor'], $estate['device'], DoctorDeviceAuthorization::STATUS_REVOKED);

    $actor = daSupervisorRme();

    // Default run stays 0: a gate that reddens for the whole duration of a
    // staged rollout is a gate that gets removed from the chain.
    expect(dbaRun(['--actor' => (string) $actor->id])['exit'])->toBe(0)
        ->and(dbaRun(['--actor' => (string) $actor->id, '--strict' => true])['exit'])->toBe(1);
});

it('never prompts, because a prompt auto-answers when nobody is at the terminal', function (): void {
    $source = file_get_contents(app_path('Console/Commands/DoctorDeviceBulkAuthorizeCommand.php'));

    // EXECUTABLE TOKENS ONLY. A whole-file scan would also match the docblock
    // that EXPLAINS why prompting is banned, so documenting the rule would
    // break the test that enforces it — and the fix would be to delete the
    // explanation. Stripping comments first is the same approach the ENT-8
    // cache-order markers take for exactly this reason.
    $executable = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $executable .= is_array($token) ? $token[1] : $token;
    }

    // A Laravel prompt returns its default under a non-interactive invocation —
    // an SSH one-liner, a deploy script, CI — so at fleet scale it would be an
    // unreviewed write wearing the costume of a question.
    expect($executable)->not->toContain('->confirm(')
        ->and($executable)->not->toContain('confirmToProceed')
        ->and($executable)->not->toContain('->ask(')
        ->and($executable)->not->toContain('->choice(')
        // And the gate it uses instead is really there.
        ->and($executable)->toContain('--confirm-plan')
        ->and($executable)->toContain('hash_equals');
});

it('keeps the pending inbox delta visible before an operator drains it', function (): void {
    $estate = dbaEstate();
    dbaAuthorization($estate['account']['doctor'], $estate['device'], DoctorDeviceAuthorization::STATUS_PENDING);

    $run = dbaRun(['--actor' => (string) daSupervisorRme()->id]);

    // Bucket C empties a queue another operator may be working through. A run
    // that hid that number would drain it silently.
    expect(dbaCounter($run['output'], 'PENDING_INBOX_BEFORE'))->toBe(1)
        ->and(dbaCounter($run['output'], 'PENDING_INBOX_AFTER_EXPECTED'))->toBe(0)
        ->and(DB::table('mst_doctor_device_authorizations')->where('status', 'pending')->count())->toBe(1);
});
