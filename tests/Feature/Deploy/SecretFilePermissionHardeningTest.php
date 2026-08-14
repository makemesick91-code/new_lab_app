<?php

/**
 * INFRA-SEC-ENV-1 — production secret file permission hardening contract.
 *
 * Two layers:
 *  1. FUNCTIONAL — actually executes scripts/harden-secret-permissions.sh
 *     against synthetic fixture files in a private temp dir, proving the real
 *     logic accepts the approved least-privilege modes and rejects every
 *     world-exposed one.
 *  2. STATIC CONTRACT — proves the deploy/rollback automation invokes the
 *     hardening helper and fails closed, so the repository itself cannot
 *     regress to a world-readable secret file.
 *
 * SAFETY: every fixture uses obviously synthetic values. No production secret,
 * credential, or environment value appears in this file or in its output.
 */

/** Absolute path of the hardening helper under test. */
function hardenScript(): string
{
    return base_path('scripts/harden-secret-permissions.sh');
}

/** Current unix user/group — the fixtures are owned by whoever runs the suite. */
function currentUser(): string
{
    return trim((string) shell_exec('id -un'));
}

function currentGroup(): string
{
    return trim((string) shell_exec('id -gn'));
}

/**
 * Create a private scratch application root containing synthetic env files.
 * The directory itself is 0700 so a fixture is never exposed even mid-test.
 *
 * @param  array<string, int>  $files  filename => octal mode
 */
function makeSecretFixtureDir(array $files): string
{
    $dir = sys_get_temp_dir().'/infra-sec-env-1-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    foreach ($files as $name => $mode) {
        $path = $dir.'/'.$name;
        // Written 0600 first so the fixture is never briefly world-readable.
        file_put_contents($path, "APP_KEY=fake-test-value\nDB_PASSWORD=fake\n");
        chmod($path, 0600);
        chmod($path, $mode);
    }

    return $dir;
}

function removeFixtureDir(string $dir): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir.'/'.$entry;
        if (is_link($path) || is_file($path)) {
            unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Run the hardening helper.
 *
 * @return array{exit:int, output:string}
 */
function runHarden(string $action, string $dir, array $options = []): array
{
    $cmd = 'bash '.escapeshellarg(hardenScript()).' '.escapeshellarg($action)
        .' --app-dir '.escapeshellarg($dir);

    foreach ($options as $flag => $value) {
        $cmd .= ' '.$flag.' '.escapeshellarg($value);
    }

    $output = [];
    $exit = 0;
    exec($cmd.' 2>&1', $output, $exit);

    return ['exit' => $exit, 'output' => implode("\n", $output)];
}

function fileMode(string $path): string
{
    clearstatcache(true, $path);

    return substr(sprintf('%o', fileperms($path)), -3);
}

// ─── FUNCTIONAL: approved modes are accepted ────────────────────────────────

test('verify accepts the approved 0640 runtime mode', function () {
    $dir = makeSecretFixtureDir(['.env' => 0640]);

    $result = runHarden('verify', $dir, ['--group' => currentGroup()]);

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain('SECRET FILE PERMISSIONS: GO');

    removeFixtureDir($dir);
});

test('verify accepts a 0600 runtime mode', function () {
    $dir = makeSecretFixtureDir(['.env' => 0600]);

    expect(runHarden('verify', $dir)['exit'])->toBe(0);

    removeFixtureDir($dir);
});

// ─── FUNCTIONAL: world-exposed modes are rejected ───────────────────────────

test('verify rejects a world-readable 0644 secret file', function () {
    $dir = makeSecretFixtureDir(['.env' => 0644]);

    $result = runHarden('verify', $dir);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('NOT GO')
        ->and($result['output'])->toContain('unsafe');

    removeFixtureDir($dir);
});

test('verify rejects a world-writable 0666 secret file', function () {
    $dir = makeSecretFixtureDir(['.env' => 0666]);

    expect(runHarden('verify', $dir)['exit'])->toBe(1);

    removeFixtureDir($dir);
});

test('verify rejects group-writable and other-readable variants', function () {
    $rejected = [];

    foreach ([0660, 0664, 0604, 0646] as $mode) {
        $dir = makeSecretFixtureDir(['.env' => $mode]);
        $rejected[sprintf('0%o', $mode)] = runHarden('verify', $dir)['exit'];
        removeFixtureDir($dir);
    }

    expect($rejected)->toBe(['0660' => 1, '0664' => 1, '0604' => 1, '0646' => 1]);
});

// ─── FUNCTIONAL: apply converges to least privilege ─────────────────────────

test('apply hardens a world-readable secret file to 0640', function () {
    $dir = makeSecretFixtureDir(['.env' => 0644]);

    $result = runHarden('apply', $dir, ['--group' => currentGroup()]);

    expect($result['exit'])->toBe(0)
        ->and(fileMode($dir.'/.env'))->toBe('640')
        ->and($result['output'])->toContain('SECRET FILE PERMISSIONS: GO');

    removeFixtureDir($dir);
});

test('apply hardens backup copies to 0600', function () {
    $dir = makeSecretFixtureDir([
        '.env' => 0644,
        '.env.bak-roll4-wave1-20260814' => 0644,
        '.env.backup' => 0644,
    ]);

    expect(runHarden('apply', $dir, ['--group' => currentGroup()])['exit'])->toBe(0)
        ->and(fileMode($dir.'/.env'))->toBe('640')
        ->and(fileMode($dir.'/.env.bak-roll4-wave1-20260814'))->toBe('600')
        ->and(fileMode($dir.'/.env.backup'))->toBe('600');

    removeFixtureDir($dir);
});

test('apply is idempotent', function () {
    $dir = makeSecretFixtureDir(['.env' => 0644]);

    runHarden('apply', $dir, ['--group' => currentGroup()]);
    $first = fileMode($dir.'/.env');
    $second = runHarden('apply', $dir, ['--group' => currentGroup()]);

    expect($second['exit'])->toBe(0)
        ->and(fileMode($dir.'/.env'))->toBe($first);

    removeFixtureDir($dir);
});

// ─── FUNCTIONAL: the public example file is never touched ───────────────────

test('the tracked example env file is excluded from hardening', function () {
    $dir = makeSecretFixtureDir(['.env' => 0644]);
    file_put_contents($dir.'/.env.example', "APP_KEY=\n");
    chmod($dir.'/.env.example', 0644);

    runHarden('apply', $dir, ['--group' => currentGroup()]);

    expect(fileMode($dir.'/.env.example'))->toBe('644')
        ->and(fileMode($dir.'/.env'))->toBe('640');

    removeFixtureDir($dir);
});

// ─── FUNCTIONAL: ownership expectation ──────────────────────────────────────

test('verify rejects an unexpected owner', function () {
    $dir = makeSecretFixtureDir(['.env' => 0640]);

    $result = runHarden('verify', $dir, ['--owner' => 'definitely-not-the-owner']);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('owner');

    removeFixtureDir($dir);
});

test('verify rejects an unexpected runtime group', function () {
    $dir = makeSecretFixtureDir(['.env' => 0640]);

    $result = runHarden('verify', $dir, ['--group' => 'definitely-not-the-group']);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('group');

    removeFixtureDir($dir);
});

// ─── FUNCTIONAL: symlink bypass is refused ──────────────────────────────────

test('verify fails closed on a symlinked secret file', function () {
    $dir = makeSecretFixtureDir([]);
    $target = $dir.'/real-target';
    file_put_contents($target, "APP_KEY=fake-test-value\n");
    chmod($target, 0600);
    symlink($target, $dir.'/.env');

    $result = runHarden('verify', $dir);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('symlink');

    removeFixtureDir($dir);
});

// ─── FUNCTIONAL: missing env file keeps existing deploy semantics ───────────

test('a missing environment file is reported without creating a replacement', function () {
    $dir = makeSecretFixtureDir([]);

    $result = runHarden('apply', $dir);

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain('nothing to')
        ->and(file_exists($dir.'/.env'))->toBeFalse();

    removeFixtureDir($dir);
});

// ─── FUNCTIONAL: no secret value is ever emitted ────────────────────────────

test('the helper never prints secret file contents', function () {
    $dir = makeSecretFixtureDir(['.env' => 0644]);

    $output = runHarden('apply', $dir, ['--group' => currentGroup()])['output']
        .runHarden('verify', $dir, ['--group' => currentGroup()])['output'];

    expect($output)->not->toContain('fake-test-value')
        ->and($output)->not->toContain('DB_PASSWORD')
        ->and($output)->not->toContain('APP_KEY');

    removeFixtureDir($dir);
});

// ─── FUNCTIONAL: database dumps are not world-readable ─────────────────────

/** Create a fixture backup dir with synthetic dump files. */
function makeBackupFixture(string $dir, array $files): void
{
    mkdir($dir.'/storage/app/backups/deploy', 0755, true);
    foreach ($files as $name => $mode) {
        $path = $dir.'/storage/app/backups/deploy/'.$name;
        file_put_contents($path, "-- synthetic dump, no real data\n");
        chmod($path, $mode);
    }
}

test('apply strips world access from database dumps without changing owner or group', function () {
    $dir = makeSecretFixtureDir(['.env' => 0640]);
    makeBackupFixture($dir, [
        'pre_auto_deploy_20260815-000000.sql' => 0664,
        'pre_auto_deploy_20260815-000001.sql' => 0644,
        'runtime_files_pre_sprint.tar.gz' => 0664,
    ]);

    $before = stat($dir.'/storage/app/backups/deploy/pre_auto_deploy_20260815-000000.sql');
    $result = runHarden('apply', $dir, ['--group' => currentGroup()]);
    $after = stat($dir.'/storage/app/backups/deploy/pre_auto_deploy_20260815-000000.sql');

    expect($result['exit'])->toBe(0)
        ->and(fileMode($dir.'/storage/app/backups/deploy/pre_auto_deploy_20260815-000000.sql'))->toBe('640')
        ->and(fileMode($dir.'/storage/app/backups/deploy/pre_auto_deploy_20260815-000001.sql'))->toBe('640')
        ->and(fileMode($dir.'/storage/app/backups/deploy/runtime_files_pre_sprint.tar.gz'))->toBe('640')
        // owner/group deliberately untouched so existing readers keep working
        ->and($after['uid'])->toBe($before['uid'])
        ->and($after['gid'])->toBe($before['gid']);

    removeFixtureDir($dir.'/storage/app/backups/deploy');
    removeFixtureDir($dir);
});

test('verify rejects a world-readable database dump', function () {
    $dir = makeSecretFixtureDir(['.env' => 0640]);
    makeBackupFixture($dir, ['pre_auto_deploy_20260815-000000.sql' => 0664]);

    $result = runHarden('verify', $dir, ['--group' => currentGroup()]);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('world-readable');

    removeFixtureDir($dir.'/storage/app/backups/deploy');
    removeFixtureDir($dir);
});

test('an already-tightened 0600 dump is left alone', function () {
    $dir = makeSecretFixtureDir(['.env' => 0640]);
    makeBackupFixture($dir, ['pre_auto_deploy_20260815-000000.sql' => 0600]);

    expect(runHarden('apply', $dir, ['--group' => currentGroup()])['exit'])->toBe(0)
        ->and(fileMode($dir.'/storage/app/backups/deploy/pre_auto_deploy_20260815-000000.sql'))->toBe('600');

    removeFixtureDir($dir.'/storage/app/backups/deploy');
    removeFixtureDir($dir);
});

test('the deploy and rollback scripts tighten a dump the moment it is created', function () {
    foreach (['scripts/deploy-vps.sh', 'scripts/rollback-vps.sh'] as $path) {
        $script = file_get_contents(base_path($path));

        $dumpAt = strpos($script, 'pg_dump');
        $chmodAt = strpos($script, 'chmod 0640 "$BACKUP"');

        expect($chmodAt)->not->toBeFalse()
            ->and($dumpAt)->not->toBeFalse()
            ->and($dumpAt)->toBeLessThan($chmodAt);
    }
});

// ─── STATIC CONTRACT: the helper itself ─────────────────────────────────────

test('the hardening helper is fail-fast and never recursively chmods the app tree', function () {
    $script = file_get_contents(hardenScript());

    expect($script)->toContain('set -euo pipefail')
        ->and($script)->not->toContain('chmod -R')
        ->and($script)->not->toContain('chown -R');
});

test('the hardening helper never reads or echoes secret file contents', function () {
    $script = file_get_contents(hardenScript());

    foreach (['cat "$file"', 'head -', 'tail -', 'source "$file"', 'set -x'] as $forbidden) {
        expect($script)->not->toContain($forbidden);
    }
});

// ─── STATIC CONTRACT: deploy + rollback wiring ──────────────────────────────

test('the deploy script hardens secrets before sourcing the environment file', function () {
    $script = file_get_contents(base_path('scripts/deploy-vps.sh'));

    $hardenAt = strpos($script, 'harden-secret-permissions.sh');
    $sourceAt = strpos($script, 'source .env');

    expect($hardenAt)->not->toBeFalse()
        ->and($sourceAt)->not->toBeFalse()
        ->and($hardenAt)->toBeLessThan($sourceAt);
});

test('the deploy script fails closed on unsafe secret permissions', function () {
    $script = file_get_contents(base_path('scripts/deploy-vps.sh'));

    expect($script)->toContain('harden-secret-permissions.sh verify')
        ->and($script)->toContain('secret file permissions are unsafe')
        ->and($script)->toContain('NOT GO');
});

test('the rollback script re-asserts the secret permission invariant', function () {
    $script = file_get_contents(base_path('scripts/rollback-vps.sh'));

    expect($script)->toContain('harden-secret-permissions.sh apply')
        ->and($script)->toContain('harden-secret-permissions.sh verify');
});

test('the ENT-11 deployment gate requires the hardening call in deploy and rollback', function () {
    expect(config('deployment_rollback.deploy_expectations.required_markers'))
        ->toContain('harden-secret-permissions.sh')
        ->and(config('deployment_rollback.rollback_expectations.required_markers'))
        ->toContain('harden-secret-permissions.sh');
});

// ─── STATIC CONTRACT: nothing secret is committable ─────────────────────────

test('every environment variant except the example file is gitignored', function () {
    $ignore = file_get_contents(base_path('.gitignore'));

    expect($ignore)->toContain('.env.*')
        ->and($ignore)->toContain('!.env.example');
});
