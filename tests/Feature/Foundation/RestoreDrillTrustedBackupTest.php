<?php

/*
| DMS-SPN4-READY-PILOT-1 G6 / R6-B6 — finding A-1 (2026-11-25): the restore drill
| replays a backup as the PostgreSQL superuser, and the backup is a plain SQL file
| the runtime user owns and can write. The drill therefore takes rule 235's path:
|
| - an expected SHA-256 from trusted evidence (the deploy's root-only log, or an
|   independent record the administrator passes), never a default "latest" file;
| - source checks: a regular file, not a symlink, owned by the runtime or the
|   operator, not group- or other-writable;
| - ONE root-only staged copy, read once by the file's owner and hashed by root;
|   the owner-role preflight, the runtime ownership gate and the replay read only
|   that copy, so replacing the file after the check changes nothing;
| - the replay runs in psql's restricted mode under a fresh random key, so no
|   backslash meta-command in a dump runs as the superuser's OS account.
|
| Exercised for real against stub binaries (sudo, psql, createdb, dropdb, php) that
| record their arguments; the psql stub keeps the replayed stream. No database, no
| network, no privileges. The same cases run on a private PostgreSQL 16 cluster in
| the owner's evidence pack. Selected by the `RestoreDrill` critical-gate token in
| both runner variants and declared in critical_gate_mandatory_suites (CG-19).
| Helper prefix `rdtb`.
*/

use Symfony\Component\Process\Process;

const RDTB_DEPLOY_ID = '20261125-100000';
const RDTB_BACKUP = 'storage/app/backups/deploy/pre_auto_deploy_20261125-100000.sql';
const RDTB_DUMP = <<<'SQL'
--
-- PostgreSQL database dump
--

\restrict AbC123synthetic

-- Dumped from database version 16.15
SET statement_timeout = 0;
CREATE TABLE public.t (id integer);
ALTER TABLE public.t OWNER TO app_runtime;
COPY public.t (id) FROM stdin;
1
\.

--
-- PostgreSQL database dump complete
--

\unrestrict AbC123synthetic

SQL;

/**
 * Runs scripts/rollout-restore-drill.sh in a scratch APP_DIR with stub binaries.
 *
 * $args may hold '{sha}' (the SHA-256 of the dump as first written) and '{file}'
 * (the backup's path). $prepare($root, $app, $backup) changes the scene before the
 * run. Scenario 'swap': the runtime-side backup check replaces the backup file
 * (after the drill staged and verified it) with $swapTo.
 *
 * @return array{exit: int, output: string, log: list<string>, evidence: ?array, replayed: ?string, root: string}
 */
function rdtbRun(array $args, ?string $dump = null, ?callable $prepare = null, string $scenario = 'none', string $swapTo = ''): array
{
    $root = sys_get_temp_dir().'/rdtb-'.bin2hex(random_bytes(6));
    $app = $root.'/app';
    $bin = $root.'/bin';
    mkdir($app.'/storage/app/backups/deploy', 0700, true);
    mkdir($bin, 0700, true);
    file_put_contents($app.'/.env', "DB_DATABASE=dmsapp\nDB_USERNAME=app_runtime\nDB_PASSWORD=not-a-real-secret\n");
    chmod($app.'/.env', 0600);
    $dump ??= RDTB_DUMP;
    $backup = $app.'/'.RDTB_BACKUP;
    file_put_contents($backup, $dump);
    chmod($backup, 0640);
    file_put_contents($root.'/swap.sql', $swapTo);
    if ($prepare !== null) {
        $prepare($root, $app, $backup);
    }

    $stubs = [
        'sudo' => <<<'SH'
            #!/usr/bin/env bash
            [ "$1" = "-n" ] && shift; [ "$1" = "-u" ] && { user="$2"; shift 2; }; [ "$1" = "--" ] && shift
            echo "AS[$user] $*" >> "$RDTB_LOG"
            exec "$@"
            SH,
        'psql' => <<<'SH'
            #!/usr/bin/env bash
            all="$*"
            case "$all" in
              *"usesuper FROM pg_user"*) echo t ;;
              *"rolcreatedb OR rolcreaterole OR rolsuper"*) echo f ;;
              *"SELECT oid FROM pg_database"*) if [ -f "$RDTB_STATE" ]; then cat "$RDTB_STATE"; fi ;;
              *"pg_roles WHERE rolname = 'dms_owner'"*) echo 0 ;;
              *"SELECT rolname FROM pg_catalog.pg_roles"*) printf 'postgres\npg_superuser\napp_runtime\npg_database_owner\n' ;;
              *"SELECT current_user"*) echo pg_superuser ;;
              *"datdba"*) echo pg_superuser ;;
              *"table_name='migrations'"*) echo 1 ;;
              *"information_schema.tables"*) echo 186 ;;
              *"-f -"*) echo "replay $all" >> "$RDTB_LOG"; cat > "$RDTB_REPLAYED" ;;
            esac
            SH,
        'createdb' => <<<'SH'
            #!/usr/bin/env bash
            echo "createdb $*" >> "$RDTB_LOG"
            echo 16384 > "$RDTB_STATE"
            SH,
        'dropdb' => <<<'SH'
            #!/usr/bin/env bash
            echo "dropdb $*" >> "$RDTB_LOG"
            rm -f "$RDTB_STATE"
            SH,
        'php' => <<<'SH'
            #!/usr/bin/env bash
            echo "php $*" >> "$RDTB_LOG"
            case "$*" in
              *"route:list"*) echo '[{"uri":"health/ready"}]' ;;
              *"foundation:backup-verify"*)
                # The runtime replaces its backup after the drill checked it (TOCTOU).
                if [ "$RDTB_SCENARIO" = swap ]; then cat "$RDTB_SWAP" > "$RDTB_BACKUP_ABS"; fi ;;
            esac
            exit 0
            SH,
    ];
    foreach ($stubs as $name => $body) {
        file_put_contents($bin.'/'.$name, $body);
        chmod($bin.'/'.$name, 0700);
    }

    $argv = array_map(fn (string $a): string => strtr($a, ['{sha}' => hash('sha256', $dump), '{file}' => RDTB_BACKUP]), $args);
    $process = new Process(array_merge(['bash', base_path('scripts/rollout-restore-drill.sh')], $argv), $app, [
        'PATH' => $bin.':'.getenv('PATH'),
        'APP_DIR' => $app,
        'RDTB_LOG' => $root.'/calls.log',
        'RDTB_STATE' => $root.'/drill-db.oid',
        'RDTB_REPLAYED' => $root.'/replayed.sql',
        'RDTB_SCENARIO' => $scenario,
        'RDTB_SWAP' => $root.'/swap.sql',
        'RDTB_BACKUP_ABS' => $backup,
        'ROLLOUT_DRILL_ENVIRONMENT' => 'staging',
        'PG_SUPERUSER_ACCOUNT' => 'postgres',
    ]);
    $process->setTimeout(60);
    $process->run();

    $evidence = $app.'/storage/app/readiness/restore-drills/latest.json';
    $log = $root.'/calls.log';
    $replayed = $root.'/replayed.sql';
    $result = [
        'exit' => (int) $process->getExitCode(),
        'output' => $process->getOutput().$process->getErrorOutput(),
        'log' => is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [],
        'evidence' => is_file($evidence) ? json_decode((string) file_get_contents($evidence), true) : null,
        'replayed' => is_file($replayed) ? (string) file_get_contents($replayed) : null,
        'root' => $root,
    ];
    (new Process(['rm', '-rf', '--', $root]))->run();

    return $result;
}

function rdtbCalls(array $run, string $binary): int
{
    return collect($run['log'])->filter(fn (string $l): bool => preg_match('/(^|\] )'.preg_quote($binary, '/').' /', $l) === 1)->count();
}

/** Calls one function of scripts/lib/restore-integrity.sh in bash. */
function rdtbLib(string $script, array $env = []): array
{
    $process = new Process(['bash', '-c', '. "$LIB_DIR/trusted-path.sh"; . "$LIB_DIR/restore-integrity.sh"; '.$script], null, array_merge([
        'LIB_DIR' => base_path('scripts/lib'),
        'PATH' => getenv('PATH'),
    ], $env));
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
}

/** Writes a trusted deploy evidence directory recording $sha for RDTB_BACKUP. */
function rdtbEvidence(string $root, string $app, string $sha, string $recorded = RDTB_BACKUP, string $mode = '0700'): void
{
    $dir = $root.'/evidence';
    mkdir($dir, 0700);
    file_put_contents($dir.'/deploy-'.RDTB_DEPLOY_ID.'.log', "== deploy\nPre-deploy backup: {$recorded} sha256={$sha}\nDEPLOY OK\n");
    file_put_contents($dir.'/deploy-'.RDTB_DEPLOY_ID.'.host', 'host='.gethostname()."\ndeploy_id=".RDTB_DEPLOY_ID."\n");
    chmod($dir.'/deploy-'.RDTB_DEPLOY_ID.'.log', 0600);
    chmod($dir.'/deploy-'.RDTB_DEPLOY_ID.'.host', 0600);
    chmod($dir, octdec($mode));
    mkdir($app.'/deploy', 0700);
    file_put_contents($app.'/deploy/production-host.conf', "DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR={$dir}\n");
}

it('refuses to run without trusted digest evidence, before anything is created', function (array $args, string $message) {
    $run = rdtbRun($args);

    expect($run['exit'])->toBe(2)
        ->and($run['output'])->toContain($message)
        ->and(rdtbCalls($run, 'createdb'))->toBe(0)
        ->and($run['replayed'])->toBeNull()
        ->and($run['evidence'])->toBeNull();
})->with([
    'no evidence, no file' => [[], 'needs trusted digest evidence'],
    'a file but no evidence' => [['{file}'], 'needs trusted digest evidence'],
    'both kinds of evidence' => [['--deploy-id', RDTB_DEPLOY_ID, '--expected-sha256', '{sha}', '{file}'], 'not both'],
    'a digest without its file' => [['--expected-sha256', '{sha}'], 'needs the backup file it describes'],
    'an unknown option' => [['--latest'], 'usage: bash scripts/rollout-restore-drill.sh'],
]);

it('refuses a backup changed after its digest was recorded: malicious SQL or a psql meta-command never reaches the superuser', function (string $appended) {
    $original = RDTB_DUMP;
    $run = rdtbRun(['--expected-sha256', hash('sha256', $original), '{file}'], prepare: function (string $root, string $app, string $backup) use ($appended) {
        file_put_contents($backup, $appended, FILE_APPEND);
    });

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('DIGEST MISMATCH')
        ->and($run['output'])->toContain('Nothing was created')
        ->and(rdtbCalls($run, 'createdb'))->toBe(0)
        ->and(rdtbCalls($run, 'replay'))->toBe(0)
        ->and($run['replayed'])->toBeNull()
        ->and($run['evidence'])->toBeNull();
})->with([
    'superuser SQL appended' => ["CREATE ROLE intruder SUPERUSER LOGIN;\n"],
    'psql meta-command appended' => ["\\! touch /tmp/rdtb-should-never-exist\n"],
    'meta-command inside a line' => ["SELECT 1; \\! id\n"],
    'early unrestrict, then a meta-command' => ["\\unrestrict AbC123synthetic\n\\! id\n"],
]);

it('refuses a symlinked, group-writable or other-writable backup before reading it', function (string $tamper) {
    $run = rdtbRun(['--expected-sha256', '{sha}', '{file}'], prepare: function (string $root, string $app, string $backup) use ($tamper) {
        if ($tamper === 'symlink') {
            rename($backup, $root.'/elsewhere.sql');
            symlink($root.'/elsewhere.sql', $backup);
        } else {
            chmod($backup, $tamper === 'group-writable' ? 0660 : 0646);
        }
    });

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('failed its source checks')
        ->and(rdtbCalls($run, 'createdb'))->toBe(0)
        ->and($run['replayed'])->toBeNull();
})->with(['symlink', 'group-writable', 'other-writable']);

it('refuses a backup owned by any identity other than the runtime or the operator', function () {
    $file = tempnam(sys_get_temp_dir(), 'rdtb-owner-');
    file_put_contents($file, RDTB_DUMP);
    chmod($file, 0640);
    $refused = rdtbLib('dms_restore_check_source "$F" someone_else daengtisiams', ['F' => $file]);
    $accepted = rdtbLib('dms_restore_check_source "$F" daengtisiams "$(id -un)"', ['F' => $file]);
    unlink($file);

    expect($refused['exit'])->toBe(1)
        ->and($refused['err'])->toContain('is owned by')
        ->and($accepted['exit'])->toBe(0);
});

it('refuses a digest-matching file that is not a plain pg_dump', function () {
    $run = rdtbRun(['--expected-sha256', '{sha}', '{file}'], "SELECT 1;\n");

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('not a plain-text pg_dump')
        ->and(rdtbCalls($run, 'createdb'))->toBe(0);
});

it('refuses an environment file another identity could have written, before sourcing it', function () {
    $run = rdtbRun(['--expected-sha256', '{sha}', '{file}'], prepare: function (string $root, string $app) {
        chmod($app.'/.env', 0666);
    });

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('refusing to source it')
        ->and(rdtbCalls($run, 'createdb'))->toBe(0);
});

it('replays the verified staged copy even when the backup is replaced after the check (TOCTOU)', function () {
    $malicious = RDTB_DUMP."ALTER TABLE public.t OWNER TO ghost_role;\nCREATE ROLE intruder SUPERUSER;\n\\! touch /tmp/rdtb-should-never-exist\n";
    $run = rdtbRun(['--expected-sha256', '{sha}', '{file}'], scenario: 'swap', swapTo: $malicious);

    expect($run['exit'])->toBe(0)
        ->and($run['evidence']['decision'] ?? null)->toBe('GO')
        ->and($run['evidence']['notes'] ?? [])->toContain('backup integrity: VERIFIED sha256='.hash('sha256', RDTB_DUMP))
        ->and($run['replayed'])->toContain('CREATE TABLE public.t (id integer);')
        ->and($run['replayed'])->not->toContain('ghost_role')
        ->and($run['replayed'])->not->toContain('intruder')
        ->and($run['replayed'])->not->toContain('\\!')
        ->and(collect($run['log'])->contains(fn (string $l): bool => str_contains($l, 'foundation:backup-verify')))->toBeTrue();
});

it('runs the whole replay in psql restricted mode under a fresh key and drops only the dump\'s own complete envelope', function () {
    $first = rdtbRun(['--expected-sha256', '{sha}', '{file}']);
    $second = rdtbRun(['--expected-sha256', '{sha}', '{file}']);
    $lines = explode("\n", (string) $first['replayed']);

    expect($first['exit'])->toBe(0)
        ->and($lines[0])->toMatch('/^\\\\restrict [0-9a-f]{64}$/')
        ->and(explode("\n", (string) $second['replayed'])[0])->not->toBe($lines[0])
        ->and($first['replayed'])->not->toContain('restrict AbC123synthetic')
        ->and($first['replayed'])->toContain("COPY public.t (id) FROM stdin;\n1\n\\.\n")
        ->and(substr_count((string) $first['replayed'], '\\restrict '))->toBe(1)
        ->and(substr_count((string) $first['replayed'], '\\unrestrict'))->toBe(0)
        ->and(collect($first['log'])->contains(fn (string $l): bool => str_starts_with($l, 'AS[postgres] psql -X -q -v ON_ERROR_STOP=1 -d dmsapp_restore_drill_') && str_ends_with($l, ' -f -')))->toBeTrue();
});

it('keeps an incomplete envelope so restricted mode refuses it, and wraps a dump that has none', function () {
    $appended = rdtbLib('dms_restore_restricted_stream "$F" Key1', ['F' => rdtbTemp(RDTB_DUMP."CREATE ROLE intruder SUPERUSER;\n")]);
    $plain = rdtbLib('dms_restore_restricted_stream "$F" Key1', ['F' => rdtbTemp("-- PostgreSQL database dump\nSELECT 1;\n")]);
    $badKey = rdtbLib('dms_restore_restricted_stream "$F" "bad key"', ['F' => rdtbTemp(RDTB_DUMP)]);

    expect($appended['exit'])->toBe(0)
        ->and(explode("\n", $appended['out'])[0])->toBe('\\restrict Key1')
        ->and($appended['out'])->toContain("\\restrict AbC123synthetic\n")
        ->and($appended['out'])->toContain("\\unrestrict AbC123synthetic\n")
        ->and($plain['out'])->toBe("\\restrict Key1\n-- PostgreSQL database dump\nSELECT 1;\n")
        ->and($badKey['exit'])->toBe(1);
});

it('takes the digest and the file from the deploy\'s trusted root-only log with --deploy-id', function () {
    $run = rdtbRun(['--deploy-id', RDTB_DEPLOY_ID], prepare: function (string $root, string $app) {
        rdtbEvidence($root, $app, hash('sha256', RDTB_DUMP));
    });

    expect($run['exit'])->toBe(0)
        ->and($run['output'])->toContain('RESTORE_INTEGRITY=VERIFIED sha256='.hash('sha256', RDTB_DUMP))
        ->and($run['output'])->toContain('the root-only log of deploy '.RDTB_DEPLOY_ID)
        ->and($run['evidence']['decision'] ?? null)->toBe('GO');
});

it('refuses --deploy-id evidence that disagrees, names another file, or is writable by others', function (string $case, string $message) {
    $run = rdtbRun($case === 'other file' ? ['--deploy-id', RDTB_DEPLOY_ID, 'storage/app/backups/deploy/other.sql'] : ['--deploy-id', RDTB_DEPLOY_ID],
        prepare: function (string $root, string $app) use ($case) {
            file_put_contents($app.'/storage/app/backups/deploy/other.sql', RDTB_DUMP);
            chmod($app.'/storage/app/backups/deploy/other.sql', 0640);
            rdtbEvidence($root, $app, $case === 'digest differs' ? str_repeat('a', 64) : hash('sha256', RDTB_DUMP), mode: $case === 'evidence writable' ? '0777' : '0700');
        });

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain($message)
        ->and(rdtbCalls($run, 'createdb'))->toBe(0)
        ->and($run['replayed'])->toBeNull();
})->with([
    'the recorded digest differs' => ['digest differs', 'DIGEST MISMATCH'],
    'another file than the deploy recorded' => ['other file', 'recorded '.RDTB_BACKUP],
    'an evidence directory others can write' => ['evidence writable', 'no trusted digest evidence'],
]);

it('reads only the staged copy after the integrity check, and never picks a default backup', function () {
    $code = implode("\n", array_filter(
        explode("\n", (string) file_get_contents(base_path('scripts/rollout-restore-drill.sh'))),
        fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
    ));
    $verify = strpos($code, 'dms_restore_verify_copy "$STAGED_BACKUP"');

    expect($verify)->not->toBeFalse()
        ->and(strpos($code, 'dms_restore_check_source "$BACKUP_FILE"'))->toBeLessThan($verify)
        ->and($verify)->toBeLessThan(strpos($code, 'dms_restore_missing_owner_roles "$STAGED_BACKUP"'))
        ->and($verify)->toBeLessThan(strpos($code, 'dms_restore_runtime_ownership_gate "$STAGED_BACKUP"'))
        ->and($verify)->toBeLessThan(strpos($code, 'as_superuser createdb -O'))
        ->and($code)->toContain('dms_restore_restricted_stream "$STAGED_BACKUP" "$RESTRICT_KEY" | as_superuser psql')
        ->and($code)->not->toContain('< "$BACKUP_FILE"')
        ->and($code)->not->toContain('ls -1t');
});

function rdtbTemp(string $content): string
{
    $file = tempnam(sys_get_temp_dir(), 'rdtb-stream-');
    file_put_contents($file, $content);
    register_shutdown_function(fn () => @unlink($file));

    return $file;
}
