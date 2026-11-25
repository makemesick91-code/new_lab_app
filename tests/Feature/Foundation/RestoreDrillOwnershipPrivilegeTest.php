<?php

/*
| DMS-SPN4-READY-PILOT-1 G6 / R6-B6 — a restore never makes the application login
| role the owner of a database, and the restore drill never relies on it holding
| CREATEDB (owner decisions D-Q6 and D-Q7, findings N-5 and N-9).
|
| A database owner can create schemas and alter or drop the database, so a
| restored database owned by the runtime would undo rule 245 at the database
| level. The production database is owned by the superuser; restores now match it.
|
| The drill drops only a database this run proved it created: createdb
| succeeded in this run and the oid is unchanged. A collision, a failed createdb,
| a changed oid or a failed drop never drops someone else's database and never
| passes silently.
|
| The drill is exercised for real against stub binaries (sudo, psql, createdb,
| dropdb, php) that only record their arguments and one state file: no database,
| no network, no privileges. Selected by the `RestoreDrill` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rdop`.
*/

use Symfony\Component\Process\Process;

function rdopScript(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

/** Code lines only: comments describe the old behaviour on purpose. */
function rdopCode(string $path): string
{
    return implode("\n", array_filter(
        explode("\n", rdopScript($path)),
        fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
    ));
}

/**
 * Runs scripts/rollout-restore-drill.sh in a scratch APP_DIR with stub binaries.
 *
 * One state file stands for "the database with the drill's name": createdb
 * writes its oid, dropdb removes it, and the oid query reads it. $scenario
 * injects one fault:
 * - collision: the name already exists (oid 77777);
 * - race: another session creates the name between the check and createdb (88888);
 * - create_fails, drop_fails, replay_fails;
 * - oid_changes: the database is replaced after creation (99999).
 *
 * @return array{exit: int, output: string, log: list<string>, evidence: ?array, state: ?string}
 */
function rdopRunDrill(string $runtimeElevated, string $scenario = 'none', string $database = 'dmsapp', ?string $dump = null, bool $r6 = false): array
{
    $root = sys_get_temp_dir().'/rdop-'.bin2hex(random_bytes(6));
    $app = $root.'/app';
    $bin = $root.'/bin';
    mkdir($app.'/storage/app/backups/deploy', 0700, true);
    mkdir($bin, 0700, true);
    file_put_contents($app.'/.env', "DB_DATABASE={$database}\nDB_USERNAME=app_runtime\nDB_PASSWORD=not-a-real-secret\n");
    chmod($app.'/.env', 0600);
    $dump ??= "-- PostgreSQL database dump\n-- synthetic dump\nSELECT 1;\n";
    file_put_contents($app.'/storage/app/backups/deploy/dump.sql', $dump);
    chmod($app.'/storage/app/backups/deploy/dump.sql', 0640);
    $log = $root.'/calls.log';
    $state = $root.'/drill-db.oid';
    if ($scenario === 'collision') {
        file_put_contents($state, "77777\n");
    }

    $stubs = [
        // sudo -n -u <user> -- <cmd...>: record the identity, run the command.
        'sudo' => <<<'SH'
            #!/usr/bin/env bash
            [ "$1" = "-n" ] && shift; [ "$1" = "-u" ] && { user="$2"; shift 2; }; [ "$1" = "--" ] && shift
            echo "AS[$user] $*" >> "$RDOP_LOG"
            exec "$@"
            SH,
        'psql' => <<<'SH'
            #!/usr/bin/env bash
            all="$*"
            case "$all" in
              --version) echo "psql (PostgreSQL) 16.15 (stub)" ;;
              *"usesuper FROM pg_user"*) echo t ;;
              *"rolcreatedb OR rolcreaterole OR rolsuper"*) echo "$RDOP_RUNTIME_ELEVATED" ;;
              *"SELECT oid FROM pg_database"*) if [ -f "$RDOP_STATE" ]; then cat "$RDOP_STATE"; fi ;;
              *"pg_roles WHERE rolname = 'dms_owner'"*) echo "$RDOP_R6" ;;
              *"SELECT rolname FROM pg_catalog.pg_roles"*) printf 'postgres\npg_superuser\napp_runtime\npg_database_owner\n'; if [ "$RDOP_R6" = 1 ]; then echo dms_owner; fi ;;
              *"SELECT current_user"*) echo pg_superuser ;;
              *"datdba"*) echo pg_superuser ;;
              *"table_name='migrations'"*) echo 1 ;;
              *"information_schema.tables"*) echo 186 ;;
              *"-f -"*) cat > /dev/null; if [ "$RDOP_SCENARIO" = replay_fails ]; then exit 3; fi ;;
            esac
            SH,
        'createdb' => <<<'SH'
            #!/usr/bin/env bash
            echo "createdb $*" >> "$RDOP_LOG"
            case "$RDOP_SCENARIO" in
              create_fails) echo "createdb: error: database creation failed" >&2; exit 1 ;;
              race) echo 88888 > "$RDOP_STATE"; echo "createdb: error: database already exists" >&2; exit 1 ;;
            esac
            echo 16384 > "$RDOP_STATE"
            SH,
        'dropdb' => <<<'SH'
            #!/usr/bin/env bash
            echo "dropdb $*" >> "$RDOP_LOG"
            if [ "$RDOP_SCENARIO" = drop_fails ]; then echo "dropdb: error: database removal failed" >&2; exit 1; fi
            rm -f "$RDOP_STATE"
            SH,
        'php' => <<<'SH'
            #!/usr/bin/env bash
            echo "php $*" >> "$RDOP_LOG"
            case "$*" in
              *"route:list"*) echo '[{"uri":"health/ready"}]' ;;
              *"artisan --version"*) if [ "$RDOP_SCENARIO" = oid_changes ]; then echo 99999 > "$RDOP_STATE"; fi ;;
            esac
            exit 0
            SH,
    ];
    foreach ($stubs as $name => $body) {
        file_put_contents($bin.'/'.$name, $body);
        chmod($bin.'/'.$name, 0700);
    }

    // Finding A-1: the drill needs trusted digest evidence; here, an independent record of the synthetic dump.
    $process = new Process(['bash', base_path('scripts/rollout-restore-drill.sh'), '--expected-sha256', hash('sha256', $dump), 'storage/app/backups/deploy/dump.sql'], $app, [
        'PATH' => $bin.':'.getenv('PATH'),
        'APP_DIR' => $app,
        'RDOP_LOG' => $log,
        'RDOP_RUNTIME_ELEVATED' => $runtimeElevated,
        'RDOP_STATE' => $state,
        'RDOP_SCENARIO' => $scenario,
        'RDOP_R6' => $r6 ? '1' : '0',
        'ROLLOUT_DRILL_ENVIRONMENT' => 'staging',
        'PG_SUPERUSER_ACCOUNT' => 'postgres',
        // The drill runs psql, createdb and dropdb only from this pinned, verified directory.
        'DMS_PG_BINDIR' => $bin,
    ]);
    $process->setTimeout(60);
    $process->run();

    $evidencePath = $app.'/storage/app/readiness/restore-drills/latest.json';
    $result = [
        'exit' => (int) $process->getExitCode(),
        'output' => $process->getOutput().$process->getErrorOutput(),
        'log' => is_file($log) ? array_values(array_filter(explode("\n", str_replace($bin.'/', 'PGBIN/', (string) file_get_contents($log))))) : [],
        'evidence' => is_file($evidencePath) ? json_decode((string) file_get_contents($evidencePath), true) : null,
        'state' => is_file($state) ? trim((string) file_get_contents($state)) : null,
    ];

    (new Process(['rm', '-rf', '--', $root]))->run();

    return $result;
}

/** How many times the stub binary itself ran (not the sudo wrapper line). */
function rdopCalls(array $run, string $binary): int
{
    return collect($run['log'])->filter(fn (string $l): bool => str_starts_with($l, $binary.' '))->count();
}

it('creates a restored database owned by a superuser and refuses the application role as its owner', function () {
    $code = rdopCode('scripts/restore_postgres.sh');

    expect($code)->not->toContain('TARGET_OWNER="$(env_value DB_USERNAME')
        ->and($code)->toContain('[ "$TARGET_OWNER" != "$APP_ROLE" ] || die')
        ->and($code)->toContain('TARGET_OWNER="$(as_superuser psql -X -Atqc "SELECT current_user")"')
        ->and($code)->toContain("SELECT rolsuper FROM pg_roles WHERE rolname = '\${TARGET_OWNER}'")
        ->and($code)->toContain('as_superuser createdb -O "$TARGET_OWNER" -T template0 -- "$TARGET_DB"');

    // The owner is resolved and checked before the database is created.
    expect(strpos($code, 'OWNER_IS_SUPERUSER='))->toBeLessThan(strpos($code, 'as_superuser createdb -O'));
});

it('creates the rehearsal database owned by the superuser, never by the application role', function () {
    $code = rdopCode('scripts/restore-rehearsal.sh');

    expect($code)->not->toContain('createdb -O "${DB_USERNAME}"')
        ->and($code)->toContain('REHEARSAL_OWNER="$(as_superuser psql -X -Atqc "SELECT current_user")"')
        ->and($code)->toContain('as_superuser createdb -O "$REHEARSAL_OWNER" -T template0 -- "$REHEARSAL_DB"');
});

it('keeps only read-only verification through the superuser, artisan as the runtime, and no replay code at all (finding A-1)', function () {
    $code = rdopCode('scripts/rollout-restore-drill.sh');

    expect($code)->not->toContain('-U "${DB_USERNAME}"')
        ->and($code)->not->toContain('PGPASSWORD')
        ->and($code)->not->toContain('as_superuser "$CREATEDB_BIN" -O')
        ->and($code)->not->toContain('as_superuser "$DROPDB_BIN" --')
        ->and($code)->not->toContain('ON_ERROR_STOP=1 -d "$DRILL_DB" -f -')
        ->and($code)->not->toContain('restore-drills/latest.json')
        ->and($code)->toContain('REFUSED: this host never replays a backup (finding A-1)')
        ->and($code)->toContain('scripts/restore-drill-vm.sh --expected-sha256')
        ->and(preg_match('/^\s*(?!as_runtime )php artisan/m', $code))->toBe(0)
        ->and(strpos($code, 'RUNTIME_ELEVATED='))->toBeLessThan(strpos($code, 'REFUSED: this host never replays'));
});

it('refuses the drill, creating nothing, when the application role holds CREATEDB, CREATEROLE or SUPERUSER', function () {
    $run = rdopRunDrill('t');

    expect($run['exit'])->not->toBe(0)
        ->and($run['output'])->toContain("the application login role 'app_runtime' holds CREATEDB, CREATEROLE or SUPERUSER")
        ->and(collect($run['log'])->contains(fn (string $l): bool => preg_match('/(^|\] )createdb /', $l) === 1))->toBeFalse()
        ->and($run['evidence'])->toBeNull();
});

it('refuses the drill when the application role cannot be found (fail closed)', function () {
    $run = rdopRunDrill('');

    expect($run['exit'])->not->toBe(0)
        ->and(collect($run['log'])->contains(fn (string $l): bool => preg_match('/(^|\] )createdb /', $l) === 1))->toBeFalse();
});

it('verifies read-only, then refuses with exit 3: nothing is created, replayed or dropped, and no evidence is written', function () {
    $run = rdopRunDrill('f');
    $log = collect($run['log']);

    expect($run['exit'])->toBe(3, $run['output'])
        ->and($run['output'])->toContain('VERIFIED (read-only, on this host)')
        ->and($run['output'])->toContain('REFUSED: this host never replays a backup (finding A-1)')
        ->and($run['output'])->toContain('Nothing was created, replayed or dropped.')
        ->and(rdopCalls($run, 'createdb'))->toBe(0)
        ->and(rdopCalls($run, 'dropdb'))->toBe(0)
        ->and($log->contains(fn (string $l): bool => str_contains($l, ' -f -')))->toBeFalse()
        ->and($run['state'])->toBeNull()
        // Every artisan call still runs as the runtime user, never as the operator.
        ->and($log->filter(fn (string $l): bool => str_starts_with($l, 'php '))->count())
        ->toBe($log->filter(fn (string $l): bool => str_starts_with($l, 'AS[daengtisiams] php '))->count())
        ->and($run['evidence'])->toBeNull();
});

it('never touches a database that already exists under the drill name (no create, no drop)', function () {
    $run = rdopRunDrill('f', 'collision');

    expect($run['exit'])->toBe(3, $run['output'])
        ->and(rdopCalls($run, 'createdb'))->toBe(0)
        ->and(rdopCalls($run, 'dropdb'))->toBe(0)
        ->and($run['state'])->toBe('77777');
});

it('refuses a drill target name that PostgreSQL would truncate', function () {
    $run = rdopRunDrill('f', 'none', str_repeat('d', 40));

    expect($run['exit'])->not->toBe(0)
        ->and($run['output'])->toContain('longer than 63 bytes')
        ->and(rdopCalls($run, 'createdb'))->toBe(0)
        ->and(rdopCalls($run, 'dropdb'))->toBe(0);
});

it('stops the drill before anything is created when a role that owns objects in the backup is missing (D-Q6-a)', function () {
    $run = rdopRunDrill('f', 'none', 'dmsapp', "-- PostgreSQL database dump\nCREATE TABLE public.t (id int);\nALTER TABLE public.t OWNER TO dms_owner;\n");

    expect($run['exit'])->not->toBe(0)
        ->and($run['output'])->toContain('they do not exist on this cluster: dms_owner. Nothing was created.')
        ->and(rdopCalls($run, 'createdb'))->toBe(0)
        ->and(rdopCalls($run, 'dropdb'))->toBe(0)
        ->and($run['evidence'])->toBeNull();
});

it('verifies a backup whose owner roles all exist, then refuses to replay it here', function () {
    $run = rdopRunDrill('f', 'none', 'dmsapp', "-- PostgreSQL database dump\nCREATE TABLE public.t (id int);\nALTER TABLE public.t OWNER TO app_runtime;\n");

    expect($run['exit'])->toBe(3, $run['output'])
        ->and($run['output'])->toContain('owner roles present (D-Q6-a)')
        ->and(rdopCalls($run, 'createdb'))->toBe(0)
        ->and($run['evidence'])->toBeNull();
});

it('refuses a pre-R6 backup before anything is created once the cluster has the R6 owner role (P5)', function () {
    $run = rdopRunDrill('f', 'none', 'dmsapp', "-- PostgreSQL database dump\nCREATE TABLE public.t (id int);\nALTER TABLE public.t OWNER TO app_runtime;\n", r6: true);

    expect($run['exit'])->not->toBe(0)
        ->and($run['output'])->toContain("makes the application login role 'app_runtime' an object owner (a pre-R6 dump)")
        ->and($run['output'])->toContain('separate operator-controlled legacy procedure')
        ->and(rdopCalls($run, 'createdb'))->toBe(0)
        ->and($run['evidence'])->toBeNull();
});

it('verifies a post-R6 backup whose objects the owner role owns, then refuses to replay it here', function () {
    $run = rdopRunDrill('f', 'none', 'dmsapp', "-- PostgreSQL database dump\nCREATE TABLE public.t (id int);\nALTER TABLE public.t OWNER TO dms_owner;\n", r6: true);

    expect($run['exit'])->toBe(3, $run['output'])
        ->and($run['output'])->toContain('runtime ownership gate (P5) passed')
        ->and(rdopCalls($run, 'createdb'))->toBe(0)
        ->and($run['evidence'])->toBeNull();
});
