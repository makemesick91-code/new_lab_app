<?php

/**
 * DEPLOY-HARDEN-1 — deployment concurrency lock.
 *
 * Two deployments must never enter the critical section (backup, migration,
 * checkout mutation) at the same time. The lock is a kernel-backed flock on a
 * root-controlled path, so it is released automatically when the holder dies —
 * a crashed deployment can never leave a permanently stuck lock the way a
 * hand-rolled PID file does.
 *
 * Everything here runs against a synthetic git repository with its own lock
 * directory. No real deployment is ever started, and production is never
 * touched: proving concurrency denial by racing two real migrations would be
 * exactly the thing this sprint exists to prevent.
 */

use App\Support\Deploy\DeploymentEntrypointScanner;

function dh1LockSandbox(string $programBody): array
{
    $root = sys_get_temp_dir().'/dh1lock-'.bin2hex(random_bytes(6));
    $app = $root.'/app';
    $lock = $root.'/lock';

    mkdir($app.'/scripts', 0700, true);
    mkdir($app.'/deploy', 0700, true);
    mkdir($lock, 0700, true);

    copy(base_path('scripts/deploy-immutable-exec.sh'), $app.'/scripts/deploy-immutable-exec.sh');
    file_put_contents($app.'/deploy/runtime-identity.conf', "DMS_RUNTIME_USER=fixture\nDMS_RUNTIME_GROUP=fixture\n");
    file_put_contents($app.'/scripts/fixture-program.sh', $programBody);

    $git = 'git -C '.escapeshellarg($app).' -c user.email=fixture@test -c user.name=fixture ';
    shell_exec($git.'init -q . 2>&1');
    shell_exec($git.'add -A 2>&1');
    shell_exec($git.'commit -qm fixture 2>&1');

    return ['root' => $root, 'app' => $app, 'lock' => $lock];
}

function dh1LockCommand(array $sandbox): string
{
    return implode(' ', array_map('escapeshellarg', [
        'bash', $sandbox['app'].'/scripts/deploy-immutable-exec.sh',
        '--role', 'deploy',
        '--app-dir', $sandbox['app'],
        '--lock-dir', $sandbox['lock'],
        '--', 'scripts/fixture-program.sh',
    ]));
}

function dh1LockRun(array $sandbox): array
{
    $output = [];
    $exit = 0;
    exec(dh1LockCommand($sandbox).' 2>&1', $output, $exit);

    return ['output' => implode("\n", $output), 'exit' => $exit];
}

function dh1LockCleanup(array $sandbox): void
{
    exec('rm -rf '.escapeshellarg($sandbox['root']));
}

function dh1LockRequiresTooling(): void
{
    foreach (['flock', 'git', 'tar'] as $binary) {
        if (trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null')) === '') {
            test()->markTestSkipped("{$binary} is not available on this host");
        }
    }
}

test('the deployment lock is declared as a mandatory contract', function () {
    $posture = app(DeploymentEntrypointScanner::class)->bootstrapPosture();

    expect($posture['lock_present'])->toBeTrue()
        ->and($posture['trusted_root_present'])->toBeTrue()
        ->and(file_get_contents(base_path('scripts/deploy-immutable-exec.sh')))->toContain('flock -n 9');
});

test('a second concurrent deployment fails closed before any mutation', function () {
    dh1LockRequiresTooling();

    // The holder sleeps inside the critical section; the challenger must be
    // refused before it can take a backup, migrate or move the checkout.
    $sandbox = dh1LockSandbox("#!/usr/bin/env bash\nset -euo pipefail\necho HOLDER_IN_CRITICAL_SECTION\nsleep 6\necho HOLDER_DONE\n");

    try {
        $holderLog = $sandbox['root'].'/holder.log';
        $cmd = dh1LockCommand($sandbox);
        exec('nohup bash -c '.escapeshellarg($cmd.' > '.escapeshellarg($holderLog).' 2>&1').' > /dev/null 2>&1 &');

        // Wait until the holder is demonstrably inside the critical section.
        $waited = 0;
        while ($waited < 50 && ! str_contains((string) @file_get_contents($holderLog), 'HOLDER_IN_CRITICAL_SECTION')) {
            usleep(100_000);
            $waited++;
        }
        expect(@file_get_contents($holderLog))->toContain('HOLDER_IN_CRITICAL_SECTION');

        $challenger = dh1LockRun($sandbox);

        expect($challenger['exit'])->toBe(75)
            ->and($challenger['output'])->toContain('BUSY another deploy is already running')
            ->and($challenger['output'])->toContain('no backup, no migration, no checkout was performed')
            // Fail-closed means it never even built an execution payload.
            ->and($challenger['output'])->not->toContain('DEPLOY_SNAPSHOT_CREATED')
            ->and($challenger['output'])->not->toContain('DEPLOY_TARGET_PINNED');

        // Let the holder finish so the lock is released cleanly.
        $waited = 0;
        while ($waited < 150 && ! str_contains((string) @file_get_contents($holderLog), 'HOLDER_DONE')) {
            usleep(100_000);
            $waited++;
        }
        expect(@file_get_contents($holderLog))->toContain('HOLDER_DONE');
    } finally {
        dh1LockCleanup($sandbox);
    }
});

test('the lock is released after a successful deployment', function () {
    dh1LockRequiresTooling();

    $sandbox = dh1LockSandbox("#!/usr/bin/env bash\nset -euo pipefail\necho RAN\n");

    try {
        $first = dh1LockRun($sandbox);
        $second = dh1LockRun($sandbox);

        expect($first['exit'])->toBe(0)
            ->and($second['exit'])->toBe(0)
            ->and($second['output'])->toContain('DEPLOY_LOCK_ACQUIRED=YES')
            ->and($second['output'])->not->toContain('BUSY');
    } finally {
        dh1LockCleanup($sandbox);
    }
});

test('the lock is released after a failed deployment', function () {
    dh1LockRequiresTooling();

    $sandbox = dh1LockSandbox("#!/usr/bin/env bash\necho FAILING\nexit 3\n");

    try {
        $first = dh1LockRun($sandbox);
        $second = dh1LockRun($sandbox);

        expect($first['exit'])->toBe(3)
            // A failed run must not wedge the next deployment behind a stale lock.
            ->and($second['exit'])->toBe(3)
            ->and($second['output'])->toContain('DEPLOY_LOCK_ACQUIRED=YES');
    } finally {
        dh1LockCleanup($sandbox);
    }
});

test('the lock is released when the deployment process is killed', function () {
    dh1LockRequiresTooling();

    // flock is released by the kernel when the holding process dies, so an
    // abrupt kill cannot leave the deployment permanently blocked.
    $sandbox = dh1LockSandbox("#!/usr/bin/env bash\necho SUICIDE\nkill -9 \$\$\n");

    try {
        $crashed = dh1LockRun($sandbox);
        expect($crashed['exit'])->not->toBe(0);

        $next = dh1LockRun($sandbox);
        expect($next['output'])->toContain('DEPLOY_LOCK_ACQUIRED=YES')
            ->and($next['output'])->not->toContain('BUSY');
    } finally {
        dh1LockCleanup($sandbox);
    }
});

test('a leftover lock file with no holder does not block the next deployment', function () {
    dh1LockRequiresTooling();

    $sandbox = dh1LockSandbox("#!/usr/bin/env bash\nset -euo pipefail\necho RAN\n");

    try {
        // The advisory lock is the authority. A lock FILE existing on disk (or a
        // stale human-readable status left behind by an earlier run) is not, and
        // must never be mistaken for a held lock.
        file_put_contents($sandbox['lock'].'/deploy.lock', "stale\n");
        file_put_contents($sandbox['lock'].'/deploy.status', "pid=999999 running\n");

        $result = dh1LockRun($sandbox);

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toContain('DEPLOY_LOCK_ACQUIRED=YES')
            ->and($result['output'])->not->toContain('BUSY');
    } finally {
        dh1LockCleanup($sandbox);
    }
});

test('deploy and rollback take separate role-scoped locks', function () {
    // A rollback is a different critical section from a deploy; each serialises
    // against itself. The lock file name is role-scoped.
    expect(file_get_contents(base_path('scripts/deploy-immutable-exec.sh')))
        ->toContain('LOCK_FILE="${LOCK_DIR}/${ROLE}.lock"');
});

test('snapshot removal is path-guarded and symlink-safe', function () {
    $bootstrap = file_get_contents(base_path('scripts/deploy-immutable-exec.sh'));

    // `rm -rf` runs as root here, so every guard matters: the path must be
    // non-empty, must match this run's own snapshot exactly, must be a real
    // directory and must not be a symlink pointing out of the sandbox.
    expect($bootstrap)->toContain('refusing to remove unexpected path')
        ->and($bootstrap)->toContain('refusing to remove a symlink')
        ->and($bootstrap)->toContain('rm -rf -- "$dir"')
        ->and($bootstrap)->not->toContain('rm -rf "$SNAPSHOT_DIR"')
        ->and($bootstrap)->not->toContain('rm -rf /');
});
