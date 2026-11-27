<?php

/*
| DMS-SPN4-READY-PILOT-1 R6-B4 (rule 245; Design Lock §13.4 row R6-B4; OD-R6-1;
| F1-B): the deploy's migrate step behind the host switch for the migrator
| (scripts/lib/r6-migrate.sh), the rollback guard (IAR-A12), and the isolation
| gate and normalisation fixes found by the F1-B VM run.
|
| Everything here runs as the unprivileged test user: the switch file is read with
| the test user as its expected owner, and the commands the step would run are
| recorded by a stand-in for dms_privsep_as_runtime. The OS-level behaviour
| (primary-group accounts, ACLs, the real migration account) is proven on the
| disposable VM (validation branch), not here.
*/

const R6M_LIB = 'scripts/lib/r6-migrate.sh';

function r6mTmp(): string
{
    $dir = sys_get_temp_dir().'/r6m-'.bin2hex(random_bytes(5));
    mkdir($dir, 0700, true);

    return $dir;
}

/** Runs $body in bash with the library sourced; returns [exit code, output]. */
function r6mRun(string $body, array $env = []): array
{
    $script = implode("\n", [
        'set -euo pipefail',
        '. '.escapeshellarg(base_path(R6M_LIB)),
        $body,
    ]);
    $proc = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env + ['PATH' => (string) getenv('PATH')]);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), (string) $out];
}

function r6mSwitch(string $dir, string $content, int $mode = 0600): string
{
    $file = $dir.'/migrator.conf';
    file_put_contents($file, $content);
    chmod($file, $mode);

    return $file;
}

function r6mValid(array $override = []): string
{
    $values = array_replace([
        'DMS_R6_MIGRATOR' => '1',
        'DMS_R6_MIGRATION_OS_USER' => 'nobody',
        'DMS_MIGRATE_SOCKET_DIR' => '/run/r6m-socket',
        'DMS_MIGRATE_PORT' => '5432',
        'DMS_MIGRATE_DATABASE' => 'r6m_db',
        'DMS_MIGRATE_USERNAME' => 'r6m_migrator',
        'DMS_MIGRATE_OWNER_ROLE' => 'r6m_owner',
        'DMS_PRIVILEGE_RUNTIME_ROLE' => 'r6m_runtime',
    ], $override);

    return implode("\n", array_map(fn ($k, $v) => "{$k}={$v}", array_keys($values), $values))."\n";
}

function r6mUid(): string
{
    return trim((string) shell_exec('id -u'));
}

function r6mGroup(): string
{
    return trim((string) shell_exec('id -gn'));
}

function r6mUser(): string
{
    return trim((string) shell_exec('id -un'));
}

it('keeps today\'s migrate when the switch file is absent or off', function (?string $content) {
    $dir = r6mTmp();
    $file = $content === null ? $dir.'/absent.conf' : r6mSwitch($dir, $content);
    [$code, $out] = r6mRun('dms_r6_switch_read '.r6mUid().' "$(id -un)" "$(id -gn)"; echo "switch=$DMS_R6_MIGRATOR"', ['DMS_R6_SWITCH_FILE' => $file]);

    expect($code)->toBe(0, $out)->and($out)->toContain('switch=0');
})->with(['absent' => [null], 'off' => ["# comment\nDMS_R6_MIGRATOR=0\n"]]);

it('reads a valid switch file without sourcing it', function () {
    $dir = r6mTmp();
    $file = r6mSwitch($dir, r6mValid());
    [$code, $out] = r6mRun('dms_r6_switch_read '.r6mUid().' "$(id -un)" "$(id -gn)"; echo "switch=$DMS_R6_MIGRATOR user=$DMS_R6_MIGRATION_OS_USER port=$DMS_MIGRATE_PORT"', ['DMS_R6_SWITCH_FILE' => $file]);

    expect($code)->toBe(0, $out)->and($out)->toContain('switch=1 user=nobody port=5432');
});

it('refuses a switch file it must not trust, and never echoes a value', function (string $content, int $mode, string $reason, string $expectedOwner) {
    $dir = r6mTmp();
    $file = r6mSwitch($dir, $content, $mode);
    $owner = $expectedOwner === 'self' ? r6mUid() : $expectedOwner;
    [$code, $out] = r6mRun('dms_r6_switch_read '.$owner.' "$(id -un)" "$(id -gn)"', ['DMS_R6_SWITCH_FILE' => $file]);

    expect($code)->toBe(1, $out)->and($out)->toContain($reason)->and($out)->toContain('NOT GO')
        ->and($out)->not->toContain('r6m_db')->and($out)->not->toContain('r6m_migrator')->and($out)->not->toContain('r6m_owner');
})->with([
    'group-writable' => [r6mValid(), 0620, 'writable by nobody else', 'self'],
    'other-writable' => [r6mValid(), 0602, 'writable by nobody else', 'self'],
    'another owner' => [r6mValid(), 0600, 'owned by the deploy identity', '0'],
    'an unknown key' => [r6mValid()."APP_KEY=x\n", 0600, 'unknown or malformed line', 'self'],
    'a line without =' => [r6mValid()."DMS_R6_MIGRATOR\n", 0600, 'unknown or malformed line', 'self'],
    'a shell substitution' => [r6mValid(['DMS_MIGRATE_DATABASE' => '$(touch /tmp/r6m-pwned)']), 0600, 'plain character class', 'self'],
    'a space' => [r6mValid(['DMS_MIGRATE_DATABASE' => 'a b']), 0600, 'plain character class', 'self'],
    'a semicolon' => [r6mValid(['DMS_MIGRATE_OWNER_ROLE' => 'x;id']), 0600, 'plain character class', 'self'],
    'an empty value' => [r6mValid(['DMS_MIGRATE_PORT' => '']), 0600, 'plain character class', 'self'],
    'a switch value other than 0 or 1' => [r6mValid(['DMS_R6_MIGRATOR' => '2']), 0600, 'must be 0 or 1', 'self'],
    'a relative socket directory' => [r6mValid(['DMS_MIGRATE_SOCKET_DIR' => 'run/s']), 0600, 'must be absolute', 'self'],
    'a non-numeric port' => [r6mValid(['DMS_MIGRATE_PORT' => '54x']), 0600, 'must be numeric', 'self'],
    'an unknown OS account' => [r6mValid(['DMS_R6_MIGRATION_OS_USER' => 'r6m-no-such-user']), 0600, 'does not exist', 'self'],
    'root as the migration account' => [r6mValid(['DMS_R6_MIGRATION_OS_USER' => 'root']), 0600, 'its own unprivileged account', 'self'],
]);

it('refuses a missing key once the switch is on', function (string $key) {
    $dir = r6mTmp();
    $lines = array_filter(explode("\n", r6mValid()), fn (string $l) => $l !== '' && ! str_starts_with($l, $key.'='));
    $file = r6mSwitch($dir, implode("\n", $lines)."\n");
    [$code, $out] = r6mRun('dms_r6_switch_read '.r6mUid().' "$(id -un)" "$(id -gn)"', ['DMS_R6_SWITCH_FILE' => $file]);

    expect($code)->toBe(1, $out)->and($out)->toContain("{$key} is missing");
})->with(['DMS_R6_MIGRATION_OS_USER', 'DMS_MIGRATE_SOCKET_DIR', 'DMS_MIGRATE_PORT', 'DMS_MIGRATE_DATABASE', 'DMS_MIGRATE_USERNAME', 'DMS_MIGRATE_OWNER_ROLE', 'DMS_PRIVILEGE_RUNTIME_ROLE']);

it('refuses a migration account that is the runtime user or in the runtime group (OD-R6-1, F1-B)', function () {
    $dir = r6mTmp();
    // The test user as the migration account: it is the runtime user here, and its primary group is the runtime group.
    $file = r6mSwitch($dir, r6mValid(['DMS_R6_MIGRATION_OS_USER' => r6mUser()]));
    [$code, $out] = r6mRun('dms_r6_switch_read '.r6mUid().' "$(id -un)" "$(id -gn)"', ['DMS_R6_SWITCH_FILE' => $file]);
    expect($code)->toBe(1, $out)->and($out)->toContain('its own unprivileged account');

    // A different runtime user, but the migration account's primary group is the runtime group.
    [$code, $out] = r6mRun('dms_r6_switch_read '.r6mUid().' r6m-other-runtime "$(id -gn)"', ['DMS_R6_SWITCH_FILE' => $file]);
    expect($code)->toBe(1, $out)->and($out)->toContain('must not be in the runtime group');
});

/** A stand-in for dms_privsep_as_runtime and chown that records every command. */
function r6mStubs(string $log, int $checkExit = 0, int $migrateExit = 0, string $split = 'auto'): string
{
    return implode("\n", [
        'dms_privsep_as_runtime() { local u="$1"; shift; printf "AS %s: %s\n" "$u" "$*" >> '.escapeshellarg($log).';',
        '  case "$*" in *"--split-state"*) if [ '.escapeshellarg($split).' = auto ]; then echo "split=${DMS_R6_MIGRATOR:-0}"; else echo '.escapeshellarg('split='.$split).'; fi; return 0 ;;',
        '    *"migrate --database=pgsql_migrate"*) return '.$migrateExit.' ;; *"db-privilege-check"*) return '.$checkExit.' ;; esac; return 0; }',
        'chown() { printf "CHOWN %s\n" "$*" >> '.escapeshellarg($log).'; }',
    ]);
}

it('runs today\'s migrate as the runtime user when the switch is off', function () {
    $dir = r6mTmp();
    $log = $dir.'/calls.log';
    [$code, $out] = r6mRun(r6mStubs($log)."\ndms_r6_migrate rt rtgroup", ['DMS_R6_SWITCH_FILE' => $dir.'/absent.conf']);

    expect($code)->toBe(0, $out)->and($out)->toContain('R6 migrator switch: 0')
        ->and(explode("\n", trim((string) file_get_contents($log))))->toBe(['AS rt: php artisan foundation:db-privilege-check --split-state', 'AS rt: php artisan migrate --force']);
});

it('migrates as the migration account with only the allowlisted environment, applies the grants, then checks as the runtime', function () {
    $dir = r6mTmp();
    $log = $dir.'/calls.log';
    $file = r6mSwitch($dir, r6mValid());
    // The switch file is read with root as the expected owner in the deploy; here the library is called with the test user.
    [$code, $out] = r6mRun(r6mStubs($log).'
dms_r6_switch_read() { DMS_R6_MIGRATOR=1; DMS_R6_MIGRATION_OS_USER=nobody; DMS_MIGRATE_SOCKET_DIR=/run/r6m-socket; DMS_MIGRATE_PORT=5432;
  DMS_MIGRATE_DATABASE=r6m_db; DMS_MIGRATE_USERNAME=r6m_migrator; DMS_MIGRATE_OWNER_ROLE=r6m_owner; DMS_PRIVILEGE_RUNTIME_ROLE=r6m_runtime; }
dms_r6_migrate rt rtgroup', ['DMS_R6_SWITCH_FILE' => $file, 'DMS_R6_CACHE_ROOT' => $dir]);
    $calls = explode("\n", trim((string) file_get_contents($log)));

    expect($code)->toBe(0, $out)->and($out)->toContain('R6 migrator switch: 1')->and($out)->toContain('GO')
        ->and($calls)->toHaveCount(5)
        ->and($calls[0])->toBe('AS rt: php artisan foundation:db-privilege-check --split-state')
        ->and($calls[1])->toStartWith('CHOWN -- nobody '.$dir.'/dms-r6-migrate.')
        ->and($calls[2])->toStartWith('AS nobody: env -i ')->and($calls[2])->toEndWith('php artisan migrate --database=pgsql_migrate --force')
        ->and($calls[3])->toStartWith('AS nobody: env -i ')->and($calls[3])->toEndWith('php artisan foundation:db-privilege-apply --apply')
        ->and($calls[4])->toBe('AS rt: env DB_MIGRATE_OWNER_ROLE=r6m_owner DB_PRIVILEGE_RUNTIME_ROLE=r6m_runtime php artisan foundation:db-privilege-check');

    // The migration process sees exactly the allowlist: no secret, every cache path redirected, the array store.
    preg_match('/env -i (.*) php artisan migrate/', $calls[2], $m);
    $names = array_map(fn (string $pair) => explode('=', $pair, 2)[0], explode(' ', $m[1]));
    expect($names)->toBe(['PATH', 'HOME', 'APP_ENV', 'LOG_CHANNEL', 'CACHE_STORE', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'DB_CONNECTION',
        'DB_MIGRATE_SOCKET_DIR', 'DB_MIGRATE_PORT', 'DB_MIGRATE_DATABASE', 'DB_MIGRATE_USERNAME', 'DB_MIGRATE_OWNER_ROLE', 'DB_PRIVILEGE_RUNTIME_ROLE',
        'APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'])
        ->and($m[1])->toContain('CACHE_STORE=array')->and($m[1])->toContain('DB_CONNECTION=pgsql_migrate')
        ->and($m[1])->not->toContain('bootstrap/cache')
        ->and(glob($dir.'/dms-r6-migrate.*'))->toBe([]);
});

it('stops the deploy before smoke when the check is not MATCH or the migration fails', function (int $checkExit, int $migrateExit, int $expectedCalls) {
    $dir = r6mTmp();
    $log = $dir.'/calls.log';
    [$code, $out] = r6mRun(r6mStubs($log, $checkExit, $migrateExit).'
dms_r6_switch_read() { DMS_R6_MIGRATOR=1; DMS_R6_MIGRATION_OS_USER=nobody; DMS_MIGRATE_SOCKET_DIR=/run/s; DMS_MIGRATE_PORT=5432;
  DMS_MIGRATE_DATABASE=d; DMS_MIGRATE_USERNAME=m; DMS_MIGRATE_OWNER_ROLE=o; DMS_PRIVILEGE_RUNTIME_ROLE=r; }
dms_r6_migrate rt rtgroup', ['DMS_R6_CACHE_ROOT' => $dir]);

    expect($code)->toBe(1, $out)->and($out)->toContain('NOT GO')
        ->and(explode("\n", trim((string) file_get_contents($log))))->toHaveCount($expectedCalls)
        ->and(glob($dir.'/dms-r6-migrate.*'))->toBe([]);
})->with([
    'drift (exit 1)' => [1, 0, 5],
    'cannot verify (exit 2)' => [2, 0, 5],
    'the migration fails' => [0, 1, 3],
]);

it('refuses before migrating when the switch disagrees with the database (OD-R6-2 v4 A4)', function (string $switch, string $split, string $reason) {
    $dir = r6mTmp();
    $log = $dir.'/calls.log';
    [$code, $out] = r6mRun(r6mStubs($log, 0, 0, $split).'
dms_r6_switch_read() { DMS_R6_MIGRATOR='.$switch.'; DMS_R6_MIGRATION_OS_USER=nobody; DMS_MIGRATE_SOCKET_DIR=/run/s; DMS_MIGRATE_PORT=5432;
  DMS_MIGRATE_DATABASE=d; DMS_MIGRATE_USERNAME=m; DMS_MIGRATE_OWNER_ROLE=o; DMS_PRIVILEGE_RUNTIME_ROLE=r; }
dms_r6_migrate rt rtgroup', ['DMS_R6_CACHE_ROOT' => $dir]);

    expect($code)->toBe(1, $out)->and($out)->toContain($reason)->and($out)->toContain('NOT GO')
        ->and(explode("\n", trim((string) file_get_contents($log))))->toBe(['AS rt: php artisan foundation:db-privilege-check --split-state']);
})->with([
    'switch off, database split' => ['0', '1', 'the migrator switch is off but the database is already split'],
    'switch on, database not split' => ['1', '0', 'the migrator switch is on but the database is not split yet'],
    'split state unknown' => ['0', 'unknown', 'cannot be determined'],
]);

it('refuses a rollback below R6-B4 while the split is active (IAR-A12), and only then', function () {
    $repo = r6mTmp();
    $git = 'git -C '.escapeshellarg($repo).' -c user.email=t@t -c user.name=t';
    exec($git.' init -q && '.$git.' commit -q --allow-empty -m old');
    $old = trim((string) shell_exec($git.' rev-parse HEAD'));
    mkdir($repo.'/scripts/lib', 0700, true);
    file_put_contents($repo.'/scripts/lib/r6-migrate.sh', "# r6-b4\n");
    exec($git.' add scripts && '.$git.' commit -q -m new');
    $new = trim((string) shell_exec($git.' rev-parse HEAD'));
    $file = r6mSwitch($repo, r6mValid(['DMS_R6_MIGRATION_OS_USER' => 'nobody']));

    $guard = fn (string $sha, string $switch) => r6mRun('cd '.escapeshellarg($repo).'; dms_r6_switch_read() { DMS_R6_MIGRATOR='.$switch.'; }; dms_r6_rollback_guard '.$sha, ['DMS_R6_SWITCH_FILE' => $file, 'RUNTIME_USER' => r6mUser(), 'RUNTIME_GROUP' => r6mGroup()]);

    [$refused, $out] = $guard($old, '1');
    expect($refused)->toBe(1, $out)->and($out)->toContain('predates R6-B4')
        ->and($guard($new, '1')[0])->toBe(0)
        ->and($guard($old, '0')[0])->toBe(0);
});

it('wires the switch into the deploy and the guard into the rollback, before any backup or checkout', function () {
    $deploy = (string) file_get_contents(base_path('scripts/deploy-vps.sh'));
    $rollback = (string) file_get_contents(base_path('scripts/rollback-vps.sh'));

    expect($deploy)->toContain('source "${DEPLOY_TOOLS_DIR}/scripts/lib/r6-migrate.sh"')
        ->and($deploy)->toContain('dms_r6_migrate "$RUNTIME_USER" "$RUNTIME_GROUP"')
        ->and($deploy)->not->toContain('as_runtime php artisan migrate --force')
        ->and(strpos($deploy, 'dms_r6_migrate "$RUNTIME_USER"'))->toBeLessThan(strpos($deploy, 'php artisan release:automated-smoke'))
        ->and($deploy)->toContain('--additional-forbidden-users')
        ->and($rollback)->toContain('dms_r6_rollback_guard "$ROLLBACK_TARGET_SHA"')
        ->and(strpos($rollback, 'dms_r6_rollback_guard'))->toBeLessThan(strpos($rollback, 'TARGET_BACKUP_CONFIG='))
        ->and($rollback)->toContain('--additional-forbidden-users');
});

it('detects extended ACLs and traverse access in private paths, and strips ACLs only there (F1-B)', function () {
    $gate = (string) file_get_contents(base_path('scripts/verify-runtime-isolation.sh'));
    $privsep = (string) file_get_contents(base_path('scripts/lib/runtime-privsep.sh'));

    // The gate: primary-group accounts, extended ACLs (with or without the ACL tools), traverse, and the config cache file itself.
    expect($gate)->toContain('as their primary group')
        ->and($gate)->toContain('getfacl -R -p --skip-base --omit-header')
        ->and($gate)->toContain("grep -c '+\$'")
        ->and($gate)->toContain('CAN traverse')
        ->and($gate)->toContain('CAN read bootstrap/cache/config.php');

    // Normalisation strips extended ACLs inside the declared private trees only.
    preg_match('/dms_privsep_normalize_modes\(\) \{(.*?)\n\}/s', $privsep, $body);
    $loop = substr($body[1], (int) strrpos($body[1], 'for rel in "$@"; do'));
    expect(substr_count($privsep, 'setfacl'))->toBe(2)
        ->and($loop)->toContain('setfacl -R -b -- "$rel"');
});
