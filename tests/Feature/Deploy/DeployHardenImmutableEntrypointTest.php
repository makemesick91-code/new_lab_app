<?php

/**
 * DEPLOY-HARDEN-1 — Immutable Deployment Entrypoint & Self-Update Safety.
 *
 * Two layers of proof:
 *
 *  1. CONTRACT — static guards on the real deployment automation so the defect
 *     cannot be reintroduced: the deploy and rollback scripts must hand over to
 *     the trusted bootstrap before mutating, refuse to run outside the snapshot,
 *     pin an exact SHA, verify HEAD against it, and invoke every post-mutation
 *     helper from the snapshot rather than from the tree they just rewrote.
 *
 *  2. BEHAVIOUR — the REAL scripts/deploy-immutable-exec.sh is executed against
 *     a synthetic git repository. Production is never touched: the fixture has
 *     its own repo, its own lock directory and its own throwaway program.
 *
 * The behavioural tests deliberately do NOT try to reproduce the original
 * undefined behaviour (a shell resuming at a stale byte offset). They assert the
 * property that makes it impossible: the executing program's bytes are
 * unchanged after the repository copy of that same program has been rewritten.
 */

use App\Support\Deploy\DeploymentEntrypointScanner;

function dh1Script(string $relative): string
{
    return (string) file_get_contents(base_path($relative));
}

function dh1DeployScript(): string
{
    return dh1Script('scripts/deploy-vps.sh');
}

function dh1RollbackScript(): string
{
    return dh1Script('scripts/rollback-vps.sh');
}

/**
 * Build a throwaway git repository carrying the real bootstrap plus a synthetic
 * deployment program. Returns the sandbox root.
 */
function dh1Sandbox(string $programBody, string $programName = 'fixture-program.sh'): array
{
    $root = sys_get_temp_dir().'/dh1-'.bin2hex(random_bytes(6));
    $app = $root.'/app';
    $lock = $root.'/lock';

    mkdir($app.'/scripts', 0700, true);
    mkdir($app.'/deploy', 0700, true);
    mkdir($lock, 0700, true);

    copy(base_path('scripts/deploy-immutable-exec.sh'), $app.'/scripts/deploy-immutable-exec.sh');
    file_put_contents($app.'/deploy/runtime-identity.conf', "DMS_RUNTIME_USER=fixture\nDMS_RUNTIME_GROUP=fixture\n");
    file_put_contents($app.'/scripts/'.$programName, $programBody);

    $git = 'git -C '.escapeshellarg($app).' -c user.email=fixture@test -c user.name=fixture ';
    shell_exec($git.'init -q . 2>&1');
    shell_exec($git.'add -A 2>&1');
    shell_exec($git.'commit -qm fixture 2>&1');

    return ['root' => $root, 'app' => $app, 'lock' => $lock, 'program' => $programName];
}

function dh1RunBootstrap(array $sandbox, array $extraArgs = []): array
{
    $cmd = implode(' ', array_map('escapeshellarg', array_merge([
        'bash', $sandbox['app'].'/scripts/deploy-immutable-exec.sh',
        '--role', 'deploy',
        '--app-dir', $sandbox['app'],
        '--lock-dir', $sandbox['lock'],
    ], $extraArgs, ['--', 'scripts/'.$sandbox['program']])));

    $output = [];
    $exit = 0;
    exec($cmd.' 2>&1', $output, $exit);

    return ['output' => implode("\n", $output), 'exit' => $exit];
}

function dh1Cleanup(array $sandbox): void
{
    exec('rm -rf '.escapeshellarg($sandbox['root']));
}

function dh1RequiresShellTooling(): void
{
    foreach (['flock', 'git', 'tar'] as $binary) {
        if (trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null')) === '') {
            test()->markTestSkipped("{$binary} is not available on this host");
        }
    }
}

// ─── Contract: the trusted bootstrap exists and is complete ─────────────────

test('the immutable execution bootstrap exists and is fail-fast', function () {
    expect(file_exists(base_path('scripts/deploy-immutable-exec.sh')))->toBeTrue()
        ->and(dh1Script('scripts/deploy-immutable-exec.sh'))->toContain('set -euo pipefail');
});

test('the bootstrap posture is complete: lock, pin, snapshot, trust, cleanup, hashes', function () {
    $posture = app(DeploymentEntrypointScanner::class)->bootstrapPosture();

    expect($posture['issues'])->toBe([])
        ->and($posture['ok'])->toBeTrue()
        ->and($posture['lock_present'])->toBeTrue()
        ->and($posture['trusted_root_present'])->toBeTrue()
        ->and($posture['target_pinning_present'])->toBeTrue()
        ->and($posture['snapshot_present'])->toBeTrue()
        ->and($posture['trust_boundary_present'])->toBeTrue()
        ->and($posture['cleanup_present'])->toBeTrue()
        ->and($posture['hash_proof_present'])->toBeTrue()
        ->and($posture['run_id_present'])->toBeTrue();
});

test('the snapshot is exported from a git object, never from the working tree', function () {
    // git archive streams the tracked bytes of a COMMIT, so a dirty checkout
    // cannot poison the deployment payload.
    expect(dh1Script('scripts/deploy-immutable-exec.sh'))->toContain('git archive');
});

test('lock and snapshot live outside the application directory', function () {
    $bootstrap = dh1Script('scripts/deploy-immutable-exec.sh');

    // storage/ and bootstrap/cache are writable by the DaengtisiaMS runtime user
    // (and were historically readable by the co-tenant), so a deployment payload
    // or lock living there could be replaced by the process it constrains.
    expect($bootstrap)->toContain('/run/daengtisiams-deploy')
        ->and($bootstrap)->not->toContain('storage/framework/deploy')
        ->and($bootstrap)->not->toContain('bootstrap/cache/deploy');
});

// ─── Contract: both mutating entrypoints are safe ───────────────────────────

test('deploy and rollback hand over to the bootstrap and refuse mutable execution', function (string $key) {
    $posture = app(DeploymentEntrypointScanner::class)->mutatingEntrypointPosture($key);

    expect($posture['issues'])->toBe([])
        ->and($posture['hands_over_to_bootstrap'])->toBeTrue()
        ->and($posture['refuses_mutable_execution'])->toBeTrue()
        ->and($posture['pins_exact_target'])->toBeTrue()
        ->and($posture['verifies_head_against_target'])->toBeTrue()
        ->and($posture['guards_dirty_checkout'])->toBeTrue()
        ->and($posture['uses_snapshot_tools'])->toBeTrue()
        ->and($posture['no_working_tree_invocation'])->toBeTrue();
})->with(['deploy_script', 'rollback_script']);

test('post-mutation helpers are never re-read from the working tree', function () {
    // The regression this closes: after `git checkout` rewrote the tree, the old
    // deploy ran `bash scripts/harden-secret-permissions.sh` and
    // `bash scripts/verify-runtime-isolation.sh` out of that freshly rewritten
    // tree — the same self-modification defect one indirection removed.
    foreach ([dh1DeployScript(), dh1RollbackScript()] as $script) {
        expect($script)->not->toContain('bash scripts/harden-secret-permissions.sh')
            ->and($script)->not->toContain('bash scripts/verify-runtime-isolation.sh')
            ->and($script)->toContain('${DEPLOY_TOOLS_DIR}/scripts/harden-secret-permissions.sh')
            ->and($script)->toContain('${DEPLOY_TOOLS_DIR}/scripts/verify-runtime-isolation.sh');
    }
});

test('the deploy no longer pulls a moving branch tip', function () {
    $script = dh1DeployScript();

    // `git pull --ff-only origin <branch>` resolved the target DURING the run:
    // a branch that advanced mid-deploy silently retargeted it. The deploy now
    // fast-forwards to the SHA pinned before the critical section began.
    expect($script)->not->toContain('git pull --ff-only origin')
        ->and($script)->toContain('git merge --ff-only "$TARGET_SHA"')
        ->and($script)->toContain('DMS_DEPLOY_TARGET_SHA');
});

test('rollback resolves the operator ref to one exact commit before mutating', function () {
    $script = dh1RollbackScript();

    expect($script)->toContain('ROLLBACK_TARGET_SHA')
        ->and($script)->toContain('git checkout "$ROLLBACK_TARGET_SHA"')
        ->and($script)->not->toContain('git checkout "$TARGET_REF"');
});

test('the execution closure is complete and carries the live runtime identity', function () {
    $posture = app(DeploymentEntrypointScanner::class)->executionClosurePosture();

    expect($posture['issues'])->toBe([])
        ->and($posture['closure_complete'])->toBeTrue()
        ->and($posture['identity_overlay_present'])->toBeTrue();
});

// ─── Contract: existing safety foundations survive the hardening ────────────

test('backup still runs before migration and backup failure still aborts', function () {
    $script = dh1DeployScript();

    expect(strpos($script, 'pg_dump'))->toBeLessThan(strpos($script, 'migrate --force'))
        ->and($script)->toContain('set -euo pipefail')
        ->and($script)->toContain('test -s "$BACKUP"');
});

test('the deploy never discards production work to resolve a dirty checkout', function () {
    foreach ([dh1DeployScript(), dh1RollbackScript()] as $script) {
        expect($script)->not->toContain('git reset --hard')
            ->and($script)->not->toContain('git clean -')
            ->and($script)->toContain('git status --porcelain --untracked-files=no');
    }
});

test('INFRA-SEC-ENV-1 and INFRA-SEC-RUNTIME-1 gates remain mandatory', function () {
    foreach ([dh1DeployScript(), dh1RollbackScript()] as $script) {
        expect($script)->toContain('harden-secret-permissions.sh')
            ->and($script)->toContain('verify-runtime-isolation.sh')
            ->and($script)->toContain('--require-host')
            ->and($script)->toContain('chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}"');
    }
});

test('DEPLOY OK is emitted only after the final pinned-target verification', function () {
    $script = dh1DeployScript();

    expect(strpos($script, 'DEPLOY_HEAD_TARGET_MATCH'))->toBeLessThan(strpos($script, 'echo "DEPLOY OK'))
        ->and(strpos($script, 'verify-runtime-isolation.sh'))->toBeLessThan(strpos($script, 'echo "DEPLOY OK'));
});

test('deployment scripts never print a secret value', function () {
    foreach ([dh1DeployScript(), dh1RollbackScript(), dh1Script('scripts/deploy-immutable-exec.sh')] as $script) {
        expect($script)->not->toContain('echo "${DB_PASSWORD}')
            ->and($script)->not->toContain('cat .env')
            ->and($script)->not->toContain('echo $DB_PASSWORD');
    }
});

// ─── Behaviour: the real bootstrap, driven against a synthetic repository ───

test('a repository rewrite during a run cannot change the executing program', function () {
    dh1RequiresShellTooling();

    // The fixture rewrites its OWN repository copy mid-run, then compares the
    // bytes it is executing from before and after that rewrite.
    $sandbox = dh1Sandbox(<<<'SH'
#!/usr/bin/env bash
set -euo pipefail
echo "MARKER_ORIGINAL_PAYLOAD"
before="$(sha256sum "${BASH_SOURCE[0]}" | awk '{print $1}')"
printf '#!/usr/bin/env bash\necho POISONED\n' > "${DMS_DEPLOY_APP_DIR}/scripts/fixture-program.sh"
after="$(sha256sum "${BASH_SOURCE[0]}" | awk '{print $1}')"
[ "$before" = "$after" ] && echo "EXEC_SOURCE_IMMUTABLE=YES" || { echo "EXEC_SOURCE_IMMUTABLE=NO"; exit 9; }
grep -q MARKER_ORIGINAL_PAYLOAD "${BASH_SOURCE[0]}" && echo "PAYLOAD_INTACT=YES"
SH);

    try {
        $result = dh1RunBootstrap($sandbox);

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toContain('EXEC_SOURCE_IMMUTABLE=YES')
            ->and($result['output'])->toContain('PAYLOAD_INTACT=YES')
            // The program executed from the snapshot, not from the checkout.
            ->and($result['output'])->toContain('DEPLOY_EXECUTION_SOURCE='.$sandbox['lock'])
            // Source and snapshot were byte-identical at capture time.
            ->and($result['output'])->toMatch('/SOURCE_DEPLOY_SCRIPT_SHA256=([0-9a-f]{64})\s+SNAPSHOT_DEPLOY_SCRIPT_SHA256=\1/');

        // The working-tree copy really was rewritten — the fixture proves
        // immutability of the RUNNING program, not absence of mutation.
        expect(file_get_contents($sandbox['app'].'/scripts/fixture-program.sh'))->toContain('POISONED');
    } finally {
        dh1Cleanup($sandbox);
    }
});

test('a target pinned before the run wins over a newer commit', function () {
    dh1RequiresShellTooling();

    $sandbox = dh1Sandbox(<<<'SH'
#!/usr/bin/env bash
set -euo pipefail
echo "PROGRAM_VERSION=$(cat "$(dirname "${BASH_SOURCE[0]}")/version.txt")"
SH);

    try {
        $git = 'git -C '.escapeshellarg($sandbox['app']).' -c user.email=fixture@test -c user.name=fixture ';
        file_put_contents($sandbox['app'].'/scripts/version.txt', "PINNED\n");
        shell_exec($git.'add -A 2>&1');
        shell_exec($git.'commit -qm pinned 2>&1');
        $pinned = trim((string) shell_exec($git.'rev-parse HEAD'));

        // The branch advances after the pin — that commit belongs to a LATER
        // deployment and must not retarget this one.
        file_put_contents($sandbox['app'].'/scripts/version.txt', "NEWER\n");
        shell_exec($git.'add -A 2>&1');
        shell_exec($git.'commit -qm newer 2>&1');

        $result = dh1RunBootstrap($sandbox, ['--target-sha', $pinned]);

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toContain('DEPLOY_TARGET_PINNED='.$pinned)
            ->and($result['output'])->toContain('PROGRAM_VERSION=PINNED')
            ->and($result['output'])->not->toContain('PROGRAM_VERSION=NEWER');
    } finally {
        dh1Cleanup($sandbox);
    }
});

test('the snapshot is trust-verified and removed after the run', function () {
    dh1RequiresShellTooling();

    $sandbox = dh1Sandbox("#!/usr/bin/env bash\nset -euo pipefail\nstat -c '%a' \"\$DMS_DEPLOY_SNAPSHOT_DIR\"\necho RAN\n");

    try {
        $result = dh1RunBootstrap($sandbox);

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toContain('DEPLOY_SNAPSHOT_TRUSTED=PASS')
            ->and($result['output'])->toContain('DEPLOY_SNAPSHOT_CLEANED=YES')
            // Owner-only: not writable by the runtime user or any co-tenant.
            ->and($result['output'])->toContain('700');

        $leftovers = glob($sandbox['lock'].'/deploy-*') ?: [];
        expect($leftovers)->toBe([]);
    } finally {
        dh1Cleanup($sandbox);
    }
});

test('a failing deployment keeps its exit code and still cleans up', function () {
    dh1RequiresShellTooling();

    $sandbox = dh1Sandbox("#!/usr/bin/env bash\necho FAILING\nexit 42\n");

    try {
        $result = dh1RunBootstrap($sandbox);

        // A cleanup trap must never turn a failed deployment into a success.
        expect($result['exit'])->toBe(42)
            ->and($result['output'])->toContain('DEPLOY_SNAPSHOT_CLEANED=YES');

        expect(glob($sandbox['lock'].'/deploy-*') ?: [])->toBe([]);
    } finally {
        dh1Cleanup($sandbox);
    }
});

test('the bootstrap refuses a target commit it does not have', function () {
    dh1RequiresShellTooling();

    $sandbox = dh1Sandbox("#!/usr/bin/env bash\necho SHOULD_NOT_RUN\n");

    try {
        $result = dh1RunBootstrap($sandbox, ['--target-sha', str_repeat('0', 40)]);

        expect($result['exit'])->not->toBe(0)
            ->and($result['output'])->not->toContain('SHOULD_NOT_RUN');
    } finally {
        dh1Cleanup($sandbox);
    }
});
