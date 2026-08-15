<?php

/**
 * INFRA-SEC-RUNTIME-1 — dedicated runtime identity & co-tenant isolation.
 *
 * Two layers, because one cannot substitute for the other:
 *
 *  1. CONTRACT — the committed authority, pool template, systemd unit, deploy
 *     and rollback scripts and governance configs agree on ONE dedicated
 *     identity, and the discredited "first FPM pool user wins" heuristic and the
 *     www-data fallback are gone for good.
 *
 *  2. FUNCTIONAL — the real scripts/verify-runtime-isolation.sh is executed
 *     against synthetic fixtures in a private 0700 temp directory, including a
 *     negative matrix: every way the isolation can break must FAIL, not pass
 *     quietly. Mirrors the INFRA-SEC-ENV-1 test approach.
 *
 * CI cannot reproduce a real /etc/php, systemd or Unix account, so the
 * host-truth half of the invariant is proven on the production VPS by the same
 * script with --require-host. These tests prove the logic and the contract.
 */

use Illuminate\Support\Str;

/** Absolute path to a repository file. */
function isoPath(string $relative = ''): string
{
    return rtrim(base_path($relative), '/');
}

function isoRead(string $relative): string
{
    $path = isoPath($relative);

    return is_readable($path) ? (string) file_get_contents($path) : '';
}

/**
 * Build a synthetic, fully-isolated host layout in a private temp directory and
 * return its paths. The declared runtime identity is the current unprivileged
 * test user so that ownership assertions are meaningful without root.
 */
function isoFixture(array $overrides = []): array
{
    $root = sys_get_temp_dir().'/infra-sec-runtime-1-'.Str::random(12);
    @mkdir($root, 0700, true);
    chmod($root, 0700);

    $app = $root.'/app';
    $pools = $root.'/pool.d';
    $units = $root.'/systemd';
    foreach ([$app, $pools, $units, $app.'/storage', $app.'/bootstrap/cache', $app.'/storage/app/private'] as $dir) {
        @mkdir($dir, 0775, true);
    }

    $user = trim((string) shell_exec('id -un')) ?: 'runtime';
    $group = trim((string) shell_exec('id -gn')) ?: $user;

    $socket = $overrides['socket'] ?? '/run/php/php8.3-fpm-daengtisiams.sock';
    $poolUser = $overrides['pool_user'] ?? $user;
    $poolGroup = $overrides['pool_group'] ?? $group;
    $poolListen = $overrides['pool_listen'] ?? $socket;
    $nginxBind = $overrides['nginx_bind'] ?? $socket;
    $unitUser = $overrides['unit_user'] ?? $user;
    $unitGroup = $overrides['unit_group'] ?? $group;
    // The fixture's files are all owned by the test user's primary group, so the
    // secret-group breach case is driven by declaring a DIFFERENT runtime group
    // rather than by chgrp'ing the file (which an unprivileged test cannot do).
    $runtimeGroup = $overrides['runtime_group'] ?? $group;
    $envMode = $overrides['env_mode'] ?? 0640;
    $declaredUser = $overrides['declared_user'] ?? $user;
    $privateMode = $overrides['private_mode'] ?? 0770;
    // An unprivileged test cannot create a root-owned file, so the fixture
    // declares the test user as the expected secret owner. The production
    // authority declares root — asserted separately by a contract test.
    $secretOwner = $overrides['secret_owner'] ?? $user;
    // Source immutability is ownership-based and cannot be simulated
    // unprivileged in the happy path (one user owns every fixture file), so it
    // is empty by default and exercised explicitly as a breach case below.
    $sourceImmutable = $overrides['source_immutable'] ?? '';

    file_put_contents($pools.'/daengtisiams.conf', <<<POOL
        [daengtisiams]
        user = {$poolUser}
        group = {$poolGroup}
        listen = {$poolListen}
        listen.owner = www-data
        listen.group = www-data
        listen.mode = 0660
        POOL);

    if (! empty($overrides['default_pool_active'])) {
        file_put_contents($pools.'/www.conf', "[www]\nuser = www-data\ngroup = www-data\n");
    }

    file_put_contents($root.'/nginx-site', <<<NGINX
        server {
            listen 80 default_server;
            server_name _;
            root {$app}/public;
            location ~ \.php\$ {
                fastcgi_pass unix:{$nginxBind};
            }
        }
        NGINX);

    file_put_contents($units.'/daengtisiams-queue-worker.service', <<<UNIT
        [Service]
        User={$unitUser}
        Group={$unitGroup}
        ExecStart=/usr/bin/php artisan queue:work
        UNIT);

    // Secret file: never contains a real secret, only a shape.
    $envFile = $app.'/.env';
    file_put_contents($envFile, "APP_ENV=testing\n");
    chmod($envFile, $envMode);

    // Application source must stay non-runtime-writable.
    file_put_contents($app.'/artisan', "#!/usr/bin/env php\n");
    chmod($app.'/artisan', 0644);

    chmod($app.'/storage/app/private', $privateMode);

    $identity = $root.'/runtime-identity.conf';
    file_put_contents($identity, <<<CONF
        DMS_RUNTIME_USER={$declaredUser}
        DMS_RUNTIME_GROUP={$runtimeGroup}
        DMS_SECRET_OWNER={$secretOwner}
        DMS_FORBIDDEN_RUNTIME_USERS="root www-data nobody daemon postgres"
        DMS_FPM_PHP_VERSION=8.3
        DMS_FPM_POOL=daengtisiams
        DMS_FPM_SERVICE=php8.3-fpm
        DMS_FPM_POOL_FILE={$pools}/daengtisiams.conf
        DMS_FPM_DEFAULT_POOL_FILE={$pools}/www.conf
        DMS_FPM_SOCKET={$socket}
        DMS_NGINX_SITE={$root}/nginx-site
        DMS_NGINX_CONNECT_USER=www-data
        DMS_QUEUE_SERVICE=daengtisiams-queue-worker.service
        DMS_QUEUE_UNIT_SOURCE=
        DMS_BACKGROUND_SERVICES=""
        DMS_APP_DIR={$app}
        DMS_RUNTIME_WRITABLE_PATHS="storage bootstrap/cache"
        DMS_SOURCE_IMMUTABLE_PATHS="{$sourceImmutable}"
        DMS_PRIVATE_PATHS="storage/app/private"
        CONF);

    return ['root' => $root, 'app' => $app, 'pools' => $pools, 'units' => $units, 'identity' => $identity];
}

/** Run the real verifier against a fixture. Returns [exitCode, output]. */
function isoVerify(array $fx, array $extraArgs = []): array
{
    $cmd = sprintf(
        'bash %s --app-dir %s --identity-file %s --fpm-pool-dir %s --nginx-site %s --systemd-dir %s --skip-os-account %s 2>&1',
        escapeshellarg(isoPath('scripts/verify-runtime-isolation.sh')),
        escapeshellarg($fx['app']),
        escapeshellarg($fx['identity']),
        escapeshellarg($fx['pools']),
        escapeshellarg($fx['root'].'/nginx-site'),
        escapeshellarg($fx['units']),
        implode(' ', array_map('escapeshellarg', $extraArgs)),
    );

    $output = [];
    $exit = 0;
    exec($cmd, $output, $exit);

    return [$exit, implode("\n", $output)];
}

function isoCleanup(array $fx): void
{
    exec('rm -rf '.escapeshellarg($fx['root']));
}

// ── 1. Contract: the identity authority ─────────────────────────────────────

it('declares a single explicit dedicated runtime identity authority', function () {
    $conf = isoRead('deploy/runtime-identity.conf');

    expect($conf)->not->toBe('', 'deploy/runtime-identity.conf must exist');

    foreach ([
        'DMS_RUNTIME_USER', 'DMS_RUNTIME_GROUP', 'DMS_FORBIDDEN_RUNTIME_USERS',
        'DMS_FPM_POOL', 'DMS_FPM_SOCKET', 'DMS_FPM_POOL_FILE', 'DMS_NGINX_SITE',
        'DMS_QUEUE_SERVICE', 'DMS_APP_DIR', 'DMS_RUNTIME_WRITABLE_PATHS',
        'DMS_SOURCE_IMMUTABLE_PATHS', 'DMS_PRIVATE_PATHS',
    ] as $key) {
        expect($conf)->toContain($key);
    }

    // The production authority must actually enumerate the paths it protects —
    // a declared-but-empty list would silently skip the invariant on the VPS.
    foreach (['DMS_SOURCE_IMMUTABLE_PATHS', 'DMS_PRIVATE_PATHS', 'DMS_RUNTIME_WRITABLE_PATHS'] as $list) {
        expect(preg_match('/^'.$list.'="[^"]+"/m', $conf))->toBe(1, "{$list} must be non-empty in production");
    }

    // The authority must never itself carry a secret.
    foreach (['APP_KEY', 'DB_PASSWORD', 'PASSWORD=', 'SECRET='] as $forbidden) {
        expect(str_contains($conf, $forbidden))->toBeFalse("identity authority must not contain {$forbidden}");
    }
});

it('never declares a privileged or co-tenant-shared runtime identity', function () {
    $conf = isoRead('deploy/runtime-identity.conf');

    preg_match('/^DMS_RUNTIME_USER=(\S+)/m', $conf, $m);
    $user = $m[1] ?? '';

    expect($user)->not->toBe('')
        ->and($user)->not->toBe('root')
        ->and($user)->not->toBe('www-data');

    // www-data is the account the co-tenant application runs as on the shared
    // production host — it must be explicitly forbidden, not merely unused.
    preg_match('/^DMS_FORBIDDEN_RUNTIME_USERS="([^"]*)"/m', $conf, $f);
    expect($f[1] ?? '')->toContain('www-data')
        ->and($f[1] ?? '')->toContain('root');

    // The secret stays root-owned: the runtime READS its configuration through
    // the group and can never rewrite it (INFRA-SEC-ENV-1 owns the mode).
    preg_match('/^DMS_SECRET_OWNER=(\S+)/m', $conf, $o);
    expect($o[1] ?? '')->toBe('root');
});

// ── 2. Contract: the deploy defect is actually gone ─────────────────────────

it('resolves the deploy runtime user explicitly and never by scanning pools', function () {
    $deploy = isoRead('scripts/deploy-vps.sh');

    // The exact discredited heuristic: grep every pool, take the first match.
    expect($deploy)->not->toContain('/etc/php/*/fpm/pool.d/');
    expect(preg_match('/grep[^\n]*pool\.d[^\n]*head -1/', $deploy))->toBe(0);
    // The process-table guess.
    expect(preg_match('/ps -eo user,comm[^\n]*php-fpm/', $deploy))->toBe(0);
    // The forbidden silent default.
    expect(preg_match('/RUNTIME_USER=(["\']?)www-data\1/', $deploy))->toBe(0);

    // Replaced by the explicit authority + fail-closed assertions.
    expect($deploy)->toContain('RUNTIME_IDENTITY_FILE')
        ->and($deploy)->toContain('deploy/runtime-identity.conf')
        ->and($deploy)->toContain('DMS_FORBIDDEN_RUNTIME_USERS')
        ->and($deploy)->toContain('getent passwd');
});

it('fails the deploy closed when the runtime identity is missing or forbidden', function () {
    $deploy = isoRead('scripts/deploy-vps.sh');

    expect($deploy)->toContain('Refusing to guess the runtime user')
        ->and($deploy)->toContain('is forbidden (privileged, or shared with a co-tenant application)')
        ->and($deploy)->toContain('does not exist on this host');

    // Every one of those branches must abort, never continue with a default.
    expect(substr_count($deploy, 'exit 2'))->toBeGreaterThanOrEqual(4);
});

it('chowns only the runtime-writable paths to the resolved identity', function () {
    foreach (['scripts/deploy-vps.sh', 'scripts/rollback-vps.sh'] as $script) {
        $body = isoRead($script);

        expect($body)->toContain('chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}" storage bootstrap/cache');
        // No hardcoded shared-account ownership reset survives.
        expect($body)->not->toContain('chown -R www-data:www-data');
        // The application source tree is never recursively chowned.
        expect(preg_match('/chown -R [^\n]*\s(\.|\/var\/www\/asia-dental-lab-v2)\s*$/m', $body))->toBe(0);
    }
});

it('re-restricts private clinical storage after normalizing ownership', function () {
    // normalize_runtime_ownership makes the whole storage tree 2775/0664 so the
    // runtime can work. storage/app/private must NOT inherit that: without the
    // re-strip, every deploy would silently hand the co-tenant uid read access
    // to lab workflow evidence and patient documents — the exact exposure this
    // sprint closes — and would then fail the isolation gate.
    $deploy = isoRead('scripts/deploy-vps.sh');

    expect($deploy)->toContain('restrict_private_paths')
        ->and($deploy)->toContain('chmod -R o-rwx')
        ->and($deploy)->toContain('DMS_PRIVATE_PATHS');

    // It must be invoked from inside the normalizer, not merely defined beside
    // it, so the two can never drift apart.
    $start = (int) strpos($deploy, 'normalize_runtime_ownership() {');
    $end = (int) strpos($deploy, "\n}", $start);
    $normalizer = substr($deploy, $start, $end - $start);
    expect($normalizer)->toContain('restrict_private_paths');

    $rollback = isoRead('scripts/rollback-vps.sh');
    expect($rollback)->toContain('chmod -R o-rwx')
        ->and($rollback)->toContain('DMS_PRIVATE_PATHS');
});

it('runs the fail-closed isolation gate in both deploy and rollback', function () {
    foreach (['scripts/deploy-vps.sh', 'scripts/rollback-vps.sh'] as $script) {
        $body = isoRead($script);

        expect($body)->toContain('verify-runtime-isolation.sh')
            ->and($body)->toContain('--require-host')
            ->and($body)->toContain('NOT GO');

        // The fixture escape hatch must never appear on the production path.
        expect($body)->not->toContain('--skip-os-account');
    }
});

it('keeps the rollback from restoring the shared runtime identity', function () {
    $rollback = isoRead('scripts/rollback-vps.sh');

    // The secret group follows the dedicated identity, not a hardcoded account.
    expect($rollback)->toContain('--group "$RUNTIME_GROUP"')
        ->and($rollback)->not->toContain('--group www-data');

    // The identity is resolved BEFORE the checkout, because the rollback target
    // may predate this sprint and not ship the authority at all.
    $identityPos = strpos($rollback, 'RUNTIME_IDENTITY_FILE');
    $checkoutPos = strpos($rollback, 'git checkout');
    expect($identityPos)->toBeLessThan($checkoutPos);

    // And the verifier is staged so the gate survives that checkout.
    expect($rollback)->toContain('RUNTIME_GUARD_DIR');
});

// ── 3. Contract: pool, unit and governance configs agree ────────────────────

it('ships a dedicated FPM pool template matching the identity authority', function () {
    $conf = isoRead('deploy/runtime-identity.conf');
    $pool = isoRead('deploy/php-fpm/daengtisiams.conf');

    expect($pool)->not->toBe('');

    preg_match('/^DMS_RUNTIME_USER=(\S+)/m', $conf, $u);
    preg_match('/^DMS_RUNTIME_GROUP=(\S+)/m', $conf, $g);
    preg_match('/^DMS_FPM_SOCKET=(\S+)/m', $conf, $s);
    preg_match('/^DMS_FPM_POOL=(\S+)/m', $conf, $p);

    expect($pool)->toContain("[{$p[1]}]")
        ->and($pool)->toContain("user = {$u[1]}")
        ->and($pool)->toContain("group = {$g[1]}")
        ->and($pool)->toContain("listen = {$s[1]}");

    // The dedicated socket must not be the version-generic default socket that
    // any application on the host could adopt.
    expect($s[1])->not->toBe('/run/php/php8.3-fpm.sock');

    // nginx runs as www-data and must be able to connect; that is socket-connect
    // permission, not secret read.
    expect($pool)->toContain('listen.owner = www-data');

    // Hardening must not be relaxed.
    expect($pool)->toContain('clear_env = yes');
});

it('runs the queue worker under the dedicated identity in the tracked unit', function () {
    $conf = isoRead('deploy/runtime-identity.conf');
    $unit = isoRead('deploy/systemd/daengtisiams-queue-worker.service');

    preg_match('/^DMS_RUNTIME_USER=(\S+)/m', $conf, $u);
    preg_match('/^DMS_RUNTIME_GROUP=(\S+)/m', $conf, $g);

    expect($unit)->toContain("User={$u[1]}")
        ->and($unit)->toContain("Group={$g[1]}")
        ->and($unit)->not->toContain('User=www-data')
        ->and($unit)->not->toContain('Group=www-data');
});

it('pins the deployment and runtime-hardening contracts to the dedicated identity', function () {
    $conf = isoRead('deploy/runtime-identity.conf');
    preg_match('/^DMS_RUNTIME_USER=(\S+)/m', $conf, $u);

    // POST-ENT queue worker contract.
    expect(config('enterprise_foundation_runtime_hardening.queue_worker.service_user'))->toBe($u[1]);
    expect(config('enterprise_foundation_runtime_hardening.queue_worker.required_service_markers'))
        ->toContain("User={$u[1]}")
        ->not->toContain('User=www-data');

    // ENT-11 deploy/rollback contract: the isolation gate and the identity-aware
    // ownership reset are REQUIRED markers, so removing either fails CI and the
    // VPS deploy — the durable protection, not a one-time fix.
    foreach (['deploy_expectations', 'rollback_expectations'] as $section) {
        $markers = (array) config("deployment_rollback.{$section}.required_markers");
        expect($markers)->toContain('verify-runtime-isolation.sh')
            ->and($markers)->toContain('chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}"')
            ->and($markers)->not->toContain('chown -R www-data:www-data');
    }
});

it('keeps the provisioning script non-destructive and dry-run by default', function () {
    $prov = isoRead('scripts/provision-runtime-identity.sh');

    expect($prov)->not->toBe('')
        ->and($prov)->toContain('set -euo pipefail')
        ->and($prov)->toContain('APPLY=0')
        ->and($prov)->toContain('--apply');

    // Never a database command of any kind, destructive or otherwise.
    foreach ([
        'migrate:fresh', 'migrate:reset', 'db:wipe', 'schema:drop',
        'drop database', 'drop schema', 'pg_dump', 'psql ', 'DELETE FROM',
    ] as $forbidden) {
        expect(stripos($prov, $forbidden))->toBeFalse("provisioning must not contain '{$forbidden}'");
    }

    // Never a blanket recursive chown of the checkout, and never world-writable.
    expect(preg_match('/chown -R [^\n]*"?\$\{?APP_DIR\}?"?\s*$/m', $prov))->toBe(0);
    expect($prov)->not->toContain('chmod -R 777')
        ->and($prov)->not->toContain('chmod 777');

    // Config syntax is validated before any service is reloaded.
    expect(strpos($prov, '-t'))->toBeLessThan(strpos($prov, 'systemctl reload'));
});

// ── 4. Functional: the happy path is GO ─────────────────────────────────────

it('reports GO for a fully isolated synthetic host', function () {
    $fx = isoFixture();

    [$exit, $out] = isoVerify($fx);

    isoCleanup($fx);

    expect($exit)->toBe(0)
        ->and($out)->toContain('RUNTIME ISOLATION: GO')
        // No individual check reported FAIL (the summary line legitimately
        // contains the word as a zero count).
        ->and($out)->not->toContain('  FAIL ')
        ->and($out)->toContain('0 FAIL');
});

// ── 5. Functional: the negative matrix ──────────────────────────────────────

dataset('isolation breaches', [
    'FPM pool runs as the shared co-tenant account' => [
        ['pool_user' => 'www-data'],
        'pool user is',
    ],
    'FPM pool listens on the shared default socket' => [
        ['pool_listen' => '/run/php/php8.3-fpm.sock'],
        'pool listens on',
    ],
    'nginx binds a socket other than the dedicated pool' => [
        ['nginx_bind' => '/run/php/php8.5-fpm-aish-pos.sock'],
        'site binds',
    ],
    'queue worker unit still runs as the shared account' => [
        ['unit_user' => 'www-data'],
        'expected',
    ],
    'the shared default pool is still active' => [
        ['default_pool_active' => true],
        'default shared pool still active',
    ],
    'the secret file is world-readable' => [
        ['env_mode' => 0644],
        'mode is 644',
    ],
    'private clinical storage is world-readable' => [
        ['private_mode' => 0755],
        'world-readable',
    ],
    'the declared identity is the shared co-tenant account' => [
        ['declared_user' => 'www-data'],
        'forbidden',
    ],
    'the declared identity is root' => [
        ['declared_user' => 'root'],
        'forbidden',
    ],
    'the secret file group is not the dedicated runtime group' => [
        ['runtime_group' => 'www-data'],
        'environment file group is',
    ],
    'application source is owned by the runtime user' => [
        ['source_immutable' => 'artisan'],
        'must not be runtime-writable',
    ],
]);

it('fails closed on every isolation breach', function (array $breach, string $expected) {
    $fx = isoFixture($breach);

    [$exit, $out] = isoVerify($fx);

    isoCleanup($fx);

    expect($exit)->toBe(1, "breach should be NOT GO, output:\n{$out}")
        ->and($out)->toContain('RUNTIME ISOLATION: NOT GO')
        ->and(strtolower($out))->toContain(strtolower($expected));
})->with('isolation breaches');

it('refuses to run at all without an identity authority', function () {
    $fx = isoFixture();
    unlink($fx['identity']);

    [$exit, $out] = isoVerify($fx);

    isoCleanup($fx);

    expect($exit)->toBe(1)
        ->and($out)->toContain('no identity authority')
        ->and($out)->toContain('refusing to guess');
});

it('treats an uninspectable host fact as FAIL under --require-host', function () {
    $fx = isoFixture();
    // Remove the pool file: on a real host this means the dedicated pool is not
    // installed, which must never be reported as a pass.
    unlink($fx['pools'].'/daengtisiams.conf');

    // Without --require-host this degrades to SKIP...
    [$lenientExit] = isoVerify($fx);

    // ...but the production path must refuse to claim GO on evidence it could
    // not see. --require-host is incompatible with the fixture flag, so invoke
    // the verifier directly here.
    $cmd = sprintf(
        'bash %s --app-dir %s --identity-file %s --fpm-pool-dir %s --nginx-site %s --systemd-dir %s --require-host 2>&1',
        escapeshellarg(isoPath('scripts/verify-runtime-isolation.sh')),
        escapeshellarg($fx['app']),
        escapeshellarg($fx['identity']),
        escapeshellarg($fx['pools']),
        escapeshellarg($fx['root'].'/nginx-site'),
        escapeshellarg($fx['units']),
    );
    $out = [];
    $strictExit = 0;
    exec($cmd, $out, $strictExit);

    isoCleanup($fx);

    expect($lenientExit)->toBe(0)
        ->and($strictExit)->toBe(1)
        ->and(implode("\n", $out))->toContain('host evidence required but not inspectable');
});

it('refuses to combine the fixture flag with the production flag', function () {
    $cmd = sprintf(
        'bash %s --require-host --skip-os-account 2>&1',
        escapeshellarg(isoPath('scripts/verify-runtime-isolation.sh')),
    );
    $out = [];
    $exit = 0;
    exec($cmd, $out, $exit);

    expect($exit)->toBe(2)
        ->and(implode("\n", $out))->toContain('cannot be combined with --require-host');
});
