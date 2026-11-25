<?php

use Symfony\Component\Process\Process;

/*
 * R6-B6 isolated restore drill (finding A-1; owner directive "R6-B6 ISOLATED RESTORE DRILL", D1–D10).
 *
 * The isolation profile of the sandbox unit, the D6 limits, the result parser and the host-side decision. Every
 * property asserted here exists because a test proved the weaker form unsafe (F-ISO-1/2, DR-1..DR-18 in
 * docs/architecture/r6-b6-isolated-restore-drill-design.md). Static and bash-level checks only: the real sandbox runs
 * in RestoreDrillSandboxRuntimeTest and in the dedicated real-sandbox CI gate.
 */

/** Runs bash with the sandbox library (and the integrity library) loaded. */
function rdipLib(string $script, array $env = []): array
{
    $process = new Process(['bash', '-c', '. "$LIB/restore-integrity.sh"; . "$LIB/restore-sandbox.sh"; '.$script], null, array_merge([
        'LIB' => base_path('scripts/lib'),
        'PATH' => getenv('PATH'),
    ], $env));
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
}

function rdipProps(string $mode): array
{
    $run = rdipLib('dms_sandbox_properties '.$mode.' dmsdrill-0123456789ab 1024 1536 64 1500 /opt/x/inner.sh /w/archive.dump /w/guard.sql');
    expect($run['exit'])->toBe(0, $run['err']);

    return explode("\n", trim($run['out']));
}

function rdipTemp(string $content): string
{
    $file = tempnam(sys_get_temp_dir(), 'rdip');
    file_put_contents($file, $content);

    return $file;
}

it('requires every isolation property of the sandbox unit, in system and in user mode', function (string $mode) {
    $props = rdipProps($mode);
    $required = [
        'NoNewPrivileges=yes', 'CapabilityBoundingSet=', 'AmbientCapabilities=', 'RestrictSUIDSGID=yes', 'LimitCORE=0', 'CoredumpFilter=0',
        'ProtectSystem=strict', 'ProtectHome=yes', 'PrivateTmp=yes', 'PrivateDevices=yes', 'PrivateIPC=yes',
        'TemporaryFileSystem=/run:ro', 'TemporaryFileSystem=/var:ro', 'TemporaryFileSystem=/etc:ro',
        'BindReadOnlyPaths=/etc/passwd /etc/group /etc/nsswitch.conf /etc/ld.so.cache -/etc/localtime',
        // F-ISO-2: every tmpfs is explicitly noexec; F-ISO-1: a private /dev/shm.
        'TemporaryFileSystem=/srv:size=1024M,mode=1777,noexec,nosuid,nodev',
        'TemporaryFileSystem=/dev/shm:size=64M,mode=1777,noexec,nosuid,nodev',
        // DR-1: PrivateTmp is host disk without a cap.
        'ReadOnlyPaths=/tmp /var/tmp', '--setenv=TMPDIR=/srv',
        // DR-12: host cgroup tree and devpts.
        'InaccessiblePaths=/sys/fs/cgroup /dev/pts',
        'BindReadOnlyPaths=/opt/x/inner.sh:/opt/dmsdrill/inner.sh',
        // PR-6: the structured archive and the load guard, read-only; stdin carries nothing.
        'BindReadOnlyPaths=/w/archive.dump:/opt/dmsdrill/archive.dump /w/guard.sql:/opt/dmsdrill/guard.sql',
        'ProtectKernelTunables=yes', 'ProtectKernelModules=yes', 'ProtectKernelLogs=yes', 'ProtectControlGroups=yes',
        'ProtectProc=invisible', 'ProcSubset=pid', 'RestrictNamespaces=yes', 'MemoryDenyWriteExecute=yes',
        'SystemCallArchitectures=native', 'SystemCallFilter=@system-service',
        'SystemCallFilter=~@privileged @resources @mount @swap @reboot @raw-io @module @debug @obsolete @cpu-emulation @clock',
        // DR-2, DR-4, DR-12: io_uring sockets, memfd execution, keyrings.
        'SystemCallFilter=~memfd_create io_uring_setup io_uring_enter io_uring_register @keyring',
        'RestrictAddressFamilies=AF_UNIX', 'PrivateNetwork=yes', 'IPAddressDeny=any',
        'NoExecPaths=/ /tmp /var/tmp',
        'ExecPaths=/usr/lib/postgresql/16/bin /usr/lib/postgresql/16/lib /usr/lib/x86_64-linux-gnu /usr/lib64 /usr/bin/bash /usr/bin/dash',
        'MemoryMax=1536M', 'MemorySwapMax=0', 'TasksMax=64', 'OOMPolicy=stop', 'KillMode=control-group',
        // PR-1: a signal-killed main process is a failure only under oneshot; its time limit is TimeoutStartSec.
        'Type=oneshot', 'TimeoutStartSec=1500',
    ];
    foreach ($required as $property) {
        expect($props)->toContain($property);
    }
    expect(collect($props)->filter(fn (string $p): bool => str_starts_with($p, 'TemporaryFileSystem=') && ! str_ends_with($p, ':ro') && ! str_contains($p, 'noexec'))->all())->toBe([]);
})->with(['system', 'user']);

it('runs the drill as a DynamicUser named after the run, only in system mode (D2)', function () {
    $system = rdipProps('system');
    $user = rdipProps('user');

    expect($system)->toContain('DynamicUser=yes')
        ->and($system)->toContain('User=dmsdrill-0123456789ab')
        ->and($system)->toContain('--setenv=DRILL_EXPECT_DYNAMIC_UID=1')
        ->and($user)->not->toContain('DynamicUser=yes')
        ->and(collect($user)->contains(fn (string $p): bool => str_starts_with($p, 'User=')))->toBeFalse();
});

it('refuses limits above the D6 pilot ceilings, and never trims them', function (string $args, int $exit, string $message) {
    $run = rdipLib('dms_sandbox_limits_check '.$args);

    expect($run['exit'])->toBe($exit)
        ->and($run['out'])->toContain($message);
})->with([
    'at the ceilings' => ['1 2048 10 30 64', 0, ''],
    'two vCPUs' => ['2 2048 10 30 64', 1, 'vcpu 2 exceeds the pilot ceiling 1 (D6)'],
    'more memory' => ['1 4096 10 30 64', 1, 'memory_mib 4096 exceeds'],
    'more disk' => ['1 2048 11 30 64', 1, 'disk_gib 11 exceeds'],
    'longer' => ['1 2048 10 31 64', 1, 'minutes 31 exceeds'],
    'more tasks' => ['1 2048 10 30 65', 1, 'tasks 65 exceeds'],
    'not a number' => ['1 2048 10 30 x', 1, 'is not a positive integer'],
    'zero' => ['0 2048 10 30 64', 1, 'is not a positive integer'],
]);

it('refuses a unit profile outside the limits or with a tmpfs not smaller than MemoryMax', function (string $args) {
    $run = rdipLib('dms_sandbox_properties '.$args);

    expect($run['exit'])->toBe(2)
        ->and($run['out'])->toBe('');
})->with([
    'tasks above 64' => ['system dmsdrill-0123456789ab 1024 1536 65 1500 /x /a /g'],
    'timeout above 30 min' => ['system dmsdrill-0123456789ab 1024 1536 64 1801 /x /a /g'],
    'memory above 2 GiB' => ['system dmsdrill-0123456789ab 1024 4096 64 1500 /x /a /g'],
    'tmpfs as large as memory' => ['system dmsdrill-0123456789ab 1536 1536 64 1500 /x /a /g'],
    'bad unit name' => ['system dms-restore-drill-x 1024 1536 64 1500 /x /a /g'],
    'unknown mode' => ['root dmsdrill-0123456789ab 1024 1536 64 1500 /x /a /g'],
]);

it('draws a fresh run name of at most 31 characters, valid as a DynamicUser name (DR-11)', function () {
    $run = rdipLib('dms_sandbox_unit_name; dms_sandbox_unit_name');
    $names = explode("\n", trim($run['out']));

    expect($names)->toHaveCount(2)
        ->and($names[0])->toMatch('/^dmsdrill-[0-9a-f]{12}$/')
        ->and(strlen($names[0]))->toBeLessThanOrEqual(31)
        ->and($names[0])->not->toBe($names[1]);
});

it('keeps the inner script to builtins and pinned binaries, restores the structured archive by section as the restore role, superuser locked, fd 3 closed', function () {
    $inner = (string) file_get_contents(base_path('scripts/restore-drill-sandbox-inner.sh'));
    $code = implode("\n", array_filter(explode("\n", $inner), fn (string $l): bool => ! str_starts_with(ltrim($l), '#')));

    expect(preg_match('/(^|[;|&(]\s*|\$\(\s*)(cat|sed|awk|grep|rm|mkdir|sleep|head|tail|cut|tr|date|id|ls|cp|mv|chmod|env|python3?|perl|sh)\s/m', $code))->toBe(0)
        // PR-6 + D5: pg_restore emits each section under a fresh restricted-mode key; psql runs it as the restore role (D4).
        ->and($code)->toContain('"$PGBIN/pg_restore" --section="$section" -L "$R/restore.list" --restrict-key="$key" -f - "$ARCHIVE" 2> "$R/pg_restore.err" 3>&-')
        ->and($code)->toContain('-U "$RR" -d "$DB" -f - > /dev/null 2> "$R/replay.err" 3>&-')
        ->and($code)->toContain('restore_section pre-data')
        ->and(strpos($code, 'restore_section pre-data'))->toBeLessThan(strpos($code, '-d "$DB" -f "$GUARD"'))
        ->and(strpos($code, '-d "$DB" -f "$GUARD"'))->toBeLessThan(strpos($code, 'restore_section data'))
        ->and(strpos($code, 'restore_section data'))->toBeLessThan(strpos($code, 'restore_section post-data'))
        ->and($code)->toContain('CREATE ROLE :"r" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;')
        // DR-5: the bootstrap superuser is NOLOGIN before the first byte of the archive is restored.
        ->and(strpos($code, 'ALTER ROLE :"r" NOLOGIN;'))->toBeLessThan(strpos($code, 'restore_section pre-data'))
        // DR-10: the server, pg_restore and every psql close the result channel.
        ->and($code)->toContain('> "$R/pg.log" 2>&1 3>&- &')
        ->and(preg_match_all('/"\$PGBIN\/(psql|pg_restore)"(?![^\n]*3>&-)/', $code))->toBe(0)
        // F-ISO-4: always an immediate shutdown; only the SQLSTATE of a failure leaves the unit.
        ->and($code)->toContain('kill -QUIT "$PGPID"')
        ->and($code)->toContain('-v VERBOSITY=sqlstate')
        // PR-5: the per-table digest; PR-8: the extension allowlist.
        ->and($code)->toContain('emit "table_digest=$tdigest"')
        ->and($code)->toContain('emit "extensions_outside_allowlist=$extbad"')
        // P4P-1: function calls tracked by a superuser-only setting, flushed per section; the catalog shape; user code
        // decided from the dependency graph, including references that no longer resolve.
        ->and($code)->toContain('-c track_functions=all')
        ->and($code)->toContain("'SELECT pg_catalog.pg_stat_force_next_flush();'")
        ->and($code)->toContain('emit "user_code_executed=$ucalls"')
        ->and($code)->toContain('emit "catalog_shape=$shape"')
        ->and($code)->toContain("'pg_catalog.pg_attrdef'::pg_catalog.regclass")
        ->and($code)->toContain('NOT EXISTS (SELECT 1 FROM pg_catalog.pg_proc p WHERE p.oid OPERATOR(pg_catalog.=) refs.id)')
        // P4P-1(b): allowlisted extensions are created by the bootstrap superuser before the backup is read; the
        // archive's EXTENSION entries never reach the restore.
        ->and($code)->toContain('CREATE EXTENSION IF NOT EXISTS :"e" WITH SCHEMA public;')
        ->and(strpos($code, 'CREATE EXTENSION IF NOT EXISTS'))->toBeLessThan(strpos($code, "<<< 'ALTER ROLE :\"r\" NOLOGIN;'"))
        ->and($code)->toContain('-L "$R/restore.list"')
        // DR-3: the effective profile is attested before any input.
        ->and($code)->toContain('fail profile_exec_writable_mount 13')
        ->and($code)->toContain('fail profile_writable_persistent_fs 13')
        ->and($code)->toContain('fail profile_not_dynamic_uid 13')
        ->and($code)->toContain('fail profile_network_interfaces 13')
        ->and(strpos($code, 'emit "profile=attested"'))->toBeLessThan(strpos($code, '"$PGBIN/initdb"'));
});

it('ships none of the prototype-only paths (DR-18)', function (string $path) {
    $code = (string) file_get_contents(base_path($path));

    expect($code)->not->toContain('DRILL_REPLAY_AS')
        ->and($code)->not->toContain('DRILL_TEST_REPORT')
        ->and($code)->not->toContain('DRILL_COMPAT')
        ->and($code)->not->toContain('--relax')
        ->and($code)->not->toContain('--no-trailer-check')
        ->and($code)->not->toContain('drill_test_report');
})->with([
    'scripts/restore-drill-sandbox-inner.sh', 'scripts/lib/restore-sandbox.sh', 'scripts/restore-drill-vm-guest.sh',
    'scripts/restore-drill-vm.sh', 'scripts/lib/restore-vm.sh',
]);

it('parses the result channel strictly: only well-formed lines, each key once, bounded', function () {
    $raw = "DRILL result=GO\nDRILL result=GO\nnoise\nDRILL bad key=1\nDRILL table_count=1\x1b[31m\nDRILL x=".str_repeat('a', 81)."\nDRILL ok_key=1\n";
    $file = rdipTemp($raw);
    $run = rdipLib('dms_sandbox_parse "$F"; echo "dup=$DMS_SANDBOX_DUPLICATES rej=$DMS_SANDBOX_REJECTED"; for k in "${!DMS_SANDBOX_RESULT[@]}"; do echo "$k=${DMS_SANDBOX_RESULT[$k]}"; done | sort', ['F' => $file]);
    unlink($file);

    expect($run['out'])->toContain('dup=1 rej=4')
        ->and($run['out'])->toContain("ok_key=1\nresult=GO")
        ->and($run['out'])->not->toContain('table_count');
});

it('decides GO only when every fact holds, including the per-table digest the host computed (PR-5)', function (array $result, string $args, string $decision) {
    $lines = collect($result)->map(fn (string $v, string $k): string => "DRILL {$k}={$v}")->implode("\n")."\n";
    $file = rdipTemp($lines);
    $run = rdipLib('dms_sandbox_parse "$F"; dms_sandbox_decide '.$args, ['F' => $file]);
    unlink($file);

    expect(trim($run['out']))->toBe($decision);
})->with(function () {
    $d = str_repeat('a', 64);
    $sh = '1:2:0:2:0:0:1';
    $good = ['profile' => 'attested', 'bootstrap_superuser' => 'nologin', 'result' => 'GO', 'migrations_table' => '1', 'user_code_reachable' => '0', 'user_code_executed' => '0', 'catalog_shape' => $sh, 'extensions_outside_allowlist' => '0', 'base_table_count' => '2', 'table_digest' => $d];

    return [
        'all facts hold' => [$good, "0 {$d} 2 {$sh}", 'GO'],
        'unit failed' => [$good, "20 {$d} 2 {$sh}", 'FAIL:unit_exit_20'],
        'profile not attested' => [array_merge($good, ['profile' => 'no']), "0 {$d} 2 {$sh}", 'FAIL:profile_not_attested'],
        'superuser not locked' => [array_diff_key($good, ['bootstrap_superuser' => 1]), "0 {$d} 2 {$sh}", 'FAIL:superuser_not_locked'],
        'sandbox said FAIL' => [array_merge($good, ['result' => 'FAIL', 'stage' => 'replay']), "0 {$d} 2 {$sh}", 'FAIL:sandbox_replay'],
        'no migrations table' => [array_merge($good, ['migrations_table' => '0']), "0 {$d} 2 {$sh}", 'FAIL:no_migrations_table'],
        'user code on a restore path' => [array_merge($good, ['user_code_reachable' => '1']), "0 {$d} 2 {$sh}", 'FAIL:user_code_in_restore_path'],
        'user code not reported' => [array_diff_key($good, ['user_code_reachable' => 1]), "0 {$d} 2 {$sh}", 'FAIL:user_code_in_restore_path'],
        'extension outside the allowlist' => [array_merge($good, ['extensions_outside_allowlist' => '1']), "0 {$d} 2 {$sh}", 'FAIL:extension_outside_allowlist'],
        'user code ran during the restore (P4P-1)' => [array_merge($good, ['user_code_executed' => '1']), "0 {$d} 2 {$sh}", 'FAIL:user_code_executed_in_restore'],
        'function calls not reported (P4P-1)' => [array_diff_key($good, ['user_code_executed' => 1]), "0 {$d} 2 {$sh}", 'FAIL:user_code_executed_in_restore'],
        'a function was dropped (catalog shape, P4P-1)' => [array_merge($good, ['catalog_shape' => '0:2:0:2:0:0:1']), "0 {$d} 2 {$sh}", 'FAIL:catalog_shape_mismatch'],
        'no catalog shape reported' => [array_diff_key($good, ['catalog_shape' => 1]), "0 {$d} 2 {$sh}", 'FAIL:catalog_shape_mismatch'],
        'host shape malformed' => [$good, "0 {$d} 2 noshape", 'FAIL:catalog_shape_mismatch'],
        'tables differ' => [array_merge($good, ['base_table_count' => '1']), "0 {$d} 2 {$sh}", 'FAIL:table_count_mismatch'],
        'one table lost rows (digest differs)' => [array_merge($good, ['table_digest' => str_repeat('b', 64)]), "0 {$d} 2 {$sh}", 'FAIL:per_table_rows_mismatch'],
        'no digest reported' => [array_diff_key($good, ['table_digest' => 1]), "0 {$d} 2 {$sh}", 'FAIL:per_table_rows_mismatch'],
        'host digest malformed' => [$good, "0 nodigest 2 {$sh}", 'FAIL:per_table_rows_mismatch'],
    ];
});

it('fails the decision when the result channel overflows its window or carries NUL padding (VR-5)', function (string $padding) {
    $lines = "DRILL profile=attested\nDRILL bootstrap_superuser=nologin\nDRILL result=GO\nDRILL migrations_table=1\nDRILL base_table_count=2\nDRILL rows_total=3\n";
    $file = rdipTemp($lines.$padding);
    $run = rdipLib('dms_sandbox_parse "$F"; echo "rej=$DMS_SANDBOX_REJECTED"; dms_sandbox_decide 0 0 1 1 2 3', ['F' => $file]);
    unlink($file);

    expect($run['out'])->not->toContain('rej=0')
        ->and(trim(collect(explode("\n", trim($run['out'])))->last()))->toStartWith('FAIL:');
})->with([
    'NUL padding' => [str_repeat("\0", 100)],
    'beyond 8 KiB' => [str_repeat("DRILL pad=x\n", 800)],
]);

it('fails the decision when the result channel carried a duplicate or a rejected line (forgery)', function (string $extra, string $decision) {
    $lines = "DRILL profile=attested\nDRILL bootstrap_superuser=nologin\nDRILL result=GO\nDRILL migrations_table=1\nDRILL user_code_reachable=0\nDRILL user_code_executed=0\nDRILL catalog_shape=1:2:0:2:0:0:1\nDRILL extensions_outside_allowlist=0\nDRILL base_table_count=2\nDRILL table_digest=".str_repeat('a', 64)."\n".$extra;
    $file = rdipTemp($lines);
    $run = rdipLib('dms_sandbox_parse "$F"; dms_sandbox_decide 0 '.str_repeat('a', 64).' 2 1:2:0:2:0:0:1', ['F' => $file]);
    unlink($file);

    expect(trim($run['out']))->toBe($decision);
})->with([
    'forged duplicate' => ["DRILL result=GO\n", 'FAIL:duplicate_result_keys'],
    'injected noise' => ["forged line\n", 'FAIL:rejected_result_lines'],
]);

it('starts the guest agent only in system mode inside the VM image (D2)', function () {
    $unit = (string) file_get_contents(base_path('deploy/restore-drill-vm/dms-restore-drill-guest.service'));

    expect($unit)->toContain('ExecStart=/usr/local/lib/dms-restore-drill/restore-drill-vm-guest.sh /dev/disk/by-id/virtio-dmsdrill-input /dev/disk/by-id/virtio-dmsdrill-output system')
        ->and($unit)->toContain('ExecStopPost=/usr/bin/systemctl --no-block poweroff')
        ->and($unit)->toContain('StandardError=null');
});
