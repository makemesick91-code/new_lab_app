<?php

use Symfony\Component\Process\Process;

/**
 * SECURITY-FIX-DEPLOY-BACKUP-FILE-PERMISSIONS-1 — database dumps are private
 * FROM THE INSTANT THEIR FILE EXISTS.
 *
 * The defect was `pg_dump ... > "$BACKUP"` followed by `chmod 0640 "$BACKUP"`:
 * the shell creates the redirect target under the caller's umask BEFORE pg_dump
 * runs, so the inode was 0644 for the entire dump. A test that only checks the
 * FINAL mode cannot see that — the chmod at the end makes it look correct.
 *
 * So the load-bearing assertions here observe the file WHILE IT IS BEING
 * WRITTEN: the stand-in dump command (`pdd_probe`) stats its own stdout through
 * /proc/$$/fd/1 before emitting a byte, and reports the mode on a side channel.
 * That is the mode any other account would have seen at that moment.
 *
 * Every fixture is synthetic ("FAKE DUMP"); no database is touched.
 */
function pddHelper(): string
{
    return base_path('scripts/lib/private-db-dump.sh');
}

/**
 * Run a bash snippet with the helper sourced, under the given umask.
 *
 * @return array{0:int,1:string,2:string} exit code, stdout, stderr
 */
function pddRun(string $dir, string $snippet, string $umask = '022'): array
{
    // pdd_probe: report the CREATION-TIME mode of its own stdout, then emit data.
    $probe = <<<'BASH'
pdd_probe() {
  stat -L -c '%a' "/proc/$BASHPID/fd/1" > "$PDD_SIDE"
  printf 'FAKE DUMP LINE\n%.0s' 1 2 3
}
pdd_fail()  { printf 'PARTIAL FAKE DUMP\n'; stat -L -c '%a' "/proc/$BASHPID/fd/1" > "$PDD_SIDE"; return 3; }
pdd_empty() { stat -L -c '%a' "/proc/$BASHPID/fd/1" > "$PDD_SIDE"; return 0; }
BASH;

    $script = "set -euo pipefail\numask {$umask}\nsource ".escapeshellarg(pddHelper())."\n{$probe}\n{$snippet}\n";

    $process = new Process(['bash', '-c', $script], $dir, ['PDD_SIDE' => $dir.'/side']);
    $process->run();

    return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
}

function pddMode(string $path): string
{
    clearstatcache(true, $path);

    return substr(sprintf('%o', fileperms($path)), -4);
}

function pddSide(string $dir): string
{
    return trim((string) @file_get_contents($dir.'/side'));
}

function pddFixture(): string
{
    $root = tempArtifactDir('pdd-', 0700);
    mkdir($root.'/backups', 0700);
    chmod($root.'/backups', 0700);

    return $root;
}

it('proves the probe is real: the OLD redirect-then-chmod pattern creates a world-readable inode', function () {
    $dir = pddFixture();

    [$code] = pddRun($dir, 'pdd_probe > backups/old.sql; chmod 0640 backups/old.sql', '022');

    expect($code)->toBe(0)
        // The window the defect left open: readable by "other" while written.
        ->and(pddSide($dir))->toBe('644')
        // ...and invisible to a final-mode check, which is why one is not enough.
        ->and(pddMode($dir.'/backups/old.sql'))->toBe('0640');
});

it('creates the dump 0600 at the moment of creation under the deploy umask 022', function () {
    $dir = pddFixture();

    [$code, , $err] = pddRun($dir, 'dms_write_private_dump backups/new.sql pdd_probe', '022');

    expect($code)->toBe(0, $err)
        ->and(pddSide($dir))->toBe('600')
        // Published at its creation mode: no chmod ever touches it by path.
        ->and(pddMode($dir.'/backups/new.sql'))->toBe('0600')
        ->and(file_get_contents($dir.'/backups/new.sql'))->toBe(str_repeat("FAKE DUMP LINE\n", 3));
});

it('stays private at creation even under a hostile umask 000', function () {
    $dir = pddFixture();

    [$code, , $err] = pddRun($dir, 'dms_write_private_dump backups/new.sql pdd_probe', '000');

    expect($code)->toBe(0, $err)
        ->and(pddSide($dir))->toBe('600')
        ->and(pddMode($dir.'/backups/new.sql'))->toBe('0600');
});

it('cannot be widened through the environment the caller sources', function () {
    $dir = pddFixture();

    [$code, , $err] = pddRun(
        $dir,
        'export DMS_DUMP_FINAL_MODE=0644 DMS_DUMP_DIR_MODE=0777; '
        .'dms_prepare_private_backup_dir backups; dms_write_private_dump backups/new.sql pdd_probe',
        '022'
    );

    expect($code)->toBe(0, $err)
        ->and(pddMode($dir.'/backups/new.sql'))->toBe('0600')
        ->and(pddMode($dir.'/backups'))->toBe('2750');
});

it('never chmods the dump by path after writing it', function () {
    $code = preg_replace('/^\s*#.*$/m', '', (string) file_get_contents(pddHelper()));

    // A path-based chmod after the write is redirectable through a symlink the
    // runtime user swaps in (root writes into a runtime-owned directory).
    expect($code)->not->toMatch('/chmod[^\n]*\$(?:partial|dest)/');
});

it('publishes the final dump with no access for other and no group write', function () {
    $dir = pddFixture();

    pddRun($dir, 'dms_write_private_dump backups/new.sql pdd_probe', '000');

    $mode = octdec(pddMode($dir.'/backups/new.sql'));
    expect($mode & 0o007)->toBe(0)
        ->and($mode & 0o020)->toBe(0);
});

it('does not leak the restrictive umask into the calling script', function () {
    $dir = pddFixture();

    [$code, $out] = pddRun($dir, 'dms_write_private_dump backups/new.sql pdd_probe; umask', '022');

    expect($code)->toBe(0)->and(trim($out))->toBe('0022');
});

it('removes a partial dump and fails when the dump command fails', function () {
    $dir = pddFixture();

    [$code] = pddRun($dir, 'dms_write_private_dump backups/new.sql pdd_fail', '000');

    expect($code)->not->toBe(0)
        // The partial was private even while it existed...
        ->and(pddSide($dir))->toBe('600')
        // ...and nothing is left behind afterwards.
        ->and(file_exists($dir.'/backups/new.sql.partial'))->toBeFalse()
        ->and(file_exists($dir.'/backups/new.sql'))->toBeFalse();
});

it('refuses an empty dump instead of publishing it', function () {
    $dir = pddFixture();

    [$code] = pddRun($dir, 'dms_write_private_dump backups/new.sql pdd_empty', '022');

    expect($code)->not->toBe(0)
        ->and(file_exists($dir.'/backups/new.sql'))->toBeFalse()
        ->and(file_exists($dir.'/backups/new.sql.partial'))->toBeFalse();
});

it('refuses to write through a pre-planted symlink and leaves its target untouched', function () {
    $dir = pddFixture();
    file_put_contents($dir.'/victim', 'untouched');
    symlink($dir.'/victim', $dir.'/backups/new.sql.partial');

    [$code] = pddRun($dir, 'dms_write_private_dump backups/new.sql pdd_probe', '022');

    expect($code)->not->toBe(0)
        ->and(file_get_contents($dir.'/victim'))->toBe('untouched')
        ->and(file_exists($dir.'/backups/new.sql'))->toBeFalse();
});

it('removes a stale private partial left by an earlier crash and writes a fresh dump', function () {
    $dir = pddFixture();
    file_put_contents($dir.'/backups/new.sql.partial', 'STALE');
    chmod($dir.'/backups/new.sql.partial', 0600);

    [$code, , $err] = pddRun($dir, 'dms_write_private_dump backups/new.sql pdd_probe', '022');

    expect($code)->toBe(0, $err)
        ->and(file_get_contents($dir.'/backups/new.sql'))->not->toContain('STALE');
});

it('restricts a world-open backup directory to 2750', function () {
    $dir = pddFixture();
    mkdir($dir.'/open', 0777);
    chmod($dir.'/open', 0777);

    [$code, , $err] = pddRun($dir, 'dms_prepare_private_backup_dir open; dms_prepare_private_backup_dir fresh/nested', '022');

    expect($code)->toBe(0, $err)
        ->and(pddMode($dir.'/open'))->toBe('2750')
        ->and(pddMode($dir.'/fresh/nested'))->toBe('2750')
        // A parent created on the way is private too, never 0755.
        ->and(octdec(pddMode($dir.'/fresh')) & 0o007)->toBe(0);
});

it('refuses a backup directory that is a symlink', function () {
    $dir = pddFixture();
    mkdir($dir.'/real', 0700);
    symlink($dir.'/real', $dir.'/link');

    [$code] = pddRun($dir, 'dms_prepare_private_backup_dir link', '022');

    expect($code)->not->toBe(0);
});

it('refuses a directory carrying a default ACL, which would override the umask', function () {
    if (trim((string) shell_exec('command -v setfacl')) === '') {
        test()->markTestSkipped('setfacl is not installed; the ACL refusal path cannot be exercised here.');
    }
    $dir = pddFixture();
    $set = new Process(['setfacl', '-d', '-m', 'o::r', $dir.'/backups']);
    $set->run();
    if (! $set->isSuccessful()) {
        test()->markTestSkipped('This filesystem does not support default ACLs.');
    }

    [$code] = pddRun($dir, 'dms_write_private_dump backups/new.sql pdd_probe', '022');

    expect($code)->not->toBe(0)
        ->and(file_exists($dir.'/backups/new.sql'))->toBeFalse();
});

// ── Static contract: every dump writer goes through the helper ─────────────

dataset('pdd_dump_writers', [
    'deploy' => ['scripts/deploy-vps.sh'],
    'rollback' => ['scripts/rollback-vps.sh'],
    'scheduled backup' => ['scripts/backup-vps.sh'],
    'manual backup' => ['scripts/backup_postgres.sh'],
]);

it('routes every database dump through the private-creation helper', function (string $script) {
    $source = (string) file_get_contents(base_path($script));
    $code = preg_replace('/^\s*#.*$/m', '', $source);

    expect($code)->toContain('lib/private-db-dump.sh');

    // Every pg_dump invocation, with its backslash continuations, must be the
    // argument of the helper — never a bare `> file` redirect and never
    // `pg_dump -f file` (pg_dump then creates the file under the caller's umask).
    preg_match_all('/^[^\n]*\bpg_dump\b[^\n]*(?:\\\\\n[^\n]*)*/m', $code, $blocks);

    expect($blocks[0])->not->toBeEmpty();
    foreach ($blocks[0] as $block) {
        expect($block)->toContain('dms_write_private_dump')
            ->and($block)->not->toContain('>')
            ->and($block)->not->toMatch('/\s-f\s/');
    }
})->with('pdd_dump_writers');

it('keeps the backup tree out of the 0664/2775 storage widening in deploy and rollback', function (string $script) {
    $code = preg_replace('/^\s*#.*$/m', '', (string) file_get_contents(base_path($script)));

    preg_match_all('/^\s*find storage bootstrap\/cache[^\n]*chmod (2775|0664)[^\n]*$/m', $code, $widenings);

    expect($widenings[0])->not->toBeEmpty();
    foreach ($widenings[0] as $line) {
        expect($line)->toContain('-path storage/app/backups -prune');
    }
    expect($code)->toContain('chmod 2750')->and($code)->toContain('chmod 0640');
})->with([
    'deploy' => ['scripts/deploy-vps.sh'],
    'rollback' => ['scripts/rollback-vps.sh'],
]);
