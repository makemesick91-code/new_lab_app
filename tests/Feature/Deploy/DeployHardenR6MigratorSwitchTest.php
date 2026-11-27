<?php

/*
| DMS-SPN4-READY-PILOT-1 R6-B4 (rule 245; Design Lock §13.4 row R6-B4; OD-R6-1;
| F1-B; OD-R6-2 v5 A4/A12): the deploy's migrate step behind the host switch for
| the migrator (scripts/lib/r6-migrate.sh), its preflight before the first
| mutation, the rollback guard, and the isolation gate and normalisation fixes.
|
| Everything here runs as the unprivileged test user. The switch file is read
| with the test user as its expected owner; OS accounts come from a fixture
| passwd/group database behind stand-ins for `id` and `getent`; the commands the
| step would run are recorded by stand-ins for dms_privsep_as_runtime and
| _dms_r6_as_migrator, except where a test drives the real function through a
| fake `runuser` or `psql` on PATH. The OS-level behaviour (the real migration
| account, primary-group accounts, ACLs) is proven on the disposable VM
| (validation branch), not here.
|
| Named DeployHarden… so the `DeployHarden` token selects it in BOTH critical
| variants (CG-19); declared in config/ci_runner.php critical_gate_mandatory_suites.
*/

const R6M_LIB = 'scripts/lib/r6-migrate.sh';

function r6mTmp(): string
{
    $dir = sys_get_temp_dir().'/r6m-'.bin2hex(random_bytes(5));
    mkdir($dir, 0700, true);
    chmod($dir, 0700);

    return $dir;
}

function r6mUid(): string
{
    return trim((string) shell_exec('id -u'));
}

function r6mGid(): string
{
    return trim((string) shell_exec('id -g'));
}

/**
 * Runs $body in bash with both libraries sourced; returns [exit code, output].
 * The library's fixed paths are set AFTER sourcing (it never reads them from the
 * environment): DMS_R6_SWITCH_FILE, DMS_R6_CACHE_ROOT and DMS_R6_SWITCH_OWNER_UID
 * given in $env become assignments; the owner defaults to the test user.
 */
function r6mRun(string $body, array $env = [], ?string $cwd = null): array
{
    $env += ['DMS_R6_SWITCH_FILE' => sys_get_temp_dir().'/r6m-absent-'.bin2hex(random_bytes(4)).'.conf', 'DMS_R6_SWITCH_OWNER_UID' => r6mUid()];
    $assign = [];
    foreach (['DMS_R6_SWITCH_FILE', 'DMS_R6_CACHE_ROOT', 'DMS_R6_SWITCH_OWNER_UID'] as $key) {
        if (array_key_exists($key, $env)) {
            $assign[] = $key.'='.escapeshellarg((string) $env[$key]);
            unset($env[$key]);
        }
    }
    $script = implode("\n", array_merge([
        'set -euo pipefail',
        '. '.escapeshellarg(base_path('scripts/lib/runtime-privsep.sh')),
        '. '.escapeshellarg(base_path(R6M_LIB)),
    ], $assign, [$body]));
    $proc = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd ?? base_path(), $env + ['PATH' => (string) getenv('PATH')]);
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
        'DMS_R6_MIGRATION_OS_USER' => 'r6m-mig',
        'DMS_MIGRATE_SOCKET_DIR' => '/run/r6m-socket',
        'DMS_MIGRATE_PORT' => '5432',
        'DMS_MIGRATE_DATABASE' => 'r6m_db',
        'DMS_MIGRATE_USERNAME' => 'r6m_migrator',
        'DMS_MIGRATE_OWNER_ROLE' => 'r6m_owner',
        'DMS_PRIVILEGE_RUNTIME_ROLE' => 'r6m_runtime',
    ], $override);

    return implode("\n", array_map(fn ($k, $v) => "{$k}={$v}", array_keys($values), $values))."\n";
}

/**
 * A fixture account database behind stand-ins for `id` and `getent`. The runtime
 * user is `rt` (group `rtgroup`, gid 2001); `r6m-mig` is a valid migration account.
 */
function r6mAccounts(): string
{
    $dir = r6mTmp();
    $uid = r6mUid();
    $gid = r6mGid();
    file_put_contents($dir.'/passwd', implode("\n", [
        'root:x:0:0::/root:/bin/bash',
        'nobody:x:65534:65534::/nonexistent:/usr/sbin/nologin',
        'rt:x:2001:2001::/home/rt:/bin/bash',
        'r6m-mig:x:2002:2002::/nonexistent:/usr/sbin/nologin',
        'r6m-build:x:2003:2003::/home/b:/usr/sbin/nologin',
        'r6m-alias:x:2001:2010::/x:/usr/sbin/nologin',
        'r6m-shell:x:2004:2004::/home/s:/bin/bash',
        'r6m-primary:x:2005:2001::/x:/usr/sbin/nologin',
        'r6m-suppl:x:2006:2006::/x:/usr/sbin/nologin',
        'r6m-shared:x:2007:2008::/x:/usr/sbin/nologin',
        'r6m-other:x:2009:2008::/x:/usr/sbin/nologin',
        'r6m-member:x:2011:2011::/x:/usr/sbin/nologin',
        'r6m-builduid:x:2003:2012::/x:/usr/sbin/nologin',
        'r6m-dup1:x:2013:2013::/x:/usr/sbin/nologin',
        'r6m-dup2:x:2013:2014::/x:/usr/sbin/nologin',
        "r6m-ingroup:x:2015:{$gid}::/x:/usr/sbin/nologin",
        "r6m-sockowner:x:{$uid}:2016::/x:/usr/sbin/nologin",
    ])."\n");
    file_put_contents($dir.'/group', implode("\n", [
        'root:x:0:',
        'nogroup:x:65534:',
        'rtgroup:x:2001:',
        'r6m-mig:x:2002:',
        'r6m-build:x:2003:',
        'r6m-shell:x:2004:',
        'r6m-suppl:x:2006:',
        'rtalias:x:2001:r6m-suppl',
        'shared8:x:2008:',
        'r6m-member:x:2011:intruder',
        'r6m-dup1:x:2013:',
    ])."\n");
    $p = escapeshellarg($dir.'/passwd');
    $g = escapeshellarg($dir.'/group');

    return <<<BASH
id() {
  local opt="\$1"; shift; [ "\${1:-}" = -- ] && shift; local u="\${1:-}" line pg
  line="\$(awk -F: -v u="\$u" '\$1 == u' {$p})"; [ -n "\$line" ] || return 1
  case "\$opt" in
    -u) printf '%s\\n' "\$(printf '%s' "\$line" | cut -d: -f3)" ;;
    -g) printf '%s\\n' "\$(printf '%s' "\$line" | cut -d: -f4)" ;;
    -G) pg="\$(printf '%s' "\$line" | cut -d: -f4)"
        printf '%s%s\\n' "\$pg" "\$(awk -F: -v u="\$u" -v g="\$pg" '{ n = split(\$4, m, ","); for (i = 1; i <= n; i++) if (m[i] == u && \$3 != g) printf " %s", \$3 }' {$g})" ;;
    *) return 2 ;;
  esac
}
getent() {
  local f; case "\$1" in passwd) f={$p} ;; group) f={$g} ;; *) return 2 ;; esac
  if [ -z "\${2:-}" ]; then cat "\$f"; else awk -F: -v k="\$2" '\$1 == k { print; found = 1 } END { exit !found }' "\$f"; fi
}
BASH;
}

// ── The switch file ──────────────────────────────────────────────────────────

it('keeps today\'s migrate when the switch file is absent or off', function (?string $content) {
    $dir = r6mTmp();
    $file = $content === null ? $dir.'/absent.conf' : r6mSwitch($dir, $content);
    [$code, $out] = r6mRun(r6mAccounts().'
dms_r6_switch_read "$DMS_R6_SWITCH_OWNER_UID" rt rtgroup; echo "switch=$DMS_R6_MIGRATOR"', ['DMS_R6_SWITCH_FILE' => $file]);

    expect($code)->toBe(0, $out)->and($out)->toContain('switch=0');
})->with(['absent' => [null], 'off' => ["# comment\nDMS_R6_MIGRATOR=0\n"]]);

it('reads a valid switch file without sourcing it', function () {
    $dir = r6mTmp();
    $file = r6mSwitch($dir, r6mValid());
    [$code, $out] = r6mRun(r6mAccounts().'
dms_r6_switch_read "$DMS_R6_SWITCH_OWNER_UID" rt rtgroup "r6m-build"; echo "switch=$DMS_R6_MIGRATOR user=$DMS_R6_MIGRATION_OS_USER port=$DMS_MIGRATE_PORT"', ['DMS_R6_SWITCH_FILE' => $file]);

    expect($code)->toBe(0, $out)->and($out)->toContain('switch=1 user=r6m-mig port=5432');
});

it('takes its paths and the expected owner from itself, never from the environment (rule 233)', function () {
    $proc = proc_open(['bash', '-c', '. '.escapeshellarg(base_path(R6M_LIB)).'; echo "$DMS_R6_SWITCH_FILE|$DMS_R6_CACHE_ROOT|$DMS_R6_SWITCH_OWNER_UID"'],
        [1 => ['pipe', 'w']], $pipes, base_path(),
        ['PATH' => (string) getenv('PATH'), 'DMS_R6_SWITCH_FILE' => '/tmp/planted.conf', 'DMS_R6_CACHE_ROOT' => '/tmp', 'DMS_R6_SWITCH_OWNER_UID' => '1234']);
    $out = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    proc_close($proc);

    expect($out)->toBe('/etc/dms-r6/migrator.conf|/run|0');
});

it('refuses a switch file it must not trust, and never echoes a value', function (string $content, int $mode, string $reason, string $expectedOwner) {
    $dir = r6mTmp();
    $file = r6mSwitch($dir, $content, $mode);
    $owner = $expectedOwner === 'self' ? r6mUid() : $expectedOwner;
    [$code, $out] = r6mRun(r6mAccounts().'
dms_r6_switch_read "$DMS_R6_SWITCH_OWNER_UID" rt rtgroup', ['DMS_R6_SWITCH_FILE' => $file, 'DMS_R6_SWITCH_OWNER_UID' => $owner]);

    expect($code)->toBe(1, $out)->and($out)->toContain($reason)->and($out)->toContain('NOT GO')
        ->and($out)->not->toContain('r6m_db')->and($out)->not->toContain('r6m_migrator')->and($out)->not->toContain('r6m_owner');
})->with([
    'group-writable' => [r6mValid(), 0620, 'writable by nobody else', 'self'],
    'other-writable' => [r6mValid(), 0602, 'writable by nobody else', 'self'],
    'readable by every account' => [r6mValid(), 0644, 'writable by nobody else', 'self'],
    'another owner' => [r6mValid(), 0600, 'owned by the deploy identity', '0'],
    'an unknown key' => [r6mValid()."APP_KEY=x\n", 0600, 'unknown or malformed line', 'self'],
    'a line without =' => [r6mValid()."DMS_R6_MIGRATOR\n", 0600, 'unknown or malformed line', 'self'],
    'two keys run together (review #14)' => ["DMS_R6_MIGRATOR DMS_R6_MIGRATION_OS_USER=x\n".r6mValid(), 0600, 'unknown or malformed line', 'self'],
    'a key set twice' => [r6mValid()."DMS_MIGRATE_PORT=5433\n", 0600, 'sets DMS_MIGRATE_PORT twice', 'self'],
    'a shell substitution' => [r6mValid(['DMS_MIGRATE_DATABASE' => '$(touch /tmp/r6m-pwned)']), 0600, 'plain character class', 'self'],
    'a space' => [r6mValid(['DMS_MIGRATE_DATABASE' => 'a b']), 0600, 'plain character class', 'self'],
    'a semicolon' => [r6mValid(['DMS_MIGRATE_OWNER_ROLE' => 'x;id']), 0600, 'plain character class', 'self'],
    'an empty value' => [r6mValid(['DMS_MIGRATE_PORT' => '']), 0600, 'plain character class', 'self'],
    'a switch value other than 0 or 1' => [r6mValid(['DMS_R6_MIGRATOR' => '2']), 0600, 'must be 0 or 1', 'self'],
    'a relative socket directory' => [r6mValid(['DMS_MIGRATE_SOCKET_DIR' => 'run/s']), 0600, 'must be absolute', 'self'],
    'a non-numeric port' => [r6mValid(['DMS_MIGRATE_PORT' => '54x']), 0600, 'must be numeric', 'self'],
]);

it('refuses a switch path that is not a regular file, or under a directory another account can write (review #8)', function (string $case) {
    $dir = r6mTmp();
    $real = r6mSwitch($dir, r6mValid());
    $file = match ($case) {
        'symlink' => (function () use ($dir, $real) { symlink($real, $dir.'/link.conf');

            return $dir.'/link.conf'; })(),
        'directory' => (function () use ($dir) { mkdir($dir.'/d.conf', 0700);

            return $dir.'/d.conf'; })(),
        'group-writable parent' => (function () use ($dir, $real) { chmod($dir, 0770);

            return $real; })(),
    };
    [$code, $out] = r6mRun(r6mAccounts().'
dms_r6_switch_read "$DMS_R6_SWITCH_OWNER_UID" rt rtgroup', ['DMS_R6_SWITCH_FILE' => $file]);

    expect($code)->toBe(1, $out)->and($out)->toContain($case === 'group-writable parent' ? 'a directory above the migrator switch file' : 'not a regular file');
})->with(['symlink', 'directory', 'group-writable parent']);

it('refuses a missing key once the switch is on', function (string $key) {
    $dir = r6mTmp();
    $lines = array_filter(explode("\n", r6mValid()), fn (string $l) => $l !== '' && ! str_starts_with($l, $key.'='));
    $file = r6mSwitch($dir, implode("\n", $lines)."\n");
    [$code, $out] = r6mRun(r6mAccounts().'
dms_r6_switch_read "$DMS_R6_SWITCH_OWNER_UID" rt rtgroup', ['DMS_R6_SWITCH_FILE' => $file]);

    expect($code)->toBe(1, $out)->and($out)->toContain("{$key} is missing");
})->with(['DMS_R6_MIGRATION_OS_USER', 'DMS_MIGRATE_SOCKET_DIR', 'DMS_MIGRATE_PORT', 'DMS_MIGRATE_DATABASE', 'DMS_MIGRATE_USERNAME', 'DMS_MIGRATE_OWNER_ROLE', 'DMS_PRIVILEGE_RUNTIME_ROLE']);

// ── The migration account (OD-R6-1, F1-B, review #3 and #9) ─────────────────

it('refuses a migration account that is not its own dedicated, unprivileged, login-less account', function (string $account, string $reason) {
    $dir = r6mTmp();
    $file = r6mSwitch($dir, r6mValid(['DMS_R6_MIGRATION_OS_USER' => $account]));
    [$code, $out] = r6mRun(r6mAccounts().'
dms_r6_switch_read "$DMS_R6_SWITCH_OWNER_UID" rt rtgroup "r6m-build"', ['DMS_R6_SWITCH_FILE' => $file]);

    expect($code)->toBe(1, $out)->and($out)->toContain($reason)->and($out)->toContain('NOT GO');
})->with([
    'an unknown account' => ['r6m-no-such-user', 'does not exist'],
    'root' => ['root', 'its own unprivileged account'],
    'the runtime user' => ['rt', 'its own unprivileged account'],
    'another name for the runtime uid' => ['r6m-alias', 'its own unprivileged account'],
    'a shared system account' => ['nobody', 'shared, system, build or admin account'],
    'the build identity' => ['r6m-build', 'shared, system, build or admin account'],
    'the build identity\'s uid under another name' => ['r6m-builduid', 'share a uid with a shared, system, build or admin account'],
    'a second name for its own uid' => ['r6m-dup1', 'another account shares the migration OS account\'s uid'],
    'a login shell' => ['r6m-shell', 'no login shell'],
    'the runtime group as primary group' => ['r6m-primary', 'must not be in the runtime group'],
    'the runtime gid as a supplementary group under another name' => ['r6m-suppl', 'must not be in the runtime group'],
    'a primary group another account also has' => ['r6m-shared', 'a primary group of its own'],
    'a primary group with another member' => ['r6m-member', 'a primary group of its own'],
]);

// ── The socket the migration account connects to (review #2) ────────────────

it('accepts only a migration socket nobody else can plant', function (string $case, string $runtime, ?string $reason) {
    $dir = r6mTmp();
    $server = null;
    $port = '5432';
    if ($case === 'a regular file') {
        file_put_contents($dir.'/.s.PGSQL.5432', '');
    } elseif ($case !== 'no socket') {
        $server = stream_socket_server('unix://'.$dir.'/.s.PGSQL.5432', $errno, $errstr);
        expect($server)->not->toBeFalse($errstr);
    }
    if ($case === 'an other-writable directory') {
        chmod($dir, 0703);
    } elseif ($case === 'a group-writable directory of the runtime\'s group') {
        chmod($dir, 0770);
    }
    try {
        [$code, $out] = r6mRun(r6mAccounts().'
_dms_r6_socket_check '.escapeshellarg($dir).' '.$port.' r6m-mig '.$runtime);
    } finally {
        if (is_resource($server)) {
            fclose($server);
        }
        @unlink($dir.'/.s.PGSQL.5432');
    }

    if ($reason === null) {
        expect($code)->toBe(0, $out);
    } else {
        expect($code)->toBe(1, $out)->and($out)->toContain($reason);
    }
})->with([
    'a server socket in a private directory' => ['ok', 'rt', null],
    'no socket' => ['no socket', 'rt', 'not a server socket'],
    'a regular file' => ['a regular file', 'rt', 'not a server socket'],
    'an other-writable directory' => ['an other-writable directory', 'rt', 'can be replaced or written by another account'],
    'a group-writable directory of the runtime\'s group' => ['a group-writable directory of the runtime\'s group', 'r6m-ingroup', 'writable by a group of'],
    'a socket the runtime user owns' => ['ok', 'r6m-sockowner', 'belongs to the migration account or the runtime user'],
]);

// ── No runtime secret reaches the migration account (review #1, #10) ───────

it('clears root\'s environment before the uid switch, and passes the allowlist in the environment, never on a command line', function () {
    $dir = r6mTmp();
    mkdir($dir.'/bin', 0700);
    file_put_contents($dir.'/bin/runuser', "#!/bin/sh\nenv > ".escapeshellarg($dir.'/env.log')."\nprintf '%s\\n' \"\$@\" > ".escapeshellarg($dir.'/argv.log')."\n");
    chmod($dir.'/bin/runuser', 0755);

    [$code, $out] = r6mRun('
export APP_KEY=base64:r6m-secret-key DB_PASSWORD=r6m-secret-password R6M_OTHER=x
r6m_exported() { :; }; export -f r6m_exported
_DMS_R6_ALLOW=(PATH=/usr/local/bin:/usr/bin:/bin DB_MIGRATE_OWNER_ROLE=r6m_owner)
_dms_r6_as_migrator r6m-mig php artisan migrate --database=pgsql_migrate --force', ['PATH' => $dir.'/bin:'.getenv('PATH')]);

    $env = (string) file_get_contents($dir.'/env.log');
    $names = array_values(array_filter(array_map(fn (string $l) => explode('=', $l, 2)[0], explode("\n", trim($env)))));
    $argv = explode("\n", trim((string) file_get_contents($dir.'/argv.log')));

    expect($code)->toBe(0, $out)
        ->and($env)->not->toContain('r6m-secret')->and($env)->not->toContain('R6M_OTHER')->and($env)->not->toContain('BASH_FUNC')
        ->and($env)->toContain('DB_MIGRATE_OWNER_ROLE=r6m_owner')->and($env)->toContain('PATH=/usr/local/bin:/usr/bin:/bin')
        ->and(array_diff($names, ['PATH', 'DB_MIGRATE_OWNER_ROLE', 'PWD', 'OLDPWD', 'SHLVL', '_']))->toBe([])
        ->and($argv)->toBe(['-u', 'r6m-mig', '--', 'php', 'artisan', 'migrate', '--database=pgsql_migrate', '--force']);
});

// ── The migrate step ─────────────────────────────────────────────────────────

/** Stand-ins that record every command the step would run. */
function r6mStubs(string $log, array $o = []): string
{
    $o += ['split' => 'auto', 'idMig' => 'identity='.str_repeat('a', 64), 'idRt' => 'identity='.str_repeat('a', 64),
        'migrate' => 0, 'apply' => 0, 'pending' => 0, 'check' => 0, 'readEnv' => 1, 'socket' => 0];
    $l = escapeshellarg($log);
    $split = escapeshellarg((string) $o['split']);

    return implode("\n", [
        'dms_privsep_as_runtime() { local u="$1"; shift; printf "AS %s: %s\n" "$u" "$*" >> '.$l.';',
        '  case "$*" in',
        '    *--split-state*) if [ '.$split.' = auto ]; then echo "split=${DMS_R6_MIGRATOR:-0}"; else echo split='.$split.'; fi; return 0 ;;',
        '    *--identity*) echo '.escapeshellarg((string) $o['idRt']).'; return 0 ;;',
        '    *migrate:status*) return '.$o['pending'].' ;;',
        '    *db-privilege-check*) printf "ENV owner=%s runtime=%s\n" "${DB_MIGRATE_OWNER_ROLE:-}" "${DB_PRIVILEGE_RUNTIME_ROLE:-}" >> '.$l.'; return '.$o['check'].' ;;',
        '  esac; return 0; }',
        '_dms_r6_as_migrator() { local u="$1"; shift; printf "MIG %s: %s | %s\n" "$u" "$*" "${_DMS_R6_ALLOW[*]%%=*}" >> '.$l.';',
        '  case "$*" in',
        '    "test -r .env") return '.$o['readEnv'].' ;;',
        '    "test -w "*) return 1 ;;',
        '    *--identity*) echo '.escapeshellarg((string) $o['idMig']).'; return 0 ;;',
        '    *"migrate --database=pgsql_migrate --force") return '.$o['migrate'].' ;;',
        '    *db-privilege-apply*) return '.$o['apply'].' ;;',
        '  esac; return 0; }',
        '_dms_r6_socket_check() { printf "SOCKET\n" >> '.$l.'; [ '.$o['socket'].' = 0 ] || echo "r6-migrate: the migration socket is refused — NOT GO" >&2; return '.$o['socket'].'; }',
        'chown() { printf "CHOWN %s\n" "$*" >> '.$l.'; }',
    ]);
}

function r6mSwitchOn(string $switch = '1'): string
{
    return 'dms_r6_switch_read() { DMS_R6_MIGRATOR='.$switch.'; DMS_R6_MIGRATION_OS_USER=r6m-mig; DMS_MIGRATE_SOCKET_DIR=/run/r6m-socket; DMS_MIGRATE_PORT=5432;
  DMS_MIGRATE_DATABASE=r6m_db; DMS_MIGRATE_USERNAME=r6m_migrator; DMS_MIGRATE_OWNER_ROLE=r6m_owner; DMS_PRIVILEGE_RUNTIME_ROLE=r6m_runtime; }';
}

function r6mCalls(string $log): array
{
    return is_file($log) ? explode("\n", trim((string) file_get_contents($log))) : [];
}

it('runs the caller\'s runtime migrate as the runtime user when the switch is off', function () {
    $dir = r6mTmp();
    $log = $dir.'/calls.log';
    [$code, $out] = r6mRun(r6mStubs($log)."\ndms_r6_migrate rt rtgroup '' dms_privsep_as_runtime rt php artisan migrate --force", ['DMS_R6_SWITCH_FILE' => $dir.'/absent.conf']);

    expect($code)->toBe(0, $out)->and($out)->toContain('R6 migrator switch: 0')
        ->and(r6mCalls($log))->toBe(['AS rt: php artisan foundation:db-privilege-check --split-state', 'AS rt: php artisan migrate --force']);
});

it('proves the database identity, migrates as the migration account with only the allowlist, applies the grants, then checks as the runtime', function () {
    $dir = r6mTmp();
    $log = $dir.'/calls.log';
    [$code, $out] = r6mRun(r6mStubs($log)."\n".r6mSwitchOn()."\ndms_r6_migrate rt rtgroup '' dms_privsep_as_runtime rt php artisan migrate --force", ['DMS_R6_CACHE_ROOT' => $dir]);
    $calls = r6mCalls($log);
    $commands = array_map(fn (string $c) => explode(' | ', $c)[0], $calls);

    expect($code)->toBe(0, $out)->and($out)->toContain('R6 migrator switch: 1')->and($out)->toContain('GO')
        ->and($commands[0])->toBe('AS rt: php artisan foundation:db-privilege-check --split-state')
        ->and($commands[1])->toBe('SOCKET')
        ->and(array_slice($commands, 2, 3))->toBe(['MIG r6m-mig: test -r .env', 'MIG r6m-mig: test -w bootstrap/cache', 'MIG r6m-mig: test -w storage'])
        ->and($commands[5])->toStartWith('CHOWN -h -- r6m-mig '.$dir.'/dms-r6-migrate.')
        ->and(array_slice($commands, 6))->toBe([
            'MIG r6m-mig: php artisan foundation:db-privilege-check --identity --database=pgsql_migrate',
            'AS rt: php artisan foundation:db-privilege-check --identity',
            'MIG r6m-mig: php artisan migrate --database=pgsql_migrate --force',
            'MIG r6m-mig: php artisan foundation:db-privilege-apply --apply',
            'AS rt: php artisan migrate:status --pending=1',
            'AS rt: php artisan foundation:db-privilege-check',
            'ENV owner=r6m_owner runtime=r6m_runtime',
        ]);

    // The migration process sees exactly the allowlist: no secret, every cache path redirected, the array store.
    $migrate = collect($calls)->first(fn (string $c) => str_contains($c, 'migrate --database=pgsql_migrate --force'));
    expect(explode(' ', explode(' | ', $migrate)[1]))->toBe(['PATH', 'HOME', 'APP_ENV', 'LOG_CHANNEL', 'CACHE_STORE', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'DB_CONNECTION',
        'DB_MIGRATE_SOCKET_DIR', 'DB_MIGRATE_PORT', 'DB_MIGRATE_DATABASE', 'DB_MIGRATE_USERNAME', 'DB_MIGRATE_OWNER_ROLE', 'DB_PRIVILEGE_RUNTIME_ROLE',
        'APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'])
        ->and(implode("\n", $calls))->not->toContain('r6m_db')->and(implode("\n", $calls))->not->toContain('bootstrap/cache/config.php')
        ->and(glob($dir.'/dms-r6-migrate.*'))->toBe([]);
});

it('stops the deploy before smoke on every failure of the migrate step, and leaves no cache directory', function (array $o, string $reason, ?string $lastCommand) {
    $dir = r6mTmp();
    $log = $dir.'/calls.log';
    [$code, $out] = r6mRun(r6mStubs($log, $o)."\n".r6mSwitchOn()."\ndms_r6_migrate rt rtgroup '' dms_privsep_as_runtime rt php artisan migrate --force", ['DMS_R6_CACHE_ROOT' => $dir]);
    $commands = array_map(fn (string $c) => explode(' | ', $c)[0], r6mCalls($log));

    expect($code)->toBe(1, $out)->and($out)->toContain($reason)->and($out)->toContain('NOT GO')
        ->and(glob($dir.'/dms-r6-migrate.*'))->toBe([]);
    if ($lastCommand !== null) {
        expect(end($commands))->toBe($lastCommand);
    }
})->with([
    'the socket is not trusted' => [['socket' => 1], 'the migration socket is refused', 'SOCKET'],
    'the migration account can read the environment file' => [['readEnv' => 0], 'can read the environment file', 'MIG r6m-mig: test -r .env'],
    'another database' => [['idMig' => 'identity='.str_repeat('b', 64)], 'does not reach the runtime\'s database', 'AS rt: php artisan foundation:db-privilege-check --identity'],
    'no identity' => [['idMig' => 'identity=unknown', 'idRt' => 'identity=unknown'], 'does not reach the runtime\'s database', 'AS rt: php artisan foundation:db-privilege-check --identity'],
    'the migration fails' => [['migrate' => 1], 'failed (exit 1)', 'MIG r6m-mig: php artisan migrate --database=pgsql_migrate --force'],
    'the grants fail' => [['apply' => 1], 'failed (exit 1)', 'MIG r6m-mig: php artisan foundation:db-privilege-apply --apply'],
    'a migration still pending for the runtime' => [['pending' => 1], 'still has pending migrations', 'AS rt: php artisan migrate:status --pending=1'],
    'drift (exit 1)' => [['check' => 1], 'does not match the role separation', 'ENV owner=r6m_owner runtime=r6m_runtime'],
    'cannot verify (exit 2)' => [['check' => 2], 'does not match the role separation', 'ENV owner=r6m_owner runtime=r6m_runtime'],
]);

it('refuses before migrating when the switch disagrees with the database (OD-R6-2 v5 A4)', function (string $switch, string $split, string $reason) {
    $dir = r6mTmp();
    $log = $dir.'/calls.log';
    [$code, $out] = r6mRun(r6mStubs($log, ['split' => $split])."\n".r6mSwitchOn($switch)."\ndms_r6_migrate rt rtgroup '' dms_privsep_as_runtime rt php artisan migrate --force", ['DMS_R6_CACHE_ROOT' => $dir]);

    expect($code)->toBe(1, $out)->and($out)->toContain($reason)->and($out)->toContain('NOT GO')
        ->and(r6mCalls($log))->toBe(['AS rt: php artisan foundation:db-privilege-check --split-state']);
})->with([
    'switch off, database split' => ['0', '1', 'the migrator switch is off but the database is already split'],
    'switch on, database not split' => ['1', '0', 'the migrator switch is on but the database is not split yet'],
    'split state unknown' => ['0', 'unknown', 'cannot be determined'],
    'a probe answer that is not a state' => ['0', '0 trailing', 'cannot be determined'],
]);

// ── The preflight, before the first mutation, without application code ──────

/** A fake psql that records its environment and argv and answers $answer with exit $exit. */
function r6mPsql(string $dir, string $answer, int $exit = 0): string
{
    mkdir($dir.'/bin', 0700);
    file_put_contents($dir.'/bin/psql', "#!/bin/sh\nenv > ".escapeshellarg($dir.'/psql-env.log')."\nprintf '%s\\n' \"\$@\" > ".escapeshellarg($dir.'/psql-argv.log')."\nprintf '%s\\n' ".escapeshellarg($answer)."\nexit {$exit}\n");
    chmod($dir.'/bin/psql', 0755);
    file_put_contents($dir.'/.env', "DB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=r6m_app_db\nDB_USERNAME=r6m_app_login\nDB_PASSWORD=r6m-secret-password\n");

    return $dir.'/bin:'.getenv('PATH');
}

it('probes the split state read-only before the first mutation without application code (OD-R6-2 v5 A4)', function (string $switch, string $answer, int $exit, ?string $reason) {
    $dir = r6mTmp();
    $log = $dir.'/calls.log';
    $path = r6mPsql($dir, $answer, $exit);
    [$code, $out] = r6mRun('export PGSERVICE=r6m-planted PGSSLMODE=disable
'.r6mStubs($log)."\n".r6mSwitchOn($switch)."\ndms_r6_preflight rt rtgroup ''", ['PATH' => $path], $dir);

    $env = (string) file_get_contents($dir.'/psql-env.log');
    $argv = (string) file_get_contents($dir.'/psql-argv.log');
    expect($env)->toContain('PGUSER=r6m_app_login')->and($env)->toContain('PGDATABASE=r6m_app_db')
        ->and($env)->toContain('default_transaction_read_only=on')->and($env)->not->toContain('PGSERVICE')->and($env)->not->toContain('PGSSLMODE')
        ->and($argv)->not->toContain('r6m_app')->and($argv)->not->toContain('r6m-secret')
        // No application code ran: nothing went through the runtime stand-in.
        ->and(array_filter(r6mCalls($log), fn (string $c) => str_starts_with($c, 'AS ')))->toBe([]);
    if ($reason === null) {
        expect($code)->toBe(0, $out)->and($out)->toContain('the database state agrees');
    } else {
        expect($code)->toBe(1, $out)->and($out)->toContain($reason)->and($out)->not->toContain('r6m_app');
    }
})->with([
    'switch off, unsplit' => ['0', '0', 0, null],
    'switch on, split' => ['1', '1', 0, null],
    'switch off, already split' => ['0', '1', 0, 'already split'],
    'switch on, not split yet' => ['1', '0', 0, 'not split yet'],
    'mixed ownership' => ['0', 'unknown', 0, 'cannot be determined'],
    'psql fails' => ['0', '0', 2, 'cannot be determined'],
]);

it('refuses in the preflight, before probing, when the switch is on and the socket or the account isolation fails', function (array $o, string $reason) {
    $dir = r6mTmp();
    $log = $dir.'/calls.log';
    $path = r6mPsql($dir, '1');
    [$code, $out] = r6mRun(r6mStubs($log, $o)."\n".r6mSwitchOn()."\ndms_r6_preflight rt rtgroup ''", ['PATH' => $path], $dir);

    expect($code)->toBe(1, $out)->and($out)->toContain($reason)->and(is_file($dir.'/psql-env.log'))->toBeFalse();
})->with([
    'an untrusted socket' => [['socket' => 1], 'the migration socket is refused'],
    'a readable environment file' => [['readEnv' => 0], 'can read the environment file'],
]);

// ── The rollback guard (IAR-A12, OD-R6-2 v5 A12, review #7) ─────────────────

function r6mRepo(): array
{
    $repo = r6mTmp();
    $git = 'git -C '.escapeshellarg($repo).' -c user.email=t@t -c user.name=t';
    exec($git.' init -q && '.$git.' commit -q --allow-empty -m old');
    $old = trim((string) shell_exec($git.' rev-parse HEAD'));
    mkdir($repo.'/scripts/lib', 0700, true);
    file_put_contents($repo.'/scripts/lib/r6-migrate.sh', "# r6-b4\n");
    exec($git.' add scripts && '.$git.' commit -q -m new');
    $new = trim((string) shell_exec($git.' rev-parse HEAD'));

    return [$repo, $old, $new];
}

it('refuses a rollback below R6-B4 while the switch is on or the database is not unsplit, and only then', function (?string $switchContent, string $target, string $split, ?string $reason) {
    [$repo, $old, $new] = r6mRepo();
    $dir = r6mTmp();
    $file = $switchContent === null ? $dir.'/absent.conf' : r6mSwitch($dir, $switchContent);
    $log = $dir.'/calls.log';
    [$code, $out] = r6mRun(r6mStubs($log, ['split' => $split]).'
dms_r6_rollback_guard '.($target === 'old' ? $old : $new).' rt', ['DMS_R6_SWITCH_FILE' => $file], $repo);

    if ($reason === null) {
        expect($code)->toBe(0, $out);
    } else {
        expect($code)->toBe(1, $out)->and($out)->toContain($reason)->and($out)->toContain('NOT GO');
    }
})->with([
    'switch on, target below R6-B4' => [r6mValid(), 'old', '1', 'switch on'],
    'switch on, target at R6-B4 (the account is not checked: a rollback never migrates through the switch)' => [r6mValid(['DMS_R6_MIGRATION_OS_USER' => 'r6m-gone']), 'new', '1', null],
    'switch off, unsplit, target below R6-B4' => [null, 'old', '0', null],
    'switch off, database split, target below R6-B4' => [null, 'old', '1', 'the database is split'],
    'switch off, split state unknown, target below R6-B4' => [null, 'old', 'unknown', 'cannot be determined'],
    'switch off, database split, target at R6-B4' => [null, 'new', '1', null],
]);

it('refuses a rollback when the switch file must not be trusted', function () {
    [$repo, , $new] = r6mRepo();
    $dir = r6mTmp();
    $file = r6mSwitch($dir, r6mValid(), 0644);
    [$code, $out] = r6mRun(r6mStubs($dir.'/calls.log').'
dms_r6_rollback_guard '.$new.' rt', ['DMS_R6_SWITCH_FILE' => $file], $repo);

    expect($code)->toBe(1, $out)->and($out)->toContain('writable by nobody else');
});

// ── Wiring ────────────────────────────────────────────────────────────────────

it('wires the preflight before the first mutation, the switch into the migrate step and the guard into the rollback', function () {
    $deploy = (string) file_get_contents(base_path('scripts/deploy-vps.sh'));
    $rollback = (string) file_get_contents(base_path('scripts/rollback-vps.sh'));
    $lib = (string) file_get_contents(base_path(R6M_LIB));
    $preflight = strpos($deploy, 'dms_r6_preflight "$RUNTIME_USER" "$RUNTIME_GROUP" "$R6_FORBIDDEN_ACCOUNTS"');

    expect($deploy)->toContain('source "${DEPLOY_TOOLS_DIR}/scripts/lib/r6-migrate.sh"')
        ->and($preflight)->not->toBeFalse()
        ->and($preflight)->toBeLessThan(strpos($deploy, 'echo "== Privilege separation preflight (rule 233) =="'))
        ->and($preflight)->toBeLessThan(strpos($deploy, 'harden-secret-permissions.sh" apply'))
        ->and($preflight)->toBeLessThan(strpos($deploy, 'echo "== Backup DB =="'))
        ->and($preflight)->toBeLessThan(strpos($deploy, 'git merge --ff-only'))
        ->and($deploy)->toContain('R6_FORBIDDEN_ACCOUNTS="${BUILD_USER} ${ADMIN_USER} ${DMS_FORBIDDEN_RUNTIME_USERS:-root www-data}"')
        // The switch-0 migrate is runtime code in the deploy itself (its scanners read real code, never a comment).
        ->and($deploy)->toContain("r6_runtime_migrate() {\n  as_runtime php artisan migrate --force\n}")
        ->and($deploy)->toContain('dms_r6_migrate "$RUNTIME_USER" "$RUNTIME_GROUP" "$R6_FORBIDDEN_ACCOUNTS" r6_runtime_migrate')
        ->and(substr_count($deploy, 'php artisan migrate --force'))->toBe(1)
        ->and(strpos($deploy, 'echo "== Backup DB =="'))->toBeLessThan(strpos($deploy, 'r6_runtime_migrate() {'))
        ->and(strpos($deploy, 'dms_r6_migrate "$RUNTIME_USER"'))->toBeLessThan(strpos($deploy, 'php artisan release:automated-smoke'))
        ->and($deploy)->toContain('--additional-forbidden-users')
        ->and($rollback)->toContain('dms_r6_rollback_guard "$ROLLBACK_TARGET_SHA" "$RUNTIME_USER"')
        ->and(strpos($rollback, 'dms_r6_rollback_guard'))->toBeLessThan(strpos($rollback, 'TARGET_BACKUP_CONFIG='))
        ->and($rollback)->toContain('--additional-forbidden-users')
        // The library is part of the deployment closure, and takes no path from the environment.
        ->and(config('deployment_entrypoint.execution_closure.closure_members'))->toContain('scripts/lib/r6-migrate.sh')
        ->and($lib)->not->toMatch('/\$\{DMS_R6_(SWITCH_FILE|CACHE_ROOT|SWITCH_OWNER_UID):-/')
        ->and($lib)->toContain('chown -h --');
});

it('detects extended ACLs, traverse access, primary-group and same-gid accounts in private paths, and strips ACLs only there (F1-B)', function () {
    $gate = (string) file_get_contents(base_path('scripts/verify-runtime-isolation.sh'));
    $privsep = (string) file_get_contents(base_path('scripts/lib/runtime-privsep.sh'));

    expect($gate)->toContain('as their primary group')
        ->and($gate)->toContain('sharing the gid of')
        ->and($gate)->toContain('sources beyond local files')
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
