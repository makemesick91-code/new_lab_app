<?php

use App\Services\Foundation\BackupVerificationService;
use Symfony\Component\Process\Process;

/*
 * R6-B7 — rollback reliability. The rollback's pre-rollback dump is written where the NSF-10 verifier accepts it, with
 * the SHIPPED configuration (never a test override of allowed_directories). A target that could not finish the
 * rollback after the code switch is refused before anything is backed up or checked out, and every stop after the
 * switch says how to recover. Selected by the `DeployHarden` token in BOTH critical variants (CG-19).
 */
uses()->group('Deploy', 'DeployHarden', 'R6B7');

function r6b7Script(string $name): string
{
    return (string) file_get_contents(base_path("scripts/{$name}"));
}

/** The literal BACKUP= template a producer script writes its dump to, with shell variables resolved for a test stamp. */
function r6b7BackupPath(string $script, string $stamp): string
{
    $code = r6b7Script($script);
    expect(preg_match('/^BACKUP="([^"]+)"$/m', $code, $m))->toBe(1, "{$script} has one literal BACKUP= line");
    $path = $m[1];
    if (str_contains($path, '${BACKUP_DIR}')) {
        expect(preg_match('/^BACKUP_DIR="([^"$]+)"$/m', $code, $d))->toBe(1, "{$script} declares BACKUP_DIR");
        $path = str_replace('${BACKUP_DIR}', $d[1], $path);
    }

    return str_replace('${STAMP}', $stamp, $path);
}

/** Writes a fixture at a base-relative path, runs the real verifier with the shipped config, removes what it created. */
function r6b7VerifyAt(string $relative): array
{
    $abs = base_path($relative);
    $dir = dirname($abs);
    $created = [];
    for ($d = $dir; ! is_dir($d); $d = dirname($d)) {
        $created[] = $d;
    }
    @mkdir($dir, 0750, true);
    file_put_contents($abs, "-- PostgreSQL database dump\n".str_repeat("-- synthetic R6-B7 fixture line\n", 80));
    chmod($abs, 0640);
    try {
        return app(BackupVerificationService::class)->verify($relative);
    } finally {
        @unlink($abs);
        foreach ($created as $d) {
            @rmdir($d);
        }
    }
}

function r6b7Failed(array $report): array
{
    return collect($report['checks'] ?? [])->where('status', 'failed')->pluck('check_id')->values()->all();
}

/** The rollback's pre-switch target check, exactly as shipped (from the command list to its last `fi`). */
function r6b7TargetCheck(): string
{
    $code = r6b7Script('rollback-vps.sh');
    $start = strpos($code, 'ROLLBACK_POST_SWITCH_COMMANDS="');
    $missing = strpos($code, 'if [ -n "$TARGET_MISSING_COMMANDS" ]; then', (int) $start);
    expect($start)->toBeInt()->and($missing)->toBeInt();
    $end = strpos($code, "\nfi\n", (int) $missing) + 4;

    return substr($code, (int) $start, $end - (int) $start);
}

/** Runs the target check in a directory against a commit; returns exit code and combined output. */
function r6b7RunCheck(string $dir, string $sha): array
{
    $p = new Process(['bash', '-c', 'set -euo pipefail; ROLLBACK_TARGET_SHA="$1"; '.r6b7TargetCheck().' echo PASSED', 'check', $sha], $dir);
    $p->run();

    return ['exit' => $p->getExitCode(), 'out' => $p->getOutput().$p->getErrorOutput()];
}

/** The application commands the rollback runs after the checkout (Laravel's own commands have no signature in app/). */
function r6b7PostSwitchAppCommands(): array
{
    $code = r6b7Script('rollback-vps.sh');
    $after = substr($code, (int) strpos($code, 'git checkout "$ROLLBACK_TARGET_SHA"'));
    preg_match_all('/php artisan ([a-z0-9:_-]+)/', $after, $m);

    return collect($m[1])->unique()->filter(function (string $cmd) {
        $p = new Process(['git', 'grep', '-q', '-E', "signature = ['\"]{$cmd}([ '\"]|$)", 'HEAD', '--', 'app'], base_path());
        $p->run();

        return $p->getExitCode() === 0;
    })->sort()->values()->all();
}

it('verifies the rollback\'s own pre-rollback dump path with the shipped verifier configuration', function () {
    $relative = r6b7BackupPath('rollback-vps.sh', 'r6b7-'.bin2hex(random_bytes(6)));
    $report = r6b7VerifyAt($relative);

    expect($relative)->toStartWith('storage/app/backups/deploy/pre_rollback_')
        ->and(r6b7Failed($report))->toBe([])
        ->and($report['summary']['decision'])->toBe('GO');
});

it('accepts a dump only inside the shipped allowed directories (BACKUP-PATH-ALLOWED)', function () {
    $report = r6b7VerifyAt('storage/app/backups/other-'.bin2hex(random_bytes(4)).'/pre_rollback_r6b7.sql');

    expect(r6b7Failed($report))->toContain('BACKUP-PATH-ALLOWED')
        ->and($report['summary']['decision'])->toBe('FAIL');
});

it('keeps every producer\'s dump inside the shipped allowed directories, with the shipped extension', function (string $script) {
    $path = r6b7BackupPath($script, '20261125-000000');
    $allowed = (array) config('backup_governance.allowed_directories');
    $inside = collect($allowed)->contains(fn (string $dir) => str_starts_with($path, rtrim($dir, '/').'/'));

    expect($inside)->toBeTrue("{$script} writes {$path}, outside ".implode(', ', $allowed))
        ->and(pathinfo($path, PATHINFO_EXTENSION))->toBeIn((array) config('backup_governance.allowed_extensions'));
})->with(['rollback-vps.sh', 'deploy-vps.sh', 'backup-vps.sh']);

it('checks the target before the backup, backs up before the checkout, and verifies after it', function () {
    $code = r6b7Script('rollback-vps.sh');
    $check = strpos($code, 'ROLLBACK_POST_SWITCH_COMMANDS="');
    $backup = strpos($code, 'echo "== Backup DB before rollback =="');
    $dumpAt = strpos($code, 'BACKUP="storage/app/backups/deploy/pre_rollback_${STAMP}.sql"');
    $trap = strpos($code, 'trap dms_rollback_on_exit EXIT');
    $checkout = strpos($code, 'git checkout "$ROLLBACK_TARGET_SHA"');
    $verify = strpos($code, 'as_runtime php artisan foundation:backup-verify --path="$BACKUP"');
    $disarm = strpos($code, 'trap - EXIT TERM INT HUP');
    $ok = strpos($code, 'echo "ROLLBACK OK: rolled back to');

    expect($check)->toBeInt()->and($check)->toBeLessThan($backup)
        ->and($backup)->toBeLessThan($dumpAt)
        ->and($dumpAt)->toBeLessThan($trap)
        ->and($trap)->toBeLessThan($checkout)
        ->and($checkout)->toBeLessThan($verify)
        ->and($verify)->toBeLessThan($disarm)
        ->and($disarm)->toBeLessThan($ok)
        ->and($code)->toContain('dms_prepare_private_backup_dir storage/app/backups/deploy')
        ->and($code)->not->toContain('backups/rollback');
});

it('lists exactly the application commands the rollback runs after the checkout', function () {
    expect(preg_match('/^ROLLBACK_POST_SWITCH_COMMANDS="([^"]+)"$/m', r6b7Script('rollback-vps.sh'), $m))->toBe(1);
    $listed = collect(explode(' ', $m[1]))->sort()->values()->all();
    // Laravel's own commands, present at every target; anything else run after the switch must be listed, however it
    // is declared (a new command not matching the check's signature pattern fails here instead of being skipped).
    $builtins = ['about', 'config:cache', 'config:clear', 'event:cache', 'optimize:clear', 'package:discover', 'route:cache', 'route:clear', 'view:cache'];
    $code = r6b7Script('rollback-vps.sh');
    preg_match_all('/php artisan ([a-z0-9:_-]+)/', substr($code, (int) strpos($code, 'git checkout "$ROLLBACK_TARGET_SHA"')), $run);
    $ran = collect($run[1])->unique()->sort()->values()->all();

    expect($listed)->toBe(r6b7PostSwitchAppCommands())
        ->and(collect($listed)->merge($builtins)->unique()->sort()->values()->all())->toBe(collect($ran)->merge($builtins)->unique()->sort()->values()->all())
        ->and(array_values(array_intersect($listed, $builtins)))->toBe([])
        ->and(array_values(array_diff($ran, $listed, $builtins)))->toBe([]);
});

it('accepts the shipped tree as a rollback target (its verifier configuration and every post-switch command)', function () {
    $run = r6b7RunCheck(base_path(), 'HEAD');

    expect($run['exit'])->toBe(0, $run['out'])->and($run['out'])->toContain('PASSED');
});

it('refuses, before any backup or checkout, a target that could not finish the rollback', function () {
    $dir = sys_get_temp_dir().'/r6b7-'.bin2hex(random_bytes(6));
    mkdir("{$dir}/config", 0700, true);
    mkdir("{$dir}/app/Console/Commands", 0700, true);
    try {
        $git = fn (string $args) => (new Process(['bash', '-c', 'git -c user.name=synthetic -c user.email=synthetic@example.invalid '.$args], $dir))->mustRun()->getOutput();
        $commit = fn (string $msg) => $git('add -A && git -c user.name=synthetic -c user.email=synthetic@example.invalid commit -qm '.$msg);
        $config = fn (string $dirs, string $ext) => file_put_contents("{$dir}/config/backup_governance.php", "<?php return ['allowed_directories' => [{$dirs}], 'allowed_extensions' => [{$ext}]];\n");
        preg_match('/^ROLLBACK_POST_SWITCH_COMMANDS="([^"]+)"$/m', r6b7Script('rollback-vps.sh'), $m);
        $commands = explode(' ', $m[1]);
        foreach ($commands as $i => $cmd) {
            file_put_contents("{$dir}/app/Console/Commands/C{$i}.php", "<?php\nclass C{$i} { protected \$signature = '{$cmd}\n        {--json}'; }\n");
        }
        $git('init -q -b main .');
        $config("'storage/app/backups/deploy'", "'sql'");
        $commit('complete');
        $complete = trim($git('rev-parse HEAD'));
        $config("'storage/app/backups/elsewhere'", "'sql'");
        $commit('other-directory');
        $otherDir = trim($git('rev-parse HEAD'));
        $config("'storage/app/backups/deploy'", "'dump'");
        $commit('other-extension');
        $otherExt = trim($git('rev-parse HEAD'));
        $config("'storage/app/backups/deploy'", "'sql'");
        unlink("{$dir}/app/Console/Commands/C9.php");
        $commit('missing-command');
        $missingCmd = trim($git('rev-parse HEAD'));
        unlink("{$dir}/config/backup_governance.php");
        $commit('no-verifier');
        $none = trim($git('rev-parse HEAD'));

        $ok = r6b7RunCheck($dir, $complete);
        $refused = array_map(fn (string $sha) => r6b7RunCheck($dir, $sha), [$otherDir, $otherExt, $none]);
        $cmd = r6b7RunCheck($dir, $missingCmd);
    } finally {
        (new Process(['rm', '-rf', '--', $dir]))->run();
    }

    expect($ok['exit'])->toBe(0, $ok['out']);
    foreach ($refused as $r) {
        expect($r['exit'])->toBe(2, $r['out'])->and($r['out'])->toContain('nothing was backed up or checked out');
    }
    expect($cmd['exit'])->toBe(2, $cmd['out'])
        ->and($cmd['out'])->toContain('lacks commands this rollback runs after the switch: '.$commands[9]);
});

it('says how to recover on every stop after the switch begins, and nothing once the rollback completes', function (string $tail, bool $notice) {
    $code = r6b7Script('rollback-vps.sh');
    $start = strpos($code, 'dms_rollback_partial_notice() {');
    $end = strpos($code, "trap 'dms_rollback_on_signal 129' HUP", (int) $start) + strlen("trap 'dms_rollback_on_signal 129' HUP");
    $block = substr($code, (int) $start, $end - (int) $start);
    // Run outside the repository: a failed checkout there must not touch the working tree under test.
    $dir = sys_get_temp_dir().'/r6b7-notice-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $p = new Process(['bash', '-c', 'set -euo pipefail; ROLLBACK_TARGET_SHA=0123456789abcdef0123456789abcdef01234567; '.$block."\n".$tail], $dir);
    $p->run();
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect(substr_count($p->getErrorOutput(), 'ROLLBACK STATE: stopped after the code switch began'))->toBe($notice ? 1 : 0, $p->getErrorOutput());
})->with([
    'a failing step' => ['false', true],
    'a failed checkout' => ['git checkout r6b7-no-such-ref-'.bin2hex(random_bytes(4)), true],
    'an interruption of the shell' => ['kill -TERM $$; sleep 5', true],
    // The race of a signal to the whole process group: the running step and the shell are signalled together.
    'an interruption of the step and the shell together' => ['( sleep 0.5; kill -TERM $(pgrep -P $$ -x sleep) $$ ) & sleep 30', true],
    'a hang-up' => ['kill -HUP $$; sleep 5', true],
    'a completed rollback' => ['trap - EXIT TERM INT HUP; true', false],
]);

it('prunes stale backups oldest first, whatever their kind, and never below the floor (backup-vps.sh)', function () {
    $code = r6b7Script('backup-vps.sh');
    $start = strpos($code, 'TOTAL="$(find "$BACKUP_DIR"');
    $end = strpos($code, "\nfi\n", (int) $start) + 4;
    $prune = substr($code, (int) $start, $end - (int) $start);
    $dir = sys_get_temp_dir().'/r6b7-prune-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $now = time();
    $files = [
        'auto_backup_fresh.sql' => $now,
        'pre_auto_deploy_newer_stale.sql' => $now - 20 * 86400,
        'pre_rollback_older_stale.sql' => $now - 30 * 86400,
        'pre_rollback_oldest_stale.sql' => $now - 31 * 86400,
    ];
    foreach ($files as $name => $mtime) {
        file_put_contents("{$dir}/{$name}", "-- PostgreSQL database dump\n");
        touch("{$dir}/{$name}", $mtime);
    }
    try {
        (new Process(['bash', '-c', 'set -euo pipefail; BACKUP_DIR="$1"; BACKUP_DR_RETENTION_DAYS=14; MIN_RETAINED=3; '.$prune, 'prune', $dir]))->mustRun();
        $kept = collect(scandir($dir))->reject(fn ($f) => $f[0] === '.')->sort()->values()->all();
    } finally {
        (new Process(['rm', '-rf', '--', $dir]))->run();
    }

    // Four files, floor three: exactly the oldest stale one goes, whatever its name.
    expect($kept)->toBe(['auto_backup_fresh.sql', 'pre_auto_deploy_newer_stale.sql', 'pre_rollback_older_stale.sql']);
});

it('never runs a migration or a destructive database command, and never restores data (ENT11-DR003)', function () {
    // Comments and the operator-facing echo lines (which name the separate, owner-approved restore) are not commands.
    $code = preg_replace('/^\s*(#|echo ).*$/m', '', r6b7Script('rollback-vps.sh'));

    expect($code)->not->toMatch('/artisan\s+migrate|migrate:(fresh|reset|rollback)|db:wipe|schema:drop|DROP\s+(DATABASE|SCHEMA)|TRUNCATE|pg_restore|restore_postgres\.sh/i');
});
