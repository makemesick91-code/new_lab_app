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

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
 * (after the drill staged and verified it) with $swapTo. $prepare runs after the stubs are
 * written and also gets the stub directory, which is the pinned DMS_PG_BINDIR unless $env
 * overrides it. A directory $root/shadow, when present, is put first on PATH.
 *
 * @return array{exit: int, output: string, log: list<string>, evidence: ?array, replayed: ?string, root: string}
 */
function rdtbRun(array $args, ?string $dump = null, ?callable $prepare = null, string $scenario = 'none', string $swapTo = '', array $env = []): array
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
              --version) if [ -n "${RDTB_PSQL_VERSION_FAIL:-}" ]; then exit 1; fi; echo "${RDTB_PSQL_VERSION:-psql (PostgreSQL) 16.15 (stub)}" ;;
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
    if ($prepare !== null) {
        $prepare($root, $app, $backup, $bin);
    }

    // The drill's root-only staged copy goes under this private TMPDIR, so every run can prove it was removed (CG-11).
    mkdir($root.'/tmp', 0700);
    $argv = array_map(fn (string $a): string => strtr($a, ['{sha}' => hash('sha256', $dump), '{file}' => RDTB_BACKUP]), $args);
    $process = new Process(array_merge(['bash', base_path('scripts/rollout-restore-drill.sh')], $argv), $app, array_merge([
        'PATH' => (is_dir($root.'/shadow') ? $root.'/shadow:' : '').$bin.':'.getenv('PATH'),
        'TMPDIR' => $root.'/tmp',
        'DMS_PG_BINDIR' => $bin,
        'APP_DIR' => $app,
        'RDTB_LOG' => $root.'/calls.log',
        'RDTB_STATE' => $root.'/drill-db.oid',
        'RDTB_REPLAYED' => $root.'/replayed.sql',
        'RDTB_SCENARIO' => $scenario,
        'RDTB_SWAP' => $root.'/swap.sql',
        'RDTB_BACKUP_ABS' => $backup,
        'ROLLOUT_DRILL_ENVIRONMENT' => 'staging',
        'PG_SUPERUSER_ACCOUNT' => 'postgres',
    ], array_map(fn (string $v): string => strtr($v, ['{root}' => $root]), $env)));
    $process->setTimeout(60);
    $process->run();

    $evidence = $app.'/storage/app/readiness/restore-drills/latest.json';
    $log = $root.'/calls.log';
    $replayed = $root.'/replayed.sql';
    $result = [
        'exit' => (int) $process->getExitCode(),
        'output' => $process->getOutput().$process->getErrorOutput(),
        'log' => is_file($log) ? array_values(array_filter(explode("\n", str_replace($bin.'/', 'PGBIN/', (string) file_get_contents($log))))) : [],
        'evidence' => is_file($evidence) ? json_decode((string) file_get_contents($evidence), true) : null,
        'replayed' => is_file($replayed) ? (string) file_get_contents($replayed) : null,
        'root' => $root,
        'tmp_left' => count(array_diff((array) scandir($root.'/tmp'), ['.', '..'])),
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
    'an uppercase digest' => [['--expected-sha256', str_repeat('A', 64), '{file}'], 'must be 64 lowercase hex digits'],
    'a short digest' => [['--expected-sha256', 'abc123', '{file}'], 'must be 64 lowercase hex digits'],
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
        ->and($run['tmp_left'])->toBe(0)
        ->and(collect($run['log'])->contains(fn (string $l): bool => str_contains($l, 'foundation:backup-verify')))->toBeTrue();
});

it('runs the whole replay in psql restricted mode under a fresh key and drops only the dump\'s own complete envelope', function () {
    $first = rdtbRun(['--expected-sha256', '{sha}', '{file}']);
    $second = rdtbRun(['--expected-sha256', '{sha}', '{file}']);
    $lines = explode("\n", (string) $first['replayed']);

    expect($first['exit'])->toBe(0)
        ->and($first['tmp_left'])->toBe(0)
        ->and($lines[0])->toMatch('/^\\\\restrict [0-9a-f]{64}$/')
        ->and(explode("\n", (string) $second['replayed'])[0])->not->toBe($lines[0])
        ->and($first['replayed'])->not->toContain('restrict AbC123synthetic')
        ->and($first['replayed'])->toContain("COPY public.t (id) FROM stdin;\n1\n\\.\n")
        ->and(substr_count((string) $first['replayed'], '\\restrict '))->toBe(1)
        ->and(substr_count((string) $first['replayed'], '\\unrestrict'))->toBe(0)
        ->and(collect($first['log'])->contains(fn (string $l): bool => str_starts_with($l, 'AS[postgres] PGBIN/psql -X -q -v ON_ERROR_STOP=1 -d dmsapp_restore_drill_') && str_ends_with($l, ' -f -')))->toBeTrue();
});

it('keeps every meta-command inside a digest-matching dump under the fresh key, so real psql refuses it', function () {
    // The digest covers this dump, so only restricted mode stands between these lines and the superuser's OS
    // account. The drill must pass them on unchanged after its own fresh \restrict, and never add an \unrestrict.
    // The private PostgreSQL 16 harness proves that psql 16 then refuses them (owner evidence pack, cases C and C2).
    $dump = str_replace('COPY public.t (id) FROM stdin;', "\\unrestrict AbC123synthetic\n\\! id\nSELECT 1; \\! id\nCOPY public.t (id) FROM stdin;", RDTB_DUMP);
    $run = rdtbRun(['--expected-sha256', '{sha}', '{file}'], $dump);
    $lines = explode("\n", (string) $run['replayed']);

    expect($run['exit'])->toBe(0)
        ->and($lines[0])->toMatch('/^\\\\restrict [0-9a-f]{64}$/')
        ->and($run['replayed'])->toContain("\\unrestrict AbC123synthetic\n\\! id\nSELECT 1; \\! id\n")
        ->and(collect($lines)->filter(fn (string $l): bool => str_starts_with($l, '\\unrestrict '))->values()->all())->toBe(['\\unrestrict AbC123synthetic'])
        ->and(substr_count((string) $run['replayed'], '\\restrict '))->toBe(1);
});

it('defines the load guard first and calls it before every object header, every COPY line and at the end, under a fresh tag', function () {
    $dump = str_replace('\\unrestrict AbC123synthetic', "COPY public.t (id) FROM stdin;\n2\n\\.\n\n\\unrestrict AbC123synthetic", RDTB_DUMP);
    $first = rdtbRun(['--expected-sha256', '{sha}', '{file}'], $dump);
    $second = rdtbRun(['--expected-sha256', '{sha}', '{file}']);
    preg_match('/^CREATE FUNCTION pg_temp\.dms_restore_guard_(g[0-9a-f]{32})\(\) RETURNS void$/m', (string) $first['replayed'], $tag);
    preg_match('/^CREATE FUNCTION pg_temp\.dms_restore_guard_(g[0-9a-f]{32})\(\)/m', (string) $second['replayed'], $other);
    $lines = explode("\n", (string) $first['replayed']);
    $copies = array_keys(array_filter($lines, fn (string $l): bool => str_starts_with($l, 'COPY public.t (id) FROM stdin;')));

    expect($first['exit'])->toBe(0)
        ->and($tag[1] ?? null)->not->toBeNull()
        ->and($lines[1])->toBe('CREATE FUNCTION pg_temp.dms_restore_guard_'.$tag[1].'() RETURNS void')
        ->and($tag[1])->not->toBe($other[1] ?? null)
        ->and(count($copies))->toBe(2)
        ->and(collect($copies)->every(fn (int $i): bool => $lines[$i - 1] === 'SELECT pg_temp.dms_restore_guard_'.$tag[1].'();'))->toBeTrue()
        ->and(strpos((string) $first['replayed'], 'CREATE FUNCTION pg_temp'))->toBeLessThan(strpos((string) $first['replayed'], 'CREATE TABLE public.t'))
        ->and(collect($lines)->filter(fn (string $l): bool => $l !== '')->last())->toBe('SELECT pg_temp.dms_restore_guard_'.$tag[1].'();');
});

it('calls the load guard before every pg_dump object header, so a COPY line split by a newline in a table name is still guarded', function () {
    // pg_dump writes a one-line header before every object and every data block; a quoted table name with a newline
    // splits the COPY line itself (second independent review, H4), so the header is the anchor.
    $dump = str_replace(
        'CREATE TABLE public.t (id integer);',
        "--\n-- Name: t; Type: TABLE; Schema: public; Owner: app_runtime\n--\n\nCREATE TABLE public.t (id integer);",
        str_replace(
            'COPY public.t (id) FROM stdin;',
            "--\n-- Data for Name: \" x\"; Type: TABLE DATA; Schema: public; Owner: app_runtime\n--\n\nCOPY public.\"\nx\" (id) FROM stdin;\n1\n\\.\n\nCOPY public.t (id) FROM stdin;",
            RDTB_DUMP,
        ),
    );
    $run = rdtbRun(['--expected-sha256', '{sha}', '{file}'], $dump);
    $lines = explode("\n", (string) $run['replayed']);
    preg_match('/^CREATE FUNCTION pg_temp\.dms_restore_guard_(g[0-9a-f]{32})\(\)/m', (string) $run['replayed'], $tag);
    $call = 'SELECT pg_temp.dms_restore_guard_'.($tag[1] ?? 'none').'();';
    $headers = array_keys(array_filter($lines, fn (string $l): bool => preg_match('/^-- (Data for )?Name: /', $l) === 1));

    expect($run['exit'])->toBe(0)
        ->and(count($headers))->toBe(2)
        ->and(collect($headers)->every(fn (int $i): bool => $lines[$i - 1] === $call))->toBeTrue()
        ->and(array_search('COPY public."', $lines, true))->toBeGreaterThan($headers[1]);
});

it('refuses to load a writable or symlinked trusted-path library, before doing anything', function (string $tamper) {
    $root = sys_get_temp_dir().'/rdtb-tree-'.bin2hex(random_bytes(6));
    mkdir($root.'/scripts/lib', 0755, true);
    copy(base_path('scripts/rollout-restore-drill.sh'), $root.'/scripts/rollout-restore-drill.sh');
    copy(base_path('scripts/lib/restore-integrity.sh'), $root.'/scripts/lib/restore-integrity.sh');
    if ($tamper === 'symlink') {
        copy(base_path('scripts/lib/trusted-path.sh'), $root.'/elsewhere.sh');
        symlink($root.'/elsewhere.sh', $root.'/scripts/lib/trusted-path.sh');
    } else {
        copy(base_path('scripts/lib/trusted-path.sh'), $root.'/scripts/lib/trusted-path.sh');
        chmod($root.'/scripts/lib/trusted-path.sh', 0666);
    }
    $process = new Process(['bash', $root.'/scripts/rollout-restore-drill.sh', '--expected-sha256', str_repeat('a', 64), 'x.sql'], $root, [
        'APP_DIR' => $root.'/no-app',
        'PATH' => getenv('PATH'),
    ]);
    $process->run();
    (new Process(['rm', '-rf', '--', $root]))->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('trusted-path.sh is missing, a symlink, not owned by')
        ->and($process->getOutput())->not->toContain('== ROLL-5-1A restore drill ==');
})->with(['writable', 'symlink']);

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
        ->and($verify)->toBeLessThan(strpos($code, 'as_superuser "$CREATEDB_BIN" -O'))
        ->and($code)->toContain('dms_restore_check_source "$BACKUP_FILE" "$RUNTIME_USER" "$(id -un)"')
        ->and($code)->toContain('dms_restore_restricted_stream "$STAGED_BACKUP" "$RESTRICT_KEY" "$GUARD_TAG" | as_superuser "$PSQL_BIN"')
        ->and($code)->not->toContain('< "$BACKUP_FILE"')
        ->and($code)->not->toContain('ls -1t');
});

it('leaves no staged copy behind when the drill refuses a backup', function () {
    $run = rdtbRun(['--expected-sha256', str_repeat('b', 64), '{file}']);

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('DIGEST MISMATCH')
        ->and($run['tmp_left'])->toBe(0);
});

/**
 * The load guard on real PostgreSQL. RefreshDatabase wraps the test in a transaction and each case runs in a
 * savepoint, so the pg_temp function, the planted objects and the refusals are all rolled back.
 */
function rdtbGuard(): string
{
    $tag = 'g'.bin2hex(random_bytes(16));
    $sql = rdtbLib('dms_restore_load_guard_sql '.$tag);
    expect($sql['exit'])->toBe(0);
    DB::unprepared($sql['out']);

    return 'SELECT pg_temp.dms_restore_guard_'.$tag.'()';
}

it('lets the migrated application schema through the load guard, with no false refusal', function () {
    $call = rdtbGuard();

    expect(fn () => DB::select($call))->not->toThrow(Throwable::class);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'PostgreSQL catalog guard');

it('refuses, before any row is loaded, schema code the runtime could plant that COPY would run as the superuser', function (string $plant, string $reason) {
    $call = rdtbGuard();

    expect(fn () => DB::transaction(function () use ($plant, $call) {
        DB::unprepared($plant);
        DB::select($call);
    }))->toThrow(QueryException::class, $reason);
})->with([
    'CHECK calling a user function' => ['CREATE FUNCTION rdtb_planted() RETURNS boolean LANGUAGE plpgsql AS $f$ BEGIN RETURN true; END $f$; CREATE TABLE rdtb_t (id int CONSTRAINT rdtb_check CHECK (rdtb_planted()));', 'rdtb_planted()'],
    'generated column calling a user function' => ['CREATE FUNCTION rdtb_imm(int) RETURNS boolean LANGUAGE plpgsql IMMUTABLE AS $f$ BEGIN RETURN true; END $f$; CREATE TABLE rdtb_g (id int, g boolean GENERATED ALWAYS AS (rdtb_imm(id)) STORED);', 'rdtb_imm(integer)'],
    'domain CHECK calling a user function' => ['CREATE FUNCTION rdtb_dom(int) RETURNS boolean LANGUAGE sql AS $f$ SELECT true $f$; CREATE DOMAIN rdtb_d AS int CONSTRAINT rdtb_dcheck CHECK (rdtb_dom(VALUE));', 'rdtb_dom(integer)'],
    'CHECK calling a builtin that runs SQL text' => ["CREATE TABLE rdtb_q (id int CONSTRAINT rdtb_qcheck CHECK (query_to_xml('SELECT 1', false, false, '') IS NOT NULL));", 'query_to_xml(text,boolean,boolean,text)'],
    'a user-defined cast (JSON constructors and foreign-key checks use casts with no function id in the expression)' => ["CREATE TYPE rdtb_e AS ENUM ('a'); CREATE FUNCTION rdtb_cast(rdtb_e) RETURNS json LANGUAGE sql IMMUTABLE AS \$f\$ SELECT '1'::json \$f\$; CREATE CAST (rdtb_e AS json) WITH FUNCTION rdtb_cast(rdtb_e);", 'user-defined cast'],
    'a user-defined operator' => ['CREATE FUNCTION rdtb_eq(int, int) RETURNS boolean LANGUAGE sql IMMUTABLE AS $f$ SELECT $1 = $2 $f$; CREATE OPERATOR ==== (FUNCTION = rdtb_eq, LEFTARG = int, RIGHTARG = int);', 'user-defined operator'],
])->skip(fn () => DB::getDriverName() !== 'pgsql', 'PostgreSQL catalog guard');

function rdtbTemp(string $content): string
{
    $file = tempnam(sys_get_temp_dir(), 'rdtb-stream-');
    file_put_contents($file, $content);
    register_shutdown_function(fn () => @unlink($file));

    return $file;
}

it('refuses, before reading any backup, a psql that is not PostgreSQL 16.15 or later or cannot be verified', function (array $env, string $why) {
    $run = rdtbRun(['--expected-sha256', '{sha}', '{file}'], env: $env);

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('could not be verified as PostgreSQL 16.15 or later')
        ->and($run['output'])->toContain($why)
        ->and(rdtbCalls($run, 'createdb'))->toBe(0)
        ->and($run['replayed'])->toBeNull()
        ->and(collect($run['log'])->contains(fn (string $l): bool => str_contains($l, 'foundation:backup-verify')))->toBeFalse()
        ->and($run['tmp_left'])->toBe(0);
})->with([
    'an older 16 minor' => [['RDTB_PSQL_VERSION' => 'psql (PostgreSQL) 16.14 (Ubuntu 16.14-1)'], 'psql 16.14 is not allowed'],
    'before restricted mode' => [['RDTB_PSQL_VERSION' => 'psql (PostgreSQL) 16.9'], 'psql 16.9 is not allowed'],
    'another major' => [['RDTB_PSQL_VERSION' => 'psql (PostgreSQL) 17.2'], 'psql 17.2 is not allowed'],
    'an unreadable answer' => [['RDTB_PSQL_VERSION' => 'not psql at all'], 'cannot verify the psql version'],
    'a failing binary' => [['RDTB_PSQL_VERSION_FAIL' => '1'], 'cannot verify the psql version'],
]);

it('accepts psql 16.15 and later minors of PostgreSQL 16', function (string $version) {
    $run = rdtbRun(['--expected-sha256', '{sha}', '{file}'], env: ['RDTB_PSQL_VERSION' => $version]);

    expect($run['exit'])->toBe(0)
        ->and($run['output'])->toContain('PSQL_CLIENT=VERIFIED 16.')
        ->and($run['evidence']['decision'] ?? null)->toBe('GO');
})->with(['psql (PostgreSQL) 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)', 'psql (PostgreSQL) 16.16', 'psql (PostgreSQL) 16.20']);

it('never runs a psql, createdb or dropdb that PATH offers instead of the pinned, verified ones', function () {
    $run = rdtbRun(['--expected-sha256', '{sha}', '{file}'], prepare: function (string $root) {
        mkdir($root.'/shadow', 0700);
        foreach (['psql', 'createdb', 'dropdb'] as $tool) {
            file_put_contents($root.'/shadow/'.$tool, "#!/usr/bin/env bash\necho \"SHADOW {$tool} \$*\" >> \"\$RDTB_LOG\"\necho 'psql (PostgreSQL) 16.9'\n");
            chmod($root.'/shadow/'.$tool, 0700);
        }
    });

    expect($run['exit'])->toBe(0)
        ->and($run['evidence']['decision'] ?? null)->toBe('GO')
        ->and(collect($run['log'])->contains(fn (string $l): bool => str_starts_with($l, 'SHADOW ')))->toBeFalse()
        ->and(collect($run['log'])->contains(fn (string $l): bool => str_starts_with($l, 'AS[postgres] PGBIN/psql --version')))->toBeTrue();
});

it('refuses a pinned client directory or binary that another identity could have swapped', function (string $tamper) {
    $run = rdtbRun(['--expected-sha256', '{sha}', '{file}'], prepare: function (string $root, string $app, string $backup, string $bin) use ($tamper) {
        match ($tamper) {
            'group-writable directory' => chmod($bin, 0770),
            'other-writable psql' => chmod($bin.'/psql', 0702),
            'symlinked psql' => (function () use ($root, $bin) {
                rename($bin.'/psql', $root.'/real-psql');
                symlink($root.'/real-psql', $bin.'/psql');
            })(),
            'missing dropdb' => unlink($bin.'/dropdb'),
            'directory reached through a symlink' => symlink($bin, $root.'/bin-link'),
            'directory inside the checkout' => (function () use ($app, $bin) {
                mkdir($app.'/pgbin', 0700);
                foreach (['psql', 'createdb', 'dropdb'] as $tool) {
                    copy($bin.'/'.$tool, $app.'/pgbin/'.$tool);
                    chmod($app.'/pgbin/'.$tool, 0700);
                }
            })(),
        };
    }, env: match ($tamper) {
        'directory reached through a symlink' => ['DMS_PG_BINDIR' => '{root}/bin-link'],
        'directory inside the checkout' => ['DMS_PG_BINDIR' => '{root}/app/pgbin'],
        default => [],
    });

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('the PostgreSQL client directory failed its checks')
        ->and(rdtbCalls($run, 'createdb'))->toBe(0)
        ->and($run['replayed'])->toBeNull();
})->with(['group-writable directory', 'other-writable psql', 'symlinked psql', 'missing dropdb', 'directory reached through a symlink', 'directory inside the checkout']);

it('pins every PostgreSQL client call to the verified directory, checked before any backup is read', function () {
    $code = implode("\n", array_filter(
        explode("\n", (string) file_get_contents(base_path('scripts/rollout-restore-drill.sh'))),
        fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
    ));
    $version = strpos($code, 'dms_restore_psql_version_check "$PSQL_VERSION_LINE"');

    expect($code)->toContain('PG_BINDIR="${DMS_PG_BINDIR:-/usr/lib/postgresql/16/bin}"')
        ->and($code)->toContain('PSQL_VERSION_LINE="$(as_superuser "$PSQL_BIN" --version')
        ->and(preg_match('/as_superuser (psql|createdb|dropdb)\b/', $code))->toBe(0)
        ->and(preg_match('/^\s*(psql|createdb|dropdb) /m', $code))->toBe(0)
        ->and($version)->not->toBeFalse()
        ->and(strpos($code, 'dms_restore_pg_client_check "$PG_BINDIR"'))->toBeLessThan($version)
        ->and($version)->toBeLessThan(strpos($code, 'dms_restore_check_source "$BACKUP_FILE"'))
        ->and($version)->toBeLessThan(strpos($code, 'as_superuser "$PSQL_BIN" -X'))
        // The shared library runs `<prefix> psql`: the drill's prefix pins it too.
        ->and($code)->toContain('dms_restore_missing_owner_roles "$STAGED_BACKUP" as_superuser_pg)')
        ->and($code)->toContain('dms_restore_runtime_ownership_gate "$STAGED_BACKUP" "${DB_USERNAME}" as_superuser_pg)')
        ->and($code)->toContain('psql) as_superuser "$PSQL_BIN" "$@" ;;');
});

it('reads the psql version strictly', function (string $line, int $exit) {
    expect(rdtbLib('dms_restore_psql_version_check "$L"', ['L' => $line])['exit'])->toBe($exit);
})->with([
    ['psql (PostgreSQL) 16.15', 0],
    ['psql (PostgreSQL) 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)', 0],
    ['psql (PostgreSQL) 16.100', 0],
    ['psql (PostgreSQL) 16.9999', 0],
    ['psql (PostgreSQL) 16.99999999999999999999', 1],
    ['psql (PostgreSQL) 0016.15', 1],
    ['psql (PostgreSQL) 16.14', 1],
    ['psql (PostgreSQL) 16.1', 1],
    ['psql (PostgreSQL) 15.20', 1],
    ['psql (PostgreSQL) 17.6', 1],
    ['psql (PostgreSQL) 16.15.1', 1],
    ['psql (PostgreSQL) 16', 1],
    ['PSQL (PostgreSQL) 16.15', 1],
    ['', 1],
]);

it('refuses, before anything is created, a CHECK constraint that pg_dump split out to validate after the data', function (string $header) {
    // Third independent review of A-1: such an ALTER ... ADD CONSTRAINT validates every row in the superuser session
    // before any later guard call can see the constraint, so the dump is refused up front (proven on PostgreSQL 16).
    $dump = str_replace('\\unrestrict AbC123synthetic', "--\n{$header}\n--\n\nALTER TABLE ONLY public.t\n    ADD CONSTRAINT t_check CHECK (public.f(t.*));\n\n\\unrestrict AbC123synthetic", RDTB_DUMP);
    $run = rdtbRun(['--expected-sha256', '{sha}', '{file}'], $dump);

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('split out to validate after the data')
        ->and(rdtbCalls($run, 'createdb'))->toBe(0)
        ->and($run['replayed'])->toBeNull()
        ->and($run['tmp_left'])->toBe(0);
})->with([
    'a table CHECK' => ['-- Name: t t_check; Type: CHECK CONSTRAINT; Schema: public; Owner: app_runtime'],
    'a domain CHECK' => ['-- Name: d d_check; Type: CHECK CONSTRAINT; Schema: public; Owner: app_runtime'],
    'a planted name that adds its own Type text' => ['-- Name: t x; Type: TABLE; Schema: y; Type: CHECK CONSTRAINT; Schema: public; Owner: app_runtime'],
]);
