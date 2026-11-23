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
| The drill is exercised for real against stub binaries (sudo, psql, createdb,
| dropdb, php) that only record their arguments: no database, no network, no
| privileges. Selected by the `RestoreDrill` critical-gate token in both runner
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
 * @return array{exit: int, output: string, log: list<string>, evidence: ?array}
 */
function rdopRunDrill(string $runtimeElevated): array
{
    $root = sys_get_temp_dir().'/rdop-'.bin2hex(random_bytes(6));
    $app = $root.'/app';
    $bin = $root.'/bin';
    mkdir($app.'/storage/app/backups/deploy', 0700, true);
    mkdir($bin, 0700, true);
    file_put_contents($app.'/.env', "DB_DATABASE=dmsapp\nDB_USERNAME=app_runtime\nDB_PASSWORD=not-a-real-secret\n");
    file_put_contents($app.'/storage/app/backups/deploy/dump.sql', "-- synthetic dump\nSELECT 1;\n");
    $log = $root.'/calls.log';

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
              *"usesuper FROM pg_user"*) echo t ;;
              *"rolcreatedb OR rolcreaterole OR rolsuper"*) echo "$RDOP_RUNTIME_ELEVATED" ;;
              *"SELECT current_user"*) echo pg_superuser ;;
              *"datdba"*) echo pg_superuser ;;
              *"table_name='migrations'"*) echo 1 ;;
              *"information_schema.tables"*) echo 186 ;;
              *"-f -"*) cat > /dev/null ;;
            esac
            SH,
        'createdb' => "#!/usr/bin/env bash\necho \"createdb \$*\" >> \"\$RDOP_LOG\"\n",
        'dropdb' => "#!/usr/bin/env bash\necho \"dropdb \$*\" >> \"\$RDOP_LOG\"\n",
        'php' => <<<'SH'
            #!/usr/bin/env bash
            echo "php $*" >> "$RDOP_LOG"
            case "$*" in *"route:list"*) echo '[{"uri":"health/ready"}]' ;; esac
            exit 0
            SH,
    ];
    foreach ($stubs as $name => $body) {
        file_put_contents($bin.'/'.$name, $body);
        chmod($bin.'/'.$name, 0700);
    }

    $process = new Process(['bash', base_path('scripts/rollout-restore-drill.sh'), 'storage/app/backups/deploy/dump.sql'], $app, [
        'PATH' => $bin.':'.getenv('PATH'),
        'APP_DIR' => $app,
        'RDOP_LOG' => $log,
        'RDOP_RUNTIME_ELEVATED' => $runtimeElevated,
        'ROLLOUT_DRILL_ENVIRONMENT' => 'staging',
        'PG_SUPERUSER_ACCOUNT' => 'postgres',
    ]);
    $process->setTimeout(60);
    $process->run();

    $evidencePath = $app.'/storage/app/readiness/restore-drills/latest.json';
    $result = [
        'exit' => (int) $process->getExitCode(),
        'output' => $process->getOutput().$process->getErrorOutput(),
        'log' => is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [],
        'evidence' => is_file($evidencePath) ? json_decode((string) file_get_contents($evidencePath), true) : null,
    ];

    (new Process(['rm', '-rf', '--', $root]))->run();

    return $result;
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

it('runs every drill database operation through the superuser and artisan as the runtime user', function () {
    $code = rdopCode('scripts/rollout-restore-drill.sh');

    expect($code)->not->toContain('-U "${DB_USERNAME}"')
        ->and($code)->not->toContain('PGPASSWORD')
        ->and($code)->toContain('as_superuser dropdb --if-exists -- "$DRILL_DB"')
        ->and($code)->toContain('as_superuser createdb -O "$DRILL_OWNER" -T template0 -- "$DRILL_DB"')
        ->and($code)->toContain('as_superuser psql -X -q -v ON_ERROR_STOP=1 -d "$DRILL_DB" -f - < "$BACKUP_FILE"')
        ->and(preg_match('/^\s*(?!as_runtime )php artisan/m', $code))->toBe(0)
        ->and(strpos($code, 'RUNTIME_ELEVATED='))->toBeLessThan(strpos($code, 'as_superuser createdb -O'));
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

it('drills through the superuser, owns the disposable database by it, and drops only that database', function () {
    $run = rdopRunDrill('f');
    $log = collect($run['log']);
    $startsWith = fn (string $prefix): bool => $log->contains(fn (string $l): bool => str_starts_with($l, $prefix));

    expect($run['exit'])->toBe(0, $run['output'])
        // Created, filled and dropped by the superuser OS account; owned by the superuser role.
        ->and($startsWith('AS[postgres] createdb -O pg_superuser -T template0 -- dmsapp_restore_drill_'))->toBeTrue()
        ->and($startsWith('AS[postgres] psql -X -q -v ON_ERROR_STOP=1 -d dmsapp_restore_drill_'))->toBeTrue()
        ->and($startsWith('AS[postgres] dropdb --if-exists -- dmsapp_restore_drill_'))->toBeTrue()
        // No database command ever runs as, or for, the application role.
        ->and($log->contains(fn (string $l): bool => str_contains($l, 'app_runtime') && preg_match('/\b(createdb|dropdb|psql)\b/', $l) === 1 && ! str_contains($l, 'rolname')))->toBeFalse()
        // Every artisan call runs as the runtime user, never as the operator.
        ->and($log->filter(fn (string $l): bool => str_starts_with($l, 'php '))->count())
        ->toBe($log->filter(fn (string $l): bool => str_starts_with($l, 'AS[daengtisiams] php '))->count())
        ->and($startsWith('AS[daengtisiams] php artisan foundation:backup-verify'))->toBeTrue()
        ->and($run['evidence']['decision'] ?? null)->toBe('GO')
        ->and($run['evidence']['verification']['database_owner_superuser'] ?? null)->toBe('GO')
        ->and($run['evidence']['production_overwrite'] ?? null)->toBeFalse();
});
