<?php

/**
 * SECURITY-F8-PRIVATE-STORAGE-PATHS-1 — every private storage path is declared,
 * and declared paths are never widened.
 *
 * The deploy and rollback normalize storage to 2775/0664 so the runtime can
 * work, and strip "other" only from DMS_PRIVATE_PATHS. Private disks added after
 * that list was written were missing from it, so every deploy left them
 * world-readable on the host. Three layers close it:
 *
 *  1. COVERAGE — every private local disk in config/filesystems.php, and every
 *     directory that holds dumps or clinical evidence manifests outside a disk,
 *     is declared. A new private disk fails this suite until it is listed.
 *  2. DEPLOY ORDER — the deploy reads the identity authority from the LIVE host
 *     copy before it fast-forwards, so it merges the target release's list after
 *     the checkout advances; otherwise the deploy shipping a new path would
 *     itself re-open it.
 *  3. BEHAVIOUR — the real normalization code from deploy-vps.sh and
 *     rollback-vps.sh runs against a fixture: private trees are never widened,
 *     never keep an "other" bit, and the runtime keeps owner access.
 */

use Illuminate\Support\Str;

function f8Read(string $relative): string
{
    $path = base_path($relative);

    return is_readable($path) ? (string) file_get_contents($path) : '';
}

/** @return list<string> */
function f8DeclaredPrivatePaths(): array
{
    preg_match('/^DMS_PRIVATE_PATHS="([^"]*)"/m', f8Read('deploy/runtime-identity.conf'), $m);

    return array_values(array_filter(preg_split('/\s+/', trim($m[1] ?? '')) ?: []));
}

function f8IsCovered(string $relative, array $declared): bool
{
    foreach ($declared as $path) {
        if ($relative === $path || str_starts_with($relative, rtrim($path, '/').'/')) {
            return true;
        }
    }

    return false;
}

/** Run bash with the private-paths library sourced. Returns [exit, stdout, stderr]. */
function f8Lib(string $snippet): array
{
    $script = 'set -euo pipefail; . '.escapeshellarg(base_path('scripts/lib/private-paths.sh')).'; '.$snippet;
    $proc = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), (string) $out, (string) $err];
}

/** Extract a top-level shell function definition from a script. */
function f8ShellFunction(string $script, string $name): string
{
    $found = preg_match('/^'.preg_quote($name, '/').'\(\) \{\n.*?^\}$/ms', $script, $m);
    expect($found)->toBe(1, "{$name}() must be defined at top level");

    return $m[0];
}

/**
 * Build a storage tree with deliberately awkward modes. Every file is owned by
 * the test user, so chown to the user's own name and primary group succeeds
 * without root, exactly as the deploy's chown does on the host.
 */
function f8StorageFixture(): string
{
    $app = tempArtifactDir('f8-private-paths-');
    $layout = [
        // relative path => [mode, is_dir]
        'storage' => [0775, true],
        'storage/framework' => [0775, true],
        'storage/framework/views' => [0700, true],
        'storage/framework/views/compiled.php' => [0600, false],
        'storage/app' => [0775, true],
        'storage/app/private' => [0700, true],
        'storage/app/private/document.pdf' => [0600, false],
        'storage/app/private/readonly.pdf' => [0400, false],
        'storage/app/clinical-evidence-private' => [0775, true],
        'storage/app/clinical-evidence-private/handwritings' => [0755, true],
        'storage/app/clinical-evidence-private/handwritings/page.png' => [0644, false],
        'storage/app/backups' => [0755, true],
        'storage/app/backups/deploy' => [0755, true],
        'storage/app/backups/deploy/dump.sql' => [0644, false],
        'storage/logs' => [0755, true],
        'storage/logs/laravel.log' => [0644, false],
        'bootstrap' => [0755, true],
        // SECURITY-F8B-CONFIG-CACHE-PRIVATE-1: the modes the host actually had —
        // the compiled configuration readable by every local account.
        'bootstrap/cache' => [02775, true],
        'bootstrap/cache/config.php' => [0664, false],
    ];

    foreach ($layout as $relative => [$mode, $isDir]) {
        $path = $app.'/'.$relative;
        if ($isDir) {
            @mkdir($path, 0700, true);
        } else {
            file_put_contents($path, 'synthetic');
        }
    }
    // Modes last, deepest first, so a read-only directory never blocks setup.
    foreach (array_reverse($layout, true) as $relative => [$mode]) {
        chmod($app.'/'.$relative, $mode);
    }

    return $app;
}

function f8Mode(string $path): string
{
    clearstatcache(true, $path);

    return substr(sprintf('%o', fileperms($path)), -4);
}

/** Run a shell body inside the fixture with the deploy's variables in scope. */
function f8RunNormalization(string $app, string $body, string $privatePaths): array
{
    $user = trim((string) shell_exec('id -un'));
    $group = trim((string) shell_exec('id -gn'));
    $script = implode("\n", [
        'set -euo pipefail',
        'APP_DIR='.escapeshellarg($app),
        'cd "$APP_DIR"',
        'RUNTIME_USER='.escapeshellarg($user),
        'RUNTIME_GROUP='.escapeshellarg($group),
        'DMS_PRIVATE_PATHS='.escapeshellarg($privatePaths),
        $body,
    ]);
    $proc = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), (string) $out];
}

function f8AssertNormalizedTree(string $app, array $declared): void
{
    // Non-private runtime paths are widened so the runtime group can work.
    expect(f8Mode($app.'/storage/framework/views/compiled.php'))->toBe('0664')
        ->and(f8Mode($app.'/storage/framework/views'))->toBe('2775');

    // SECURITY-F8B-CONFIG-CACHE-PRIVATE-1: the compiled configuration holds the
    // database password and the application key. It used to come out 0664 here
    // in a 2775 directory — readable by every local account.
    expect(f8Mode($app.'/bootstrap/cache/config.php'))->toBe('0660')
        ->and(f8Mode($app.'/bootstrap/cache'))->toBe('2770');

    // Private trees are NOT widened: an owner-only file stays owner-only. Under
    // the old widen-then-strip order it came out 0660.
    expect(f8Mode($app.'/storage/app/private/document.pdf'))->toBe('0600')
        ->and(f8Mode($app.'/storage/app/private'))->toBe('0700');

    // The runtime owner is still guaranteed read/write, as the widening used to.
    expect(f8Mode($app.'/storage/app/private/readonly.pdf'))->toBe('0600');

    // A world-readable clinical file and its directories lose every "other" bit.
    expect(f8Mode($app.'/storage/app/clinical-evidence-private/handwritings/page.png'))->toBe('0640')
        ->and(f8Mode($app.'/storage/app/clinical-evidence-private/handwritings'))->toBe('0750')
        ->and(f8Mode($app.'/storage/app/clinical-evidence-private'))->toBe('0770')
        ->and(f8Mode($app.'/storage/logs/laravel.log'))->toBe('0640');

    // The backup tree keeps its own stricter modes.
    expect(f8Mode($app.'/storage/app/backups/deploy/dump.sql'))->toBe('0640')
        ->and(f8Mode($app.'/storage/app/backups/deploy'))->toBe('2750');

    // Nothing under any declared path keeps an "other" permission bit.
    foreach ($declared as $relative) {
        $root = $app.'/'.$relative;
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ([$root => true] + iterator_to_array($iterator) as $path => $_) {
            clearstatcache(true, (string) $path);
            expect(fileperms((string) $path) & 0007)->toBe(0, "{$path} must carry no 'other' bit");
        }
    }
}

function f8FixturePrivatePaths(): string
{
    return 'storage/app/private storage/app/clinical-evidence-private storage/app/backups storage/logs bootstrap/cache';
}

// ── 1. Coverage ─────────────────────────────────────────────────────────────

it('declares every private local disk rooted under storage as a private path', function () {
    $declared = f8DeclaredPrivatePaths();
    expect($declared)->not->toBeEmpty();

    $privateDisks = [];
    foreach ((array) config('filesystems.disks') as $name => $disk) {
        $root = (string) ($disk['root'] ?? '');
        if (($disk['driver'] ?? null) !== 'local' || ! str_starts_with($root, storage_path())) {
            continue;
        }
        // Laravel treats an unset visibility as private.
        if (($disk['visibility'] ?? 'private') === 'public') {
            continue;
        }
        $privateDisks[$name] = 'storage/'.ltrim(Str::after($root, storage_path()), '/');
    }

    // The disks that held clinical evidence on the host when this was found.
    expect($privateDisks)->toHaveKeys(['local', 'clinical_evidence', 'legacy_odontogram_private', 'legacy_mass_upload_private', 'legacy_rme_private']);

    foreach ($privateDisks as $name => $relative) {
        expect(f8IsCovered($relative, $declared))->toBeTrue("disk '{$name}' ({$relative}) must be listed in DMS_PRIVATE_PATHS");
    }
});

it('declares the directories that hold dumps and clinical evidence manifests outside a disk', function () {
    $declared = f8DeclaredPrivatePaths();

    // The clinical-evidence migration writes its manifests here; keep the test
    // honest if the command ever moves them.
    expect(f8Read('app/Console/Commands/ClinicalEvidenceMigratePublicCommand.php'))
        ->toContain("storage_path('app/clinical-evidence-migration')");

    foreach ([
        'storage/app/clinical-evidence-migration',
        'storage/app/backups',
        'storage/app/deploy-backups',
        'storage/backups',
        'storage/logs',
    ] as $relative) {
        expect(f8IsCovered($relative, $declared))->toBeTrue("{$relative} must be listed in DMS_PRIVATE_PATHS");
    }

    // The public disk is the one storage tree that must stay out of the list.
    expect(f8IsCovered('storage/app/public', $declared))->toBeFalse();
});

// SECURITY-F8B-CONFIG-CACHE-PRIVATE-1: `config:cache` writes the resolved
// configuration — database password and application key included — under
// bootstrap/cache, so that directory is as secret as the environment file.
it('declares the compiled configuration cache as a private path', function () {
    $declared = f8DeclaredPrivatePaths();
    $cachedConfig = Str::after(app()->getCachedConfigPath(), base_path().'/');

    expect($declared)->toContain('bootstrap/cache')
        ->and($cachedConfig)->toBe('bootstrap/cache/config.php')
        ->and(f8IsCovered($cachedConfig, $declared))->toBeTrue("{$cachedConfig} must sit under a declared private path")
        // Only the cache directory: application source under bootstrap/ stays
        // deploy-owned and is never part of the private set.
        ->and(f8IsCovered('bootstrap/app.php', $declared))->toBeFalse()
        ->and(f8IsCovered('bootstrap/providers.php', $declared))->toBeFalse();
});

it('declares only valid and unique private paths', function () {
    $declared = f8DeclaredPrivatePaths();

    expect($declared)->toBe(array_values(array_unique($declared)));

    [$exit, $out] = f8Lib('dms_private_paths_union '.escapeshellarg(implode(' ', $declared)));
    expect($exit)->toBe(0)->and($out)->toBe(implode(' ', $declared));
});

// ── 2. The union helper ─────────────────────────────────────────────────────

it('merges private path lists in first-seen order and never drops an entry', function () {
    [$exit, $out] = f8Lib("dms_private_paths_union 'storage/app/private storage/logs' 'storage/logs storage/app/clinical-evidence-private'");

    expect($exit)->toBe(0)
        ->and($out)->toBe('storage/app/private storage/logs storage/app/clinical-evidence-private');

    // An empty target declaration keeps the live list intact.
    [$exit, $out] = f8Lib("dms_private_paths_union 'storage/app/private storage/logs' ''");
    expect($exit)->toBe(0)->and($out)->toBe('storage/app/private storage/logs');
});

it('fails closed on an invalid private path and prints nothing', function (string $invalid) {
    [$exit, $out, $err] = f8Lib("dms_private_paths_union 'storage/app/private' ".escapeshellarg($invalid));

    expect($exit)->toBe(1)
        ->and($out)->toBe('')
        ->and($err)->toContain('invalid private storage path');
})->with([
    'absolute' => ['/var/www/storage/app/private'],
    'parent traversal' => ['storage/../.env'],
    'outside storage' => ['app/private'],
    'storage root itself' => ['storage'],
    'empty segment' => ['storage//app'],
    'trailing slash' => ['storage/app/'],
    'dot segment' => ['storage/./app'],
    'glob' => ['storage/app/*'],
    'bootstrap root' => ['bootstrap'],
    'bootstrap source file' => ['bootstrap/app.php'],
    'below the configuration cache' => ['bootstrap/cache/sub'],
    'configuration cache with trailing slash' => ['bootstrap/cache/'],
    'configuration cache by traversal' => ['storage/../bootstrap/cache'],
]);

it('admits the compiled configuration cache by exact name', function () {
    [$exit, $out] = f8Lib("dms_private_paths_union 'storage/logs' 'bootstrap/cache'");

    expect($exit)->toBe(0)->and($out)->toBe('storage/logs bootstrap/cache');
});

it('never expands a glob while splitting a list', function () {
    $dir = tempArtifactDir('f8-glob-');
    mkdir($dir.'/storage');
    touch($dir.'/storage/a');

    [$exit] = f8Lib('cd '.escapeshellarg($dir)." && dms_private_paths_union 'storage/*'");

    // Rejected as a pattern, not silently turned into the matching file name.
    expect($exit)->toBe(1);
});

// ── 3. Deploy order ─────────────────────────────────────────────────────────

it('merges the target release private paths after the checkout advances and before normalizing', function () {
    $deploy = f8Read('scripts/deploy-vps.sh');

    expect($deploy)->toContain('source "${DEPLOY_TOOLS_DIR}/scripts/lib/private-paths.sh"')
        ->and($deploy)->toContain('git show "${TARGET_SHA}:deploy/runtime-identity.conf"')
        ->and($deploy)->toContain('dms_private_paths_union "${DMS_PRIVATE_PATHS:-}" "$TARGET_PRIVATE_PATHS"');

    $merge = strpos($deploy, 'git merge --ff-only "$TARGET_SHA"');
    $union = strpos($deploy, 'dms_private_paths_union "${DMS_PRIVATE_PATHS:-}"');
    $firstNormalize = strpos($deploy, "\nnormalize_runtime_ownership\n");

    expect($merge)->not->toBeFalse()
        ->and($union)->not->toBeFalse()
        ->and($firstNormalize)->not->toBeFalse()
        ->and($union)->toBeGreaterThan($merge)
        ->and($union)->toBeLessThan($firstNormalize);
});

it('adopts the private paths a release adds and never drops a live one', function () {
    $deploy = f8Read('scripts/deploy-vps.sh');
    $start = strpos($deploy, 'TARGET_PRIVATE_PATHS="$(');
    $end = strpos($deploy, 'echo "Private storage paths (live + target)', (int) $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    $block = substr($deploy, (int) $start, (int) $end - (int) $start);

    // A throwaway repository whose pinned commit declares a path the live host
    // copy does not know yet — the exact shape of the deploy that ships it.
    $repo = tempArtifactDir('f8-target-');
    mkdir($repo.'/deploy');
    file_put_contents($repo.'/deploy/runtime-identity.conf', "DMS_RUNTIME_USER=runtime\nDMS_PRIVATE_PATHS=\"storage/app/private storage/app/clinical-evidence-private\"\n");
    $git = 'git -C '.escapeshellarg($repo).' -c user.name=t -c user.email=t@example.invalid -c commit.gpgsign=false';
    exec($git.' init -q && '.$git.' add deploy && '.$git.' commit -q -m t');
    $sha = trim((string) shell_exec('git -C '.escapeshellarg($repo).' rev-parse HEAD'));

    $run = function (string $livePaths) use ($block, $repo, $sha): array {
        $script = implode("\n", [
            'set -euo pipefail',
            '. '.escapeshellarg(base_path('scripts/lib/private-paths.sh')),
            'cd '.escapeshellarg($repo),
            'TARGET_SHA='.escapeshellarg($sha),
            'DMS_PRIVATE_PATHS='.escapeshellarg($livePaths),
            $block,
            'printf "%s" "$DMS_PRIVATE_PATHS"',
        ]);
        $proc = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), (string) $out, (string) $err];
    };

    // The release's new path is adopted in the same deploy.
    [$exit, $out] = $run('storage/app/private storage/logs');
    expect($exit)->toBe(0)
        ->and($out)->toBe('storage/app/private storage/logs storage/app/clinical-evidence-private');

    // An invalid live declaration fails the deploy closed instead of being dropped.
    [$exit, , $err] = $run('storage/../.env');
    expect($exit)->toBe(1)->and($err)->toContain('NOT GO');
});

it('protects the configuration cache in the same deploy that first declares it', function () {
    $deploy = f8Read('scripts/deploy-vps.sh');
    $start = strpos($deploy, 'TARGET_PRIVATE_PATHS="$(');
    $end = strpos($deploy, 'echo "Private storage paths (live + target)', (int) $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    $block = substr($deploy, (int) $start, (int) $end - (int) $start);

    // The live host copy predates bootstrap/cache; the release being installed
    // declares it. The deploy must adopt it before it normalizes storage.
    $repo = tempArtifactDir('f8b-target-');
    mkdir($repo.'/deploy');
    file_put_contents($repo.'/deploy/runtime-identity.conf', "DMS_RUNTIME_USER=runtime\nDMS_PRIVATE_PATHS=\"storage/app/private bootstrap/cache\"\n");
    $git = 'git -C '.escapeshellarg($repo).' -c user.name=t -c user.email=t@example.invalid -c commit.gpgsign=false';
    exec($git.' init -q && '.$git.' add deploy && '.$git.' commit -q -m t');
    $sha = trim((string) shell_exec('git -C '.escapeshellarg($repo).' rev-parse HEAD'));

    $script = implode("\n", [
        'set -euo pipefail',
        '. '.escapeshellarg(base_path('scripts/lib/private-paths.sh')),
        'cd '.escapeshellarg($repo),
        'TARGET_SHA='.escapeshellarg($sha),
        'DMS_PRIVATE_PATHS='.escapeshellarg('storage/app/private storage/logs'),
        $block,
        'printf "%s" "$DMS_PRIVATE_PATHS"',
    ]);
    $proc = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    expect($exit)->toBe(0, (string) $err)
        ->and($out)->toBe('storage/app/private storage/logs bootstrap/cache');
});

it('verifies the merged private path set on the host', function () {
    $deploy = f8Read('scripts/deploy-vps.sh');

    preg_match('/verify-runtime-isolation\.sh" \\\\\n(?:\s+--[^\n]*\n)+/', $deploy, $m);
    expect($m[0] ?? '')->toContain('--additional-private-paths "$DMS_PRIVATE_PATHS"');
});

it('prunes every declared private path from the storage widening in deploy and rollback', function (string $script) {
    $code = preg_replace('/^\s*#.*$/m', '', f8Read($script));

    preg_match_all('/^\s*find storage bootstrap\/cache[^\n]*chmod (2775|0664)[^\n]*$/m', $code, $widenings);

    expect($widenings[0])->toHaveCount(2);
    foreach ($widenings[0] as $line) {
        expect($line)->toContain('-path storage/app/backups -prune -o "${private_prune[@]}"');
    }
    expect($code)->toContain('read -r -a private_paths <<< "${DMS_PRIVATE_PATHS:-}"')
        ->and($code)->toContain('private_prune+=(-path "$rel" -prune -o)');
})->with([
    'deploy' => ['scripts/deploy-vps.sh'],
    'rollback' => ['scripts/rollback-vps.sh'],
]);

// ── 4. Behaviour of the real normalization code ─────────────────────────────

it('never widens a private tree when the deploy normalizes storage', function () {
    $deploy = f8Read('scripts/deploy-vps.sh');
    $functions = implode("\n", [
        f8ShellFunction($deploy, 'normalize_runtime_ownership'),
        f8ShellFunction($deploy, 'restrict_backup_tree'),
        f8ShellFunction($deploy, 'restrict_private_paths'),
    ]);

    $app = f8StorageFixture();
    [$exit, $out] = f8RunNormalization($app, $functions."\nnormalize_runtime_ownership", f8FixturePrivatePaths());

    expect($exit)->toBe(0, $out);
    f8AssertNormalizedTree($app, explode(' ', f8FixturePrivatePaths()));
});

it('never widens a private tree when a rollback normalizes storage', function () {
    $rollback = f8Read('scripts/rollback-vps.sh');
    $start = strpos($rollback, 'echo "== Permissions =="');
    $end = strpos($rollback, '# ─── INFRA-SEC-ENV-1', (int) $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    $app = f8StorageFixture();
    [$exit, $out] = f8RunNormalization($app, substr($rollback, (int) $start, (int) $end - (int) $start), f8FixturePrivatePaths());

    expect($exit)->toBe(0, $out);
    f8AssertNormalizedTree($app, explode(' ', f8FixturePrivatePaths()));
});
