<?php

/**
 * DMS-DEPLOY-PRIVSEP-HARDENING-1 (rule 233) — privilege separation between the
 * root deploy/rollback program and the runtime user (OD-1 DEPLOY-PRIVSEP).
 *
 * The runtime user owns storage/ and bootstrap/cache. Before this sprint the
 * deploy ran composer's scripts, `artisan migrate` and ~50 governance gates as
 * root (the rollback even rebuilt the configuration cache as root), and ran
 * chown -R / chmod -R / find -exec chmod as root over those trees, so a runtime
 * compromise became root at the next deploy or rollback.
 *
 *  1. STATIC — neither script runs application PHP as root, runs composer's
 *     scripts, redirects into a runtime tree or mutates one by path as root;
 *     the scanner that enforces this in CI and on the VPS catches each regression.
 *  2. BEHAVIOUR — the real scripts/lib/runtime-privsep.sh against fixtures: who
 *     runs what, symlinks never followed, hardlinks and device nodes refused,
 *     modes set without widening private trees, the source tree claimed
 *     top-down, drift gated, and no file name ever printed.
 *
 * Tests run unprivileged, so "the runtime user" is the test user; runuser itself
 * is exercised on the host by the deploy (the root-only branches are pinned
 * statically here).
 */

use App\Support\Deploy\DeploymentRollbackScanner;

function psRead(string $relative): string
{
    $path = base_path($relative);

    return is_readable($path) ? (string) file_get_contents($path) : '';
}

/** Non-comment lines of a script. */
function psCode(string $relative): string
{
    return (string) preg_replace('/^\s*#.*$/m', '', psRead($relative));
}

/**
 * Run bash with the privilege-separation library sourced, from $cwd.
 *
 * @return array{0:int,1:string,2:string}
 */
function psRun(string $cwd, string $snippet, array $env = []): array
{
    $script = 'set -euo pipefail; . '.escapeshellarg(base_path('scripts/lib/runtime-privsep.sh'))."\n".$snippet;
    $proc = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env === [] ? null : $env + getenv());
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), (string) $out, (string) $err];
}

/** @return array{user:string,group:string,uid:string,gid:string} */
function psMe(): array
{
    return [
        'user' => trim((string) shell_exec('id -un')),
        'group' => trim((string) shell_exec('id -gn')),
        'uid' => trim((string) shell_exec('id -u')),
        'gid' => trim((string) shell_exec('id -g')),
    ];
}

/** A secondary group of the test user, or null when there is none. */
function psSecondaryGroup(): ?string
{
    $primary = trim((string) shell_exec('id -gn'));
    foreach (preg_split('/\s+/', trim((string) shell_exec('id -Gn'))) ?: [] as $group) {
        if ($group !== '' && $group !== $primary) {
            return $group;
        }
    }

    return null;
}

function psMode(string $path): string
{
    clearstatcache(true, $path);

    return substr(sprintf('%o', fileperms($path)), -4);
}

function psGroupOf(string $path): string
{
    clearstatcache(true, $path);

    return (string) (posix_getgrgid((int) filegroup($path))['name'] ?? '');
}

/** An application directory with runtime trees, source files and an environment file. */
function psApp(): string
{
    $app = tempArtifactDir('privsep-app-', 0755);
    $dirs = [
        'storage/app/private', 'storage/app/backups/deploy', 'storage/logs',
        'storage/framework/views', 'bootstrap/cache', 'public/build/assets', 'app',
    ];
    foreach ($dirs as $dir) {
        mkdir($app.'/'.$dir, 0755, true);
    }
    chmod($app.'/bootstrap', 0755);
    chmod($app.'/public', 0755);
    file_put_contents($app.'/storage/app/private/document.pdf', 'synthetic');
    file_put_contents($app.'/storage/app/backups/deploy/dump.sql', 'synthetic');
    file_put_contents($app.'/storage/framework/views/compiled.php', 'synthetic');
    file_put_contents($app.'/bootstrap/cache/config.php', 'synthetic');
    file_put_contents($app.'/public/build/assets/app.js', 'synthetic');
    file_put_contents($app.'/app/Kernel.php', 'synthetic');
    file_put_contents($app.'/.env', 'synthetic');
    chmod($app.'/.env', 0640);

    return $app;
}

function psPrivatePaths(): string
{
    return 'storage/app/private storage/app/backups storage/logs bootstrap/cache';
}

// ── 1. Static: what the deploy and the rollback may run as root ─────────────

it('never runs application PHP as root and never mutates a runtime tree by path in deploy or rollback', function (string $script) {
    expect((new DeploymentRollbackScanner)->privilegeSeparationIssues(psRead($script)))->toBe([]);

    // Every artisan call is the runtime user's, either directly or through the
    // runtime-opened evidence redirect.
    foreach (preg_split('/\R/', psCode($script)) ?: [] as $line) {
        if (str_contains($line, 'php artisan')) {
            expect(preg_match('/^\s*(as_runtime php artisan |runtime_json \S+ php artisan )/', $line))
                ->toBe(1, "root artisan call: {$line}");
        }
    }
})->with([
    'deploy' => ['scripts/deploy-vps.sh'],
    'rollback' => ['scripts/rollback-vps.sh'],
]);

it('rejects each way root PHP or a root path mutation could come back', function (string $regression) {
    $script = psRead('scripts/deploy-vps.sh')."\n".$regression."\n";

    expect((new DeploymentRollbackScanner)->privilegeSeparationIssues($script))->not->toBe([]);
})->with([
    'root artisan' => ['php artisan migrate --force'],
    'root artisan inside if' => ['if ! php artisan foundation:health-check; then exit 1; fi'],
    'root artisan after &&' => ['true && php artisan about'],
    'root PHP script' => ['php storage/prod_smoke.php'],
    'composer with scripts' => ['composer install --no-dev'],
    'composer run-script' => ['COMPOSER_ALLOW_SUPERUSER=1 composer run-script post-autoload-dump'],
    'recursive chown' => ['chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}" storage bootstrap/cache'],
    'recursive chmod' => ['chmod -R o-rwx storage/app/private'],
    'long recursive chmod' => ['chmod --recursive u+rwX bootstrap/cache'],
    'find -exec chmod' => ['find storage -type f -exec chmod 0664 {} \;'],
    'find -execdir chown' => ['find storage -execdir chown x {} +'],
    'root evidence redirect' => ['as_runtime php artisan x --json > storage/release-evidence/latest/x.json'],
    'root log append' => ['echo done >> "${APP_DIR}/storage/logs/deploy.log"'],
    'root mkdir in a runtime tree' => ['mkdir -p storage/framework/cache'],
    'root rm in bootstrap cache' => ['rm -f bootstrap/cache/config.php'],
    // Bypasses found by the independent review (sprint doc §8).
    'artisan behind timeout' => ['timeout 900 php artisan migrate --force'],
    'artisan behind nice' => ['nice php artisan about'],
    'absolute php path' => ['/usr/bin/php artisan about'],
    'versioned php binary' => ['php8.3 artisan about'],
    'php in backticks' => ['x=`php artisan about`'],
    'php inside bash -c' => ["bash -c 'php artisan about'"],
    'composer behind timeout' => ['timeout 600 composer install --no-dev'],
    'composer split over lines' => ["composer install \\\n  --no-dev"],
    'root dump directory helper' => ['dms_prepare_private_backup_dir storage/app/backups'],
    'root dump writer' => ['PGPASSWORD="${DB_PASSWORD}" dms_write_private_dump "$BACKUP" pg_dump'],
    'stderr redirect' => ['as_runtime php artisan x 2> storage/logs/x.log'],
    'combined redirect' => ['as_runtime php artisan x &> storage/logs/x.log'],
    'quoted app dir redirect' => ['echo y > "$APP_DIR"/storage/x'],
    'tee -a' => ['echo y | tee -a storage/logs/x'],
    'chmod after &&' => ['[ -d x ] && chmod 0640 storage/x'],
    'xargs chmod' => ['find storage -print0 | xargs -0 chmod 0664'],
    'find -exec absolute chmod' => ['find storage -type f -exec /bin/chmod 0664 {} +'],
    'source a runtime file' => ['source storage/app/x.sh'],
]);

it('accepts the separated forms the scripts actually use', function () {
    $scanner = new DeploymentRollbackScanner;
    $base = psRead('scripts/deploy-vps.sh');

    foreach ([
        'as_runtime php artisan about',
        'runtime_json storage/release-evidence/latest/x.json php artisan x --json || true',
        'as_runtime rm -f -- bootstrap/cache/config.php',
        '# chown -R storage in a comment is not code',
        'as_runtime rm -f -- bootstrap/cache/x.php  # rm storage in a trailing comment',
        'mkdir -p /etc/x  # not storage/',
        'echo "# not a comment: storage"',
        "composer install --no-dev --optimize-autoloader \\\n  --no-scripts",
        'run runuser -u "$RUNTIME_USER" -- php artisan view:cache',
        'echo "$(php -r \'echo PHP_VERSION;\' 2>/dev/null || true)"',
        'echo "$(COMPOSER_ALLOW_SUPERUSER=1 composer --version --no-ansi 2>/dev/null)"',
        'echo "FATAL: something" >&2',
    ] as $line) {
        expect($scanner->privilegeSeparationIssues($base."\n".$line."\n"))->toBe([], $line);
    }
});

it('reports a missing privilege-separation marker', function () {
    $script = str_replace(
        'dms_privsep_final_gate 0 0 "$RUNTIME_USER" "$RUNTIME_GROUP"',
        'true',
        psRead('scripts/rollback-vps.sh')
    );

    expect(implode("\n", (new DeploymentRollbackScanner)->privilegeSeparationIssues($script)))
        ->toContain('missing marker: dms_privsep_final_gate');
});

it('folds privilege separation into the deploy and rollback postures', function () {
    $scanner = new DeploymentRollbackScanner;

    expect($scanner->deployScriptPosture())->toMatchArray(['ok' => true, 'privilege_separation' => true])
        ->and($scanner->rollbackScriptPosture())->toMatchArray(['ok' => true, 'privilege_separation' => true]);
});

it('runs composer without scripts and replays exactly its post-autoload-dump as the runtime user', function (string $script) {
    // The deploy mirrors composer.json; a new post-autoload-dump step must be
    // mirrored too, or it would silently stop running.
    $composer = json_decode(psRead('composer.json'), true);
    expect($composer['scripts']['post-autoload-dump'] ?? null)->toBe([
        'Illuminate\\Foundation\\ComposerScripts::postAutoloadDump',
        '@php artisan package:discover --ansi',
    ]);

    $code = psCode($script);
    $install = strpos($code, 'composer install --no-dev --optimize-autoloader --no-scripts');
    $clear = strpos($code, 'as_runtime rm -f -- bootstrap/cache/config.php bootstrap/cache/services.php bootstrap/cache/packages.php');
    $discover = strpos($code, 'as_runtime php artisan package:discover --ansi');
    $npm = strpos($code, 'npm ci');

    expect($install)->not->toBeFalse()
        ->and($clear)->toBeGreaterThan((int) $install)
        ->and($discover)->toBeGreaterThan((int) $clear)
        ->and($npm)->toBeGreaterThan((int) $discover);
})->with([
    'deploy' => ['scripts/deploy-vps.sh'],
    'rollback' => ['scripts/rollback-vps.sh'],
]);

it('writes every JSON evidence file through a redirect the runtime user opens', function () {
    $code = psCode('scripts/deploy-vps.sh');

    expect($code)->not->toMatch('/--json\s*>/')
        ->and(substr_count($code, "\nruntime_json storage/release-evidence/latest/"))->toBe(25)
        ->and(psRead('scripts/lib/runtime-privsep.sh'))
        ->toContain('dms_privsep_as_runtime "$user" bash -c \'out="$1"; shift; exec "$@" > "$out"\'');
});

it('creates the pre-deploy and pre-rollback dumps as the runtime user', function (string $script, string $dir) {
    $code = psCode($script);

    expect($code)->toContain('dms_privsep_as_runtime_fn "$RUNTIME_USER" "$DUMP_HELPERS" dms_prepare_private_backup_dir storage/app/backups')
        ->and($code)->toContain("dms_privsep_as_runtime_fn \"\$RUNTIME_USER\" \"\$DUMP_HELPERS\" dms_prepare_private_backup_dir storage/app/backups/{$dir}")
        ->and($code)->toContain('PGPASSWORD="${DB_PASSWORD}" dms_privsep_as_runtime_fn "$RUNTIME_USER" "$DUMP_HELPERS" dms_write_private_dump "$BACKUP" pg_dump')
        ->and($code)->toContain('as_runtime test -s "$BACKUP"')
        ->and($code)->toContain('sha256=$(as_runtime sha256sum -- "$BACKUP"');
    expect(preg_match('/^\s*dms_(prepare_private_backup_dir|write_private_dump)\b/m', $code))->toBe(0);
})->with([
    'deploy' => ['scripts/deploy-vps.sh', 'deploy'],
    'rollback' => ['scripts/rollback-vps.sh', 'rollback'],
]);

it('prepares before the first mutation and gates drift before the restart and the success marker', function (string $script, string $okMarker) {
    $code = psCode($script);
    $source = strpos($code, 'source "${DEPLOY_TOOLS_DIR}/scripts/lib/runtime-privsep.sh"');
    $prepare = strpos($code, 'dms_privsep_prepare 0 0 "$RUNTIME_USER" "$RUNTIME_GROUP"');
    $dump = strpos($code, 'pg_dump');
    $checkout = strpos($code, 'git checkout');
    $isolation = strpos($code, 'verify-runtime-isolation.sh" \\');
    $gate = strpos($code, 'dms_privsep_final_gate 0 0 "$RUNTIME_USER" "$RUNTIME_GROUP"');
    $restart = strpos($code, 'systemctl restart php8.3-fpm');
    $ok = strpos($code, $okMarker);

    expect($source)->not->toBeFalse()
        ->and($prepare)->toBeGreaterThan((int) $source)
        ->and($dump)->toBeGreaterThan((int) $prepare)
        ->and($checkout)->toBeGreaterThan((int) $prepare)
        ->and($gate)->toBeGreaterThan((int) $isolation)
        ->and($restart)->toBeGreaterThan((int) $gate)
        ->and($ok)->toBeGreaterThan((int) $gate);
    // Secret hardening as root hands the dump chmod to the runtime user.
    expect(substr_count($code, '--runtime-user "$RUNTIME_USER"'))->toBeGreaterThanOrEqual(2);
})->with([
    'deploy' => ['scripts/deploy-vps.sh', 'echo "DEPLOY OK: '],
    'rollback' => ['scripts/rollback-vps.sh', 'echo "ROLLBACK OK: '],
]);

it('carries the library in the immutable execution closure and never sources it from the working tree', function () {
    expect(config('deployment_entrypoint.execution_closure.closure_members'))->toContain('scripts/lib/runtime-privsep.sh')
        ->and(config('deployment_rollback.privilege_separation.library'))->toBe('scripts/lib/runtime-privsep.sh');

    foreach (['scripts/deploy-vps.sh', 'scripts/rollback-vps.sh'] as $script) {
        expect(psCode($script))->not->toMatch('/(source|\.)\s+"?(\$\{?APP_DIR\}?\/)?scripts\/lib\/runtime-privsep\.sh/');
    }
});

it('keeps the library free of application PHP and of root mode changes', function () {
    $lib = psRead('scripts/lib/runtime-privsep.sh');
    $code = psCode('scripts/lib/runtime-privsep.sh');

    expect($code)->not->toMatch('/\bphp\b/')
        ->and($code)->not->toContain('chown -R')
        ->and($code)->not->toContain('-exec chmod {} \\;')
        ->and($code)->not->toContain('777');
    // The only recursive chmod lives in the runtime-side function, which refuses root.
    preg_match('/^dms_privsep_normalize_modes\(\) \{\n.*?^\}$/ms', $lib, $m);
    expect($m[0] ?? '')->toContain('refusing to normalize runtime modes as root')
        ->and($m[0] ?? '')->toContain('chmod -R u+rwX,o-rwx');
    expect(substr_count($code, 'chmod -R'))->toBe(1);
    // Root's ownership repair is lchown from inside each directory, never a hardlink.
    expect($code)->toContain('-execdir chown -h -- "${user}:${group}" {} +')
        ->and($code)->toContain('-type f -links +1');
});

it('hands the dump chmod to the runtime user when the secret helper runs as root', function () {
    $helper = psRead('scripts/harden-secret-permissions.sh');
    preg_match('/^harden_data_backup\(\) \{\n.*?^\}$/ms', $helper, $m);
    $body = $m[0] ?? '';

    expect($helper)->toContain('--runtime-user) RUNTIME_USER="${2:-}"; shift 2 ;;')
        ->and($body)->toContain('runuser -u "$RUNTIME_USER" -- chmod "0${DATA_BACKUP_MODE}" -- "$file"')
        ->and($body)->toContain('root never chmods inside the runtime-writable backup tree');
    // As root, the only chmod in the function is the runtime user's.
    $rootBranch = substr($body, (int) strpos($body, 'owner="$(stat'));
    expect(substr_count($rootBranch, 'chmod'))->toBe(2); // the runuser chmod + the "never chmods" note
});

// ── 2. Behaviour of scripts/lib/runtime-privsep.sh ──────────────────────────

it('runs a command as the runtime user with a private umask and refuses root or an unknown account', function () {
    $app = psApp();
    $me = psMe();

    [$exit, , $err] = psRun($app, 'dms_privsep_as_runtime '.escapeshellarg($me['user']).' touch storage/logs/created.log');
    expect($exit)->toBe(0, $err)->and(psMode($app.'/storage/logs/created.log'))->toBe('0640');

    foreach (['root', 'no-such-account-'.bin2hex(random_bytes(3)), ''] as $who) {
        [$exit, , $err] = psRun($app, 'dms_privsep_as_runtime '.escapeshellarg($who).' touch storage/logs/never.log');
        expect($exit)->toBe(2)->and($err)->toContain('runtime-privsep:');
    }
    expect(file_exists($app.'/storage/logs/never.log'))->toBeFalse();
});

it('runs snapshot functions as the runtime user and refuses an unknown function', function () {
    $app = psApp();
    $me = escapeshellarg(psMe()['user']);

    [$exit, $out] = psRun($app, "greet() { echo \"hello \$1 from \$(id -un)\"; }\ndms_privsep_as_runtime_fn {$me} '' greet world");
    expect($exit)->toBe(0)->and(trim($out))->toBe('hello world from '.psMe()['user']);

    [$exit, , $err] = psRun($app, "dms_privsep_as_runtime_fn {$me} 'missing_helper' dms_privsep_normalize_modes");
    expect($exit)->toBe(2)->and($err)->toContain("unknown function 'missing_helper'");
});

it('writes command output through a redirect opened by the runtime user', function () {
    $app = psApp();
    $me = escapeshellarg(psMe()['user']);

    [$exit, , $err] = psRun($app, "dms_privsep_runtime_output {$me} storage/out.json printf '{\"ok\":true}'");
    expect($exit)->toBe(0, $err)
        ->and(file_get_contents($app.'/storage/out.json'))->toBe('{"ok":true}')
        ->and(psMode($app.'/storage/out.json'))->toBe('0640');
});

it('requires the kernel hardlink and symlink protections', function (string $hardlinks, string $symlinks, int $expected) {
    $sys = tempArtifactDir('privsep-sysctl-');
    file_put_contents($sys.'/protected_hardlinks', $hardlinks."\n");
    if ($symlinks !== 'absent') {
        file_put_contents($sys.'/protected_symlinks', $symlinks."\n");
    }

    [$exit, $out, $err] = psRun($sys, 'dms_privsep_kernel_guards '.escapeshellarg($sys));

    expect($exit)->toBe($expected);
    if ($expected === 0) {
        expect($out)->toContain('DEPLOY_PRIVSEP_KERNEL_PROTECTED_HARDLINKS=1')
            ->and($out)->toContain('DEPLOY_PRIVSEP_KERNEL_PROTECTED_SYMLINKS=1');
    } else {
        expect($err)->toContain('NOT GO');
    }
})->with([
    'both on' => ['1', '1', 0],
    'hardlinks off' => ['0', '1', 1],
    'symlinks off' => ['1', '0', 1],
    'symlinks unreadable' => ['1', 'absent', 1],
]);

it('creates a missing runtime root and refuses a symlinked root or a writable parent', function () {
    $me = psMe();
    $args = escapeshellarg($me['uid']).' '.escapeshellarg($me['user']).' '.escapeshellarg($me['group']);

    $app = psApp();
    unlink($app.'/bootstrap/cache/config.php');
    rmdir($app.'/bootstrap/cache');
    [$exit, , $err] = psRun($app, "dms_privsep_runtime_roots {$args} storage bootstrap/cache");
    expect($exit)->toBe(0, $err)->and(is_dir($app.'/bootstrap/cache'))->toBeTrue();

    $app = psApp();
    rename($app.'/storage', $app.'/storage-real');
    symlink('storage-real', $app.'/storage');
    [$exit, , $err] = psRun($app, "dms_privsep_runtime_roots {$args} storage bootstrap/cache");
    expect($exit)->toBe(1)->and($err)->toContain('runtime root storage is a symlink');

    $app = psApp();
    chmod($app.'/bootstrap', 0775);
    [$exit, , $err] = psRun($app, "dms_privsep_runtime_roots {$args} storage bootstrap/cache");
    expect($exit)->toBe(1)->and($err)->toContain('bootstrap is group- or world-writable');

    // The parent must belong to the trusted owner (root on the host).
    $app = psApp();
    [$exit, , $err] = psRun($app, 'dms_privsep_runtime_roots 0 '.escapeshellarg($me['user']).' '.escapeshellarg($me['group']).' storage');
    expect($exit)->toBe(1)->and($err)->toContain('expected 0');
});

it('hands foreign entries back with lchown and never follows a planted symlink', function () {
    $secondary = psSecondaryGroup();
    if ($secondary === null) {
        $this->markTestSkipped('the test user has no secondary group to make an entry foreign');
    }
    $me = psMe();
    $app = psApp();
    $outside = $app.'/outside.txt';
    file_put_contents($outside, 'host file');
    chgrp($outside, $secondary);
    chgrp($app.'/storage/framework/views/compiled.php', $secondary);
    chgrp($app.'/storage/logs', $secondary);
    symlink('../../outside.txt', $app.'/storage/logs/planted');
    // The symlink itself is foreign too (lchown sets its group without following).
    exec('chgrp -h '.escapeshellarg($secondary).' '.escapeshellarg($app.'/storage/logs/planted'));

    [$exit, $out, $err] = psRun($app, 'dms_privsep_repair_runtime_ownership '.escapeshellarg($me['user']).' '.escapeshellarg($me['group']).' storage bootstrap/cache');

    expect($exit)->toBe(0, $err)
        ->and($out)->toContain('DEPLOY_PRIVSEP_RUNTIME_FOREIGN_BEFORE_REPAIR=3')
        ->and($out)->toContain('DEPLOY_PRIVSEP_RUNTIME_REPAIRED=3')
        ->and(psGroupOf($app.'/storage/framework/views/compiled.php'))->toBe($me['group'])
        ->and(psGroupOf($app.'/storage/logs'))->toBe($me['group'])
        // The file the symlink points at keeps its group: the link was never followed.
        ->and(psGroupOf($outside))->toBe($secondary);

    [$exit, $out] = psRun($app, 'dms_privsep_foreign_count '.escapeshellarg($me['user']).' '.escapeshellarg($me['group']).' storage bootstrap/cache');
    expect(trim($out))->toBe('0');
});

it('refuses to hand a hardlinked file or a FIFO to the runtime user and changes nothing', function (string $kind) {
    $app = psApp();
    if ($kind === 'hardlink') {
        link($app.'/storage/app/private/document.pdf', $app.'/storage/logs/linked.pdf');
    } else {
        posix_mkfifo($app.'/storage/logs/pipe', 0600);
    }
    $before = psGroupOf($app.'/storage/logs');

    // Every entry is foreign to an account the test user is not.
    [$exit, $out, $err] = psRun($app, 'dms_privsep_repair_runtime_ownership nobody nogroup storage bootstrap/cache');

    expect($exit)->toBe(1)
        ->and($err)->toContain('hardlinked files, device nodes, FIFOs or sockets')
        ->and($out)->not->toContain('DEPLOY_PRIVSEP_RUNTIME_REPAIRED')
        ->and(psGroupOf($app.'/storage/logs'))->toBe($before);
})->with(['hardlink', 'fifo']);

it('summarises foreign entries without ever printing a file name', function () {
    $app = psApp();
    mkdir($app.'/storage/app/private/case-0001', 0700);
    file_put_contents($app.'/storage/app/private/case-0001/synthetic-patient-name.pdf', 'synthetic');

    [$exit, $out] = psRun($app, 'dms_privsep_foreign_summary nobody nogroup storage bootstrap/cache');

    expect($exit)->toBe(0)
        ->and($out)->toContain('storage/app/private')
        ->and($out)->not->toContain('synthetic-patient-name')
        ->and($out)->not->toContain('case-0001')
        ->and($out)->not->toContain('document.pdf');
});

it('normalizes modes as the runtime user without widening a private tree or following a symlink', function () {
    $me = psMe();
    $app = psApp();
    $outside = $app.'/outside.txt';
    file_put_contents($outside, 'host file');
    chmod($outside, 0600);
    symlink('../../outside.txt', $app.'/storage/logs/planted');
    chmod($app.'/storage/app/private/document.pdf', 0644);
    chmod($app.'/storage/app/backups/deploy/dump.sql', 0644);
    chmod($app.'/storage/framework/views/compiled.php', 0600);
    chmod($app.'/bootstrap/cache/config.php', 0664);

    [$exit, , $err] = psRun($app, 'dms_privsep_as_runtime_fn '.escapeshellarg($me['user'])." '' dms_privsep_normalize_modes ".psPrivatePaths());

    expect($exit)->toBe(0, $err)
        ->and(psMode($app.'/storage/framework/views/compiled.php'))->toBe('0664')
        ->and(psMode($app.'/storage/framework/views'))->toBe('2775')
        ->and(psMode($app.'/storage/app/private/document.pdf'))->toBe('0640')
        ->and(psMode($app.'/storage/app/backups/deploy/dump.sql'))->toBe('0640')
        ->and(psMode($app.'/storage/app/backups/deploy'))->toBe('2750')
        ->and(psMode($app.'/bootstrap/cache/config.php'))->toBe('0660')
        ->and(psMode($outside))->toBe('0600');
});

it('refuses to normalize through a symlinked private path or backup tree', function (string $victim) {
    $me = psMe();
    $app = psApp();
    rename($app.'/'.$victim, $app.'/'.$victim.'-real');
    symlink(basename($victim).'-real', $app.'/'.$victim);

    [$exit, $out, $err] = psRun($app, 'dms_privsep_as_runtime_fn '.escapeshellarg($me['user'])." '' dms_privsep_normalize_modes ".psPrivatePaths());

    // The runtime process reports through its pipe, which the caller copies to stdout.
    expect($exit)->toBe(1)->and($out.$err)->toContain("{$victim} is a symlink — NOT GO");
})->with(['storage/app/private', 'storage/app/backups']);

it('claims the source tree top-down and leaves runtime trees and environment files alone', function () {
    $me = psMe();
    $app = psApp();
    // The host's real drift: a web-server-owned, group-writable build directory.
    chmod($app.'/public/build', 0775);
    chmod($app.'/public/build/assets/app.js', 0664);
    chmod($app.'/storage/framework/views/compiled.php', 0664);

    [$exit, $out, $err] = psRun($app, 'dms_privsep_claim_source_tree '.escapeshellarg($me['uid']).' '.escapeshellarg($me['gid']));

    expect($exit)->toBe(0, $err)
        ->and($out)->toContain('DEPLOY_PRIVSEP_SOURCE_FOREIGN_BEFORE_CLAIM=2')
        ->and($out)->toContain('DEPLOY_PRIVSEP_SOURCE_CLAIMED=2')
        ->and(psMode($app.'/public/build'))->toBe('0755')
        ->and(psMode($app.'/public/build/assets/app.js'))->toBe('0644')
        // The runtime trees and the environment file are not the claim's to touch.
        ->and(psMode($app.'/storage/framework/views/compiled.php'))->toBe('0664')
        ->and(psMode($app.'/.env'))->toBe('0640');

    [$exit, $out] = psRun($app, 'dms_privsep_claim_source_tree '.escapeshellarg($me['uid']).' '.escapeshellarg($me['gid']));
    expect($exit)->toBe(0)->and($out)->toContain('DEPLOY_PRIVSEP_SOURCE_FOREIGN_BEFORE_CLAIM=0');
});

it('claims a foreign symlink in the source tree without touching what it points at', function () {
    $secondary = psSecondaryGroup();
    if ($secondary === null) {
        $this->markTestSkipped('the test user has no secondary group to make an entry foreign');
    }
    $me = psMe();
    $app = psApp();
    $outsideDir = tempArtifactDir('privsep-outside-', 0755);
    file_put_contents($outsideDir.'/target.txt', 'host file');
    chmod($outsideDir.'/target.txt', 0666);
    symlink($outsideDir.'/target.txt', $app.'/public/link');
    $secondaryGid = (string) posix_getgrnam($secondary)['gid'];

    // Claiming for the secondary group makes every source entry foreign,
    // the symlink included.
    [$exit, , $err] = psRun($app, 'dms_privsep_claim_source_tree '.escapeshellarg($me['uid']).' '.escapeshellarg($secondaryGid));

    clearstatcache();
    expect($exit)->toBe(0, $err)
        ->and((string) lstat($app.'/public/link')['gid'])->toBe($secondaryGid)
        ->and(psMode($outsideDir.'/target.txt'))->toBe('0666')
        ->and(psGroupOf($outsideDir.'/target.txt'))->toBe($me['group']);
});

it('ends NOT GO on drift and passes once the trees are clean', function () {
    $me = psMe();
    $app = psApp();
    chmod($app.'/app/Kernel.php', 0666);
    $args = escapeshellarg($me['uid']).' '.escapeshellarg($me['gid']).' '.escapeshellarg($me['user']).' '.escapeshellarg($me['group']);

    [$exit, $out, $err] = psRun($app, "dms_privsep_final_gate {$args}");
    expect($exit)->toBe(1)
        ->and($out)->toContain('DEPLOY_PRIVSEP_SOURCE_FOREIGN_ENTRIES=1')
        ->and($out)->not->toContain('DEPLOY_PRIVSEP=ENFORCED')
        ->and($err)->toContain('privilege drift after the run — NOT GO');

    chmod($app.'/app/Kernel.php', 0644);
    [$exit, $out] = psRun($app, "dms_privsep_final_gate {$args}");
    expect($exit)->toBe(0)
        ->and($out)->toContain('DEPLOY_PRIVSEP_RUNTIME_FOREIGN_ENTRIES=0')
        ->and($out)->toContain('DEPLOY_PRIVSEP_SOURCE_FOREIGN_ENTRIES=0')
        ->and($out)->toContain('DEPLOY_PRIVSEP=ENFORCED');
});

it('prepares a clean application end to end', function () {
    $me = psMe();
    $app = psApp();
    $sys = tempArtifactDir('privsep-sysctl-');
    file_put_contents($sys.'/protected_hardlinks', "1\n");
    file_put_contents($sys.'/protected_symlinks', "1\n");
    chmod($app.'/public/build', 0775);

    // dms_privsep_prepare reads the live sysctl; run its steps with the fixture.
    $snippet = implode("\n", [
        'dms_privsep_kernel_guards '.escapeshellarg($sys),
        'dms_privsep_runtime_roots '.escapeshellarg($me['uid']).' '.escapeshellarg($me['user']).' '.escapeshellarg($me['group']).' storage bootstrap/cache',
        'dms_privsep_repair_runtime_ownership '.escapeshellarg($me['user']).' '.escapeshellarg($me['group']).' storage bootstrap/cache',
        'dms_privsep_claim_source_tree '.escapeshellarg($me['uid']).' '.escapeshellarg($me['gid']),
        'dms_privsep_final_gate '.escapeshellarg($me['uid']).' '.escapeshellarg($me['gid']).' '.escapeshellarg($me['user']).' '.escapeshellarg($me['group']),
    ]);
    [$exit, $out, $err] = psRun($app, $snippet);

    expect($exit)->toBe(0, $err)->and($out)->toContain('DEPLOY_PRIVSEP=ENFORCED');
    // The library's own prepare runs exactly these steps, in this order.
    preg_match('/^dms_privsep_prepare\(\) \{\n.*?^\}$/ms', psRead('scripts/lib/runtime-privsep.sh'), $m);
    $body = $m[0] ?? '';
    expect(strpos($body, 'dms_privsep_kernel_guards'))->toBeLessThan(strpos($body, 'dms_privsep_runtime_roots'))
        ->and(strpos($body, 'dms_privsep_runtime_roots'))->toBeLessThan(strpos($body, 'dms_privsep_repair_runtime_ownership'))
        ->and(strpos($body, 'dms_privsep_repair_runtime_ownership'))->toBeLessThan(strpos($body, 'dms_privsep_claim_source_tree'));
});

// ── 3. The read-only drift check between deploys ────────────────────────────

it('reports privilege drift read-only between deploys', function () {
    $me = psMe();
    $app = psApp();
    mkdir($app.'/deploy', 0755);
    file_put_contents($app.'/deploy/runtime-identity.conf', "DMS_RUNTIME_USER={$me['user']}\nDMS_RUNTIME_GROUP={$me['group']}\n");
    $sys = tempArtifactDir('privsep-sysctl-');
    file_put_contents($sys.'/protected_hardlinks', "1\n");
    file_put_contents($sys.'/protected_symlinks', "1\n");
    $run = function () use ($app, $me, $sys): array {
        $cmd = ['bash', base_path('scripts/privsep-drift-check.sh'), '--app-dir', $app,
            '--trusted-uid', $me['uid'], '--trusted-gid', $me['gid'], '--sysctl-dir', $sys];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), (string) $out];
    };

    [$exit, $out] = $run();
    expect($exit)->toBe(0, $out)->and($out)->toContain('PRIVSEP_DRIFT=NONE');

    chmod($app.'/public/build', 0775);
    $before = psMode($app.'/public/build');
    [$exit, $out] = $run();
    expect($exit)->toBe(1)
        ->and($out)->toContain('PRIVSEP_SOURCE_FOREIGN_ENTRIES=1')
        ->and($out)->toContain('PRIVSEP_DRIFT=FOUND')
        // Read-only: the drift is reported, not repaired.
        ->and(psMode($app.'/public/build'))->toBe($before);
});

it('keeps the drift check free of every mutation and of PHP', function () {
    $code = psCode('scripts/privsep-drift-check.sh');

    expect($code)->not->toMatch('/\b(chmod|chown|chgrp|mkdir|rm|mv|cp|ln|touch|install|tee|truncate)\s/')
        ->and($code)->not->toMatch('/\bphp\b/')
        ->and($code)->not->toContain('dms_privsep_repair_runtime_ownership')
        ->and($code)->not->toContain('dms_privsep_claim_source_tree')
        ->and($code)->not->toContain('dms_privsep_as_runtime')
        ->and((new DeploymentRollbackScanner)->privilegeSeparationIssues($code))
        ->each->not->toContain('line ');
});

// ── 4. Fixes from the independent review (sprint doc §8) ────────────────────

it('gives the runtime user /dev/null and a pipe, never one of the caller\'s descriptors', function () {
    $app = psApp();
    $me = escapeshellarg(psMe()['user']);

    // fd 5 stands for the root-only deploy log the runner holds open.
    [$exit, $out, $err] = psRun($app, 'exec 5>>"$PWD/root-only.log"'."\n"
        ."dms_privsep_as_runtime {$me} bash -c 'for f in /proc/\$\$/fd/*; do printf \"%s=%s\\n\" \"\${f##*/}\" \"\$(readlink \"\$f\")\"; done'");

    expect($exit)->toBe(0, $err)
        ->and($out)->toContain('0=/dev/null')
        ->and($out)->toMatch('/^1=pipe:/m')
        ->and($out)->toMatch('/^2=pipe:/m')
        ->and($out)->not->toMatch('/^5=/m')
        ->and($out)->not->toContain('root-only.log');
});

it('returns the runtime command\'s own exit status through the pipe', function (string $pipefail) {
    $app = psApp();
    $me = escapeshellarg(psMe()['user']);

    [$exit, $out] = psRun($app, "{$pipefail}\nset +e\ndms_privsep_as_runtime {$me} bash -c 'echo out; exit 7'\necho \"rc=\$?\"");

    expect($exit)->toBe(0)->and($out)->toContain("out\nrc=7");
})->with(['with pipefail' => ['set -o pipefail'], 'without pipefail' => ['set +o pipefail']]);

it('fails closed when a repair or a claim leaves an entry behind', function () {
    $app = psApp();

    // An unprivileged test user cannot hand files to nobody, nor claim them for
    // uid 0: the recount must turn the failed chown into NOT GO, not success.
    [$exit, $out, $err] = psRun($app, 'dms_privsep_repair_runtime_ownership nobody nogroup storage bootstrap/cache');
    expect($exit)->toBe(1)
        ->and($err)->toContain('remain in the runtime trees after the repair — NOT GO')
        ->and($out)->not->toContain('DEPLOY_PRIVSEP_RUNTIME_REPAIRED');

    [$exit, $out, $err] = psRun($app, 'dms_privsep_claim_source_tree 0 0');
    expect($exit)->toBe(1)
        ->and($err)->toContain('still owned or writable by another identity after the claim — NOT GO')
        ->and($out)->not->toContain('DEPLOY_PRIVSEP_SOURCE_CLAIMED');
});

it('refuses to claim a hardlinked source file another identity can write', function () {
    $me = psMe();
    $app = psApp();
    chmod($app.'/public/build/assets/app.js', 0664);
    link($app.'/public/build/assets/app.js', $app.'/public/elsewhere.js');

    [$exit, , $err] = psRun($app, 'dms_privsep_claim_source_tree '.escapeshellarg($me['uid']).' '.escapeshellarg($me['gid']));

    expect($exit)->toBe(1)
        ->and($err)->toContain('refusing to claim them')
        ->and(psMode($app.'/public/build/assets/app.js'))->toBe('0664');
});

it('never lets root source an environment file another identity could have written', function (int $mode, bool $symlink, bool $trustedIsMe, int $expected) {
    $me = psMe();
    $app = psApp();
    chmod($app.'/.env', $mode);
    if ($symlink) {
        rename($app.'/.env', $app.'/real.env');
        symlink('real.env', $app.'/.env');
    }
    $trusted = $trustedIsMe ? $me['uid'] : '0';

    [$exit, $out, $err] = psRun($app, 'dms_privsep_assert_trusted_file '.escapeshellarg($trusted).' .env');

    expect($exit)->toBe($expected, $err);
    if ($expected === 0) {
        expect($out)->toContain('DEPLOY_PRIVSEP_TRUSTED_FILE=.env');
    } else {
        expect($err)->toContain('root will not source it');
    }
})->with([
    'root:group 0640' => [0640, false, true, 0],
    'owner-only 0600' => [0600, false, true, 0],
    'group-writable 0660' => [0660, false, true, 1],
    'world-writable 0646' => [0646, false, true, 1],
    'a symlink' => [0640, true, true, 1],
    'owned by another uid' => [0640, false, false, 1],
]);

it('checks the environment file before root sources it and repairs ownership right after the checkout', function (string $script, string $headMarker) {
    $code = psCode($script);
    $trusted = strpos($code, 'dms_privsep_assert_trusted_file 0 .env');
    $source = strpos($code, "set -a\nsource .env");
    $harden = strpos($code, 'harden-secret-permissions.sh" apply');
    $head = strpos($code, $headMarker);
    $repair = strpos($code, 'dms_privsep_repair_runtime_ownership "$RUNTIME_USER" "$RUNTIME_GROUP" storage bootstrap/cache', (int) $head);
    $discover = strpos($code, 'as_runtime php artisan package:discover --ansi');

    expect($trusted)->not->toBeFalse()
        ->and($source)->toBeGreaterThan((int) $trusted)
        ->and($head)->not->toBeFalse()
        ->and($repair)->toBeGreaterThan((int) $head)
        ->and($discover)->toBeGreaterThan((int) $repair);
    if ($script === 'scripts/deploy-vps.sh') {
        // Before the first hardening, which would repair a tampered mode silently.
        expect($harden)->toBeGreaterThan((int) $trusted);
    }
})->with([
    'deploy' => ['scripts/deploy-vps.sh', 'echo "HEAD verified at pinned target'],
    'rollback' => ['scripts/rollback-vps.sh', 'echo "HEAD verified at pinned rollback target'],
]);

it('lets the runtime user read the root-owned checkout\'s git metadata for release evidence', function (string $script) {
    $me = psMe();
    preg_match('/^as_runtime\(\) \{\n.*?^\}$/ms', psRead($script), $m);
    expect($m[0] ?? '')->toContain('GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="$APP_DIR"');

    $repo = tempArtifactDir('privsep-git-', 0755);
    $git = 'git -C '.escapeshellarg($repo).' -c user.name=t -c user.email=t@example.invalid -c commit.gpgsign=false';
    exec($git.' init -q && '.$git.' commit -q --allow-empty -m t');

    // git's own switch for "this repository belongs to someone else", with the
    // machine's system and global git configuration ignored: a CI runner often
    // trusts every directory there (safe.directory=*), which would hide the
    // refusal this test depends on.
    $isolated = "export GIT_TEST_ASSUME_DIFFERENT_OWNER=1 GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null\n";
    $snippet = implode("\n", [
        rtrim($isolated),
        'APP_DIR='.escapeshellarg($repo),
        'RUNTIME_USER='.escapeshellarg($me['user']),
        $m[0],
        'as_runtime git rev-parse --verify HEAD',
    ]);
    [$exit, $out, $err] = psRun($repo, $snippet);
    expect($exit)->toBe(0, $out.$err)->and(trim($out))->toMatch('/^[0-9a-f]{40}$/');

    [$exit, $out] = psRun($repo, $isolated.'git rev-parse --verify HEAD 2>&1 || true');
    expect($out)->toContain('dubious ownership');
})->with(['deploy' => ['scripts/deploy-vps.sh'], 'rollback' => ['scripts/rollback-vps.sh']]);

it('keeps the runtime-owned compiled views out of the asset build that root runs', function () {
    $tailwind = (string) preg_replace('#^\s*//.*$#m', '', psRead('tailwind.config.js'));

    expect($tailwind)->not->toMatch("/['\"]\\.?\\/?storage\\//")
        ->and($tailwind)->toContain("'./vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php'")
        ->and($tailwind)->toContain("'./vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/views/*.blade.php'")
        ->and($tailwind)->toContain("'./vendor/laravel/framework/src/Illuminate/Notifications/resources/views/*.blade.php'")
        ->and($tailwind)->toContain("'./resources/views/**/*.blade.php'");
});

it('keeps the provisioning script free of root PHP and of root path mutation of runtime trees', function () {
    $prov = psRead('scripts/provision-runtime-identity.sh');

    expect((new DeploymentRollbackScanner)->privilegeSeparationIssues($prov, false))->toBe([])
        ->and($prov)->toContain('. "${SCRIPT_DIR}/lib/runtime-privsep.sh"')
        ->and($prov)->toContain('run dms_privsep_repair_runtime_ownership "$RUNTIME_USER" "$RUNTIME_GROUP" "${RUNTIME_TREES[@]}"')
        ->and($prov)->toContain('run dms_privsep_as_runtime_fn "$RUNTIME_USER" "" dms_privsep_normalize_modes "${PRIVATE_PATHS[@]}"')
        ->and($prov)->toContain('run dms_privsep_repair_runtime_ownership "$PREV_USER" "$PREV_GROUP" "${RUNTIME_TREES[@]}"');
    // Ownership first, so the identity that owns storage/ switches maintenance mode.
    expect(strpos($prov, 'run dms_privsep_repair_runtime_ownership "$RUNTIME_USER"'))
        ->toBeLessThan(strpos($prov, 'dms_privsep_as_runtime "$RUNTIME_USER" php artisan down --retry=30 ) && MAINTENANCE_ON=1'));
});

it('walks the live runtime trees without failing on an entry that vanishes', function () {
    $code = psCode('scripts/lib/runtime-privsep.sh');

    preg_match_all('/\bfind -P "\$@"[^\n]*|\bfind storage[^\n]*/', $code, $walks);
    expect($walks[0])->not->toBeEmpty();
    foreach ($walks[0] as $walk) {
        expect($walk)->toContain('-ignore_readdir_race');
    }
    // The widening chmods one entry at a time: a vanished session file fails
    // only its own chmod, never the deploy.
    preg_match_all('/^\s*find storage bootstrap\/cache[^\n]*$/m', $code, $widenings);
    foreach ($widenings[0] as $line) {
        expect($line)->toEndWith('{} \;');
    }
});

it('refuses to source a drift-check library or identity file that another identity can write', function (string $victim) {
    $me = psMe();
    $tools = tempArtifactDir('privsep-tools-', 0755);
    mkdir($tools.'/scripts/lib', 0755, true);
    copy(base_path('scripts/privsep-drift-check.sh'), $tools.'/scripts/privsep-drift-check.sh');
    copy(base_path('scripts/lib/runtime-privsep.sh'), $tools.'/scripts/lib/runtime-privsep.sh');
    $app = psApp();
    mkdir($app.'/deploy', 0755);
    file_put_contents($app.'/deploy/runtime-identity.conf', "DMS_RUNTIME_USER={$me['user']}\nDMS_RUNTIME_GROUP={$me['group']}\n");
    chmod($victim === 'library' ? $tools.'/scripts/lib/runtime-privsep.sh' : $app.'/deploy/runtime-identity.conf', 0664);

    $proc = proc_open(['bash', $tools.'/scripts/privsep-drift-check.sh', '--app-dir', $app,
        '--trusted-uid', $me['uid'], '--trusted-gid', $me['gid']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($proc))->toBe(2)
        ->and($out)->toContain('refusing to source')
        ->and($out)->not->toContain('PRIVSEP_DRIFT=');
})->with(['library', 'identity file']);
