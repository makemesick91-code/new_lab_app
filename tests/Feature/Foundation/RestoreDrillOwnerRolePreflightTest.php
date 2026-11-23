<?php

/*
| DMS-SPN4-READY-PILOT-1 R6-B6, owner decisions D-Q6-a and D-Q6-b.
|
| D-Q6-a: when a role that owns objects in a dump does not exist on the target
| cluster, every restore path stops before it creates or replays anything, and
| names the missing roles. It never creates the role, never strips or rewrites
| ownership, and never makes the runtime the owner instead.
|
| D-Q6-b: there is no exception. No `--legacy-pre-r6-owner` flag (or any other
| path) lets the runtime own a restored database.
|
| The library functions run for real in bash against a stub psql. The three
| restore tools are checked by code order. Selected by the `RestoreDrill`
| critical-gate token in both runner variants and declared in
| critical_gate_mandatory_suites (CG-19). Helper prefix `rorp`.
*/

use Symfony\Component\Process\Process;

/** Code lines only: comments describe the old behaviour on purpose. */
function rorpCode(string $path): string
{
    return implode("\n", array_filter(
        explode("\n", (string) file_get_contents(base_path($path))),
        fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
    ));
}

/**
 * Sources scripts/lib/restore-integrity.sh and runs $call against $dump. The stub
 * `rorp_su` stands for as_superuser: it answers the pg_roles query with $roles,
 * or fails when $roles is null.
 *
 * @param  list<string>|null  $roles
 * @return array{exit: int, out: string}
 */
function rorpLib(string $call, string $dump, ?array $roles = ['postgres'], string $runtime = 'app_runtime'): array
{
    $dir = sys_get_temp_dir().'/rorp-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/dump.sql', $dump);
    file_put_contents($dir.'/roles', $roles === null ? '' : implode("\n", $roles)."\n");

    $script = <<<'SH'
        set -uo pipefail
        . "$RORP_LIB"
        rorp_su() {
          [ "$1" = psql ] || return 9
          [ "$RORP_ROLES_OK" = 1 ] || return 3
          case "$*" in
            *"rolname = 'dms_owner'"*) grep -cx dms_owner "$RORP_DIR/roles" || true ;;
            *"pg_get_userbyid"*) echo 7 ;;
            *) cat "$RORP_DIR/roles" ;;
          esac
        }
        case "$RORP_CALL" in
          owners) dms_restore_owner_roles "$RORP_DIR/dump.sql" ;;
          missing) dms_restore_missing_owner_roles "$RORP_DIR/dump.sql" rorp_su ;;
          gate) dms_restore_runtime_ownership_gate "$RORP_DIR/dump.sql" "$RORP_RUNTIME" rorp_su ;;
          owned) dms_restore_runtime_owned_objects synthetic_db "$RORP_RUNTIME" rorp_su ;;
        esac
        SH;

    $process = new Process(['bash', '-c', $script], null, [
        'RORP_LIB' => base_path('scripts/lib/restore-integrity.sh'),
        'RORP_DIR' => $dir,
        'RORP_CALL' => $call,
        'RORP_ROLES_OK' => $roles === null ? '0' : '1',
        'RORP_RUNTIME' => $runtime,
        'PATH' => getenv('PATH'),
    ]);
    $process->run();
    (new Process(['rm', '-rf', '--', $dir]))->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => $process->getOutput()];
}

const RORP_DUMP = <<<'SQL'
    -- PostgreSQL database dump
    SET SESSION AUTHORIZATION 'legacy_author';
    ALTER TABLE public.users OWNER TO app_runtime;
    ALTER SEQUENCE public.users_id_seq OWNER TO app_runtime;
    ALTER SCHEMA public OWNER TO pg_database_owner;
    ALTER FUNCTION public.f() OWNER TO "Mixed ""Case"" Owner";
    COPY public.notes (id, body) FROM stdin;
    1	ALTER TABLE x OWNER TO ghost_from_table_data;
    \.
    ALTER TABLE public.notes OWNER TO dms_owner;
    SQL;

it('reads every object owner from a plain dump, unquoted, and never from table data', function () {
    $run = rorpLib('owners', RORP_DUMP);

    expect($run['exit'])->toBe(0)
        ->and(explode("\n", trim($run['out'])))->toBe([
            'Mixed "Case" Owner',
            'app_runtime',
            'dms_owner',
            'legacy_author',
            'pg_database_owner',
        ]);
});

it('names each owner role missing on the target and fails (D-Q6-a)', function () {
    $run = rorpLib('missing', RORP_DUMP, ['postgres', 'app_runtime', 'pg_database_owner']);

    expect($run['exit'])->toBe(1)
        ->and(explode("\n", trim($run['out'])))->toBe(['Mixed "Case" Owner', 'dms_owner', 'legacy_author']);
});

it('passes when every owner role exists, and when the dump names no owner at all', function () {
    expect(rorpLib('missing', RORP_DUMP, ['postgres', 'app_runtime', 'pg_database_owner', 'dms_owner', 'legacy_author', 'Mixed "Case" Owner']))
        ->toBe(['exit' => 0, 'out' => ''])
        ->and(rorpLib('missing', "-- PostgreSQL database dump\nSELECT 1;\n", null))->toBe(['exit' => 0, 'out' => '']);
});

it('fails closed with a distinct status when the cluster roles cannot be read', function () {
    expect(rorpLib('missing', RORP_DUMP, null)['exit'])->toBe(2);
});

it('checks owner roles in every restore tool before it creates or replays anything', function (string $path, string $source, string $create, string $replay) {
    $code = rorpCode($path);
    $check = strpos($code, "dms_restore_missing_owner_roles \"{$source}\" as_superuser");

    expect($check)->not->toBeFalse("{$path} does not check owner roles")
        ->and($check)->toBeLessThan(strpos($code, $create))
        ->and($check)->toBeLessThan(strpos($code, $replay));
})->with([
    'restore_postgres.sh' => ['scripts/restore_postgres.sh', '$STAGED_DUMP', 'as_superuser createdb -O', 'read_dump | as_superuser psql'],
    'restore-rehearsal.sh' => ['scripts/restore-rehearsal.sh', '$STAGED_BACKUP', 'as_superuser createdb -O', 'read_backup | as_superuser psql'],
    'rollout-restore-drill.sh' => ['scripts/rollout-restore-drill.sh', '$BACKUP_FILE', 'as_superuser createdb -O', 'as_superuser psql -X -q -v ON_ERROR_STOP=1 -d "$DRILL_DB" -f -'],
]);

it('never creates a role, strips ownership, or lets the runtime own a restored database (D-Q6-a, D-Q6-b)', function (string $path) {
    $code = rorpCode($path);

    expect($code)->not->toContain('legacy-pre-r6-owner')
        ->and($code)->not->toContain('--no-owner')
        ->and($code)->not->toMatch('/CREATE\s+(ROLE|USER)\b/i')
        ->and($code)->not->toMatch('/createuser\b/')
        ->and($code)->not->toMatch('/createdb[^\n]*-O "?\$\{?DB_USERNAME/')
        ->and($code)->not->toMatch('/OWNER TO[^\n]*DB_USERNAME/');
})->with(['scripts/restore_postgres.sh', 'scripts/restore-rehearsal.sh', 'scripts/rollout-restore-drill.sh', 'scripts/lib/restore-integrity.sh']);

/**
 * Runs a copy of the drill whose library is $tamper-ed: 'writable' (mode 0666) or
 * 'symlink' (the library is a link to a file elsewhere).
 */
function rorpTamperedDrill(string $tamper): array
{
    $root = sys_get_temp_dir().'/rorp-tree-'.bin2hex(random_bytes(6));
    mkdir($root.'/scripts/lib', 0755, true);
    copy(base_path('scripts/rollout-restore-drill.sh'), $root.'/scripts/rollout-restore-drill.sh');
    if ($tamper === 'symlink') {
        copy(base_path('scripts/lib/restore-integrity.sh'), $root.'/elsewhere.sh');
        symlink($root.'/elsewhere.sh', $root.'/scripts/lib/restore-integrity.sh');
    } else {
        copy(base_path('scripts/lib/restore-integrity.sh'), $root.'/scripts/lib/restore-integrity.sh');
        chmod($root.'/scripts/lib/restore-integrity.sh', 0666);
    }

    $process = new Process(['bash', $root.'/scripts/rollout-restore-drill.sh'], $root, [
        'APP_DIR' => $root.'/no-app',
        'PATH' => getenv('PATH'),
    ]);
    $process->run();
    (new Process(['rm', '-rf', '--', $root]))->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => $process->getOutput().$process->getErrorOutput()];
}

it('refuses to load a restore library that another identity could have written, before doing anything', function (string $tamper) {
    $run = rorpTamperedDrill($tamper);

    expect($run['exit'])->not->toBe(0)
        ->and($run['out'])->toContain('refusing to load it')
        ->and($run['out'])->not->toContain('== ROLL-5-1A restore drill ==');
})->with(['writable', 'symlink']);

it('passes runtime-owned dumps on a cluster without the R6 owner role (the current design until R6-D)', function () {
    expect(rorpLib('gate', RORP_DUMP, ['postgres', 'app_runtime']))->toBe(['exit' => 0, 'out' => '']);
});

it('refuses a dump that makes the runtime an owner once the R6 owner role exists, and never with a way around it (P5, D-Q6-b)', function () {
    $run = rorpLib('gate', RORP_DUMP, ['postgres', 'app_runtime', 'dms_owner']);

    expect($run['exit'])->toBe(1)
        ->and($run['out'])->toContain("this cluster has the R6 owner role 'dms_owner', and the dump makes the application login role 'app_runtime' an object owner (a pre-R6 dump)")
        ->and($run['out'])->toContain('separate operator-controlled legacy procedure');
});

it('passes a post-R6 dump whose objects the owner role owns, and fails closed when it cannot decide', function () {
    $postR6 = "-- PostgreSQL database dump\nALTER TABLE public.t OWNER TO dms_owner;\n";

    expect(rorpLib('gate', $postR6, ['postgres', 'app_runtime', 'dms_owner'])['exit'])->toBe(0)
        ->and(rorpLib('gate', RORP_DUMP, null)['exit'])->toBe(2)
        ->and(rorpLib('gate', RORP_DUMP, ['postgres', 'dms_owner'], "app'runtime")['exit'])->toBe(2);
});

it('counts the objects the runtime owns from the catalog only', function () {
    expect(rorpLib('owned', RORP_DUMP))->toBe(['exit' => 0, 'out' => "7\n"])
        ->and(rorpLib('owned', RORP_DUMP, ['postgres'], 'bad name')['exit'])->toBe(2);
});

it('runs the runtime ownership gate in every restore tool after the role preflight and before anything is created', function (string $path, string $source, string $create) {
    $code = rorpCode($path);
    $preflight = strpos($code, 'dms_restore_missing_owner_roles');
    $gate = strpos($code, "dms_restore_runtime_ownership_gate \"{$source}\"");

    expect($gate)->not->toBeFalse("{$path} has no runtime ownership gate")
        ->and($gate)->toBeGreaterThan($preflight)
        ->and($gate)->toBeLessThan(strpos($code, $create));
})->with([
    'restore_postgres.sh' => ['scripts/restore_postgres.sh', '$STAGED_DUMP', 'as_superuser createdb -O'],
    'restore-rehearsal.sh' => ['scripts/restore-rehearsal.sh', '$STAGED_BACKUP', 'as_superuser createdb -O'],
    'rollout-restore-drill.sh' => ['scripts/rollout-restore-drill.sh', '$BACKUP_FILE', 'as_superuser createdb -O'],
]);

it('never reports RESTORE OK for a runtime-owned database after R6', function () {
    $code = rorpCode('scripts/restore_postgres.sh');
    $check = strpos($code, 'RESTORED BUT NOT A FINAL STATE');

    expect($check)->not->toBeFalse()
        ->and($check)->toBeGreaterThan(strpos($code, 'read_dump | as_superuser psql'))
        ->and($check)->toBeLessThan(strrpos($code, 'echo "RESTORE OK:'));
});
