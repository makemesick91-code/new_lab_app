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
 * Build a throwaway "production" checkout carrying the real bootstrap plus a
 * synthetic deployment program, released as an APPROVED commit (rule 229:
 * the bootstrap deploys nothing else). The checkout sits at that release, so
 * the source/snapshot hash proof compares real bytes. Returns the r229 sandbox
 * with `program` set.
 */
function dh1Sandbox(string $programBody, array $files = []): array
{
    // A deploy runs exactly one program, scripts/deploy-vps.sh (rule 229).
    $sandbox = r229Sandbox(['program' => $programBody, 'files' => $files, 'checkout' => 'release']);
    $sandbox['program'] = 'deploy-vps.sh';

    return $sandbox;
}

/**
 * Run the real bootstrap as a DEPLOY of the sandbox's approved release.
 * `$approvedSha` overrides the commit the operator names.
 */
function dh1RunBootstrap(array $sandbox, array $extraArgs = [], ?string $approvedSha = null): array
{
    $cmd = implode(' ', array_map('escapeshellarg', array_merge([
        'bash', $sandbox['app'].'/scripts/deploy-immutable-exec.sh',
        '--role', 'deploy',
        '--app-dir', $sandbox['app'],
        '--lock-dir', $sandbox['lock'],
        '--branch', $sandbox['branch'],
        '--release-id', $sandbox['release'],
        '--approved-sha', $approvedSha ?? $sandbox['sha'],
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
printf '#!/usr/bin/env bash\necho POISONED\n' > "${DMS_DEPLOY_APP_DIR}/scripts/deploy-vps.sh"
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
        expect(file_get_contents($sandbox['app'].'/scripts/deploy-vps.sh'))->toContain('POISONED');
    } finally {
        dh1Cleanup($sandbox);
    }
});

test('the approved commit is pinned and run, never a newer branch tip', function () {
    dh1RequiresShellTooling();

    // Rule 229 form of "a pinned target wins over a newer commit". The release
    // carries version PINNED and is approved. The branch then moves twice.
    $sandbox = dh1Sandbox(<<<'SH'
#!/usr/bin/env bash
set -euo pipefail
echo "PROGRAM_VERSION=$(cat "$(dirname "${BASH_SOURCE[0]}")/version.txt")"
SH, ['scripts/version.txt' => "PINNED\n"]);

    try {
        // 1. Only another approval record lands: the target is still the
        //    approved commit, not the tip.
        $tip = r229Push($sandbox, ['deploy/release-approvals/dms-later-release.approval' => "# later\n"], 'a later approval');
        $result = dh1RunBootstrap($sandbox);

        expect($result['exit'])->toBe(0, $result['output'])
            ->and($result['output'])->toContain('DEPLOY_TARGET_PINNED='.$sandbox['sha'])
            ->and($result['output'])->not->toContain('DEPLOY_TARGET_PINNED='.$tip)
            ->and($result['output'])->toContain('PROGRAM_VERSION=PINNED');

        // 2. A newer CODE commit lands: the approval no longer describes the
        //    branch, so nothing runs at all — neither version.
        r229Push($sandbox, ['scripts/version.txt' => "NEWER\n"], 'a newer commit');
        $denied = dh1RunBootstrap($sandbox);

        expect($denied['exit'])->toBe(77, $denied['output'])
            ->and($denied['output'])->toContain('DEPLOYMENT=DENIED')
            ->and($denied['output'])->not->toContain('DEPLOY_TARGET_PINNED=')
            ->and($denied['output'])->not->toContain('PROGRAM_VERSION=');
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
        $result = dh1RunBootstrap($sandbox, [], str_repeat('0', 40));

        expect($result['exit'])->toBe(77, $result['output'])
            ->and($result['output'])->toContain('DEPLOYMENT=DENIED')
            ->and($result['output'])->not->toContain('SHOULD_NOT_RUN');
    } finally {
        dh1Cleanup($sandbox);
    }
});
