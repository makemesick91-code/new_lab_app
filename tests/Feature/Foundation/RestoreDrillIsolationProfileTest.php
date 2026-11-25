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
    $run = rdipLib('dms_sandbox_properties '.$mode.' dmsdrill-0123456789ab 1024 1536 64 1500 /opt/x/inner.sh');
    expect($run['exit'])->toBe(0, $run['err']);

    return explode("\n", trim($run['out']));
}

function rdipTemp(string $content): string
{
    $file = tempnam(sys_get_temp_dir(), 'rdip');
    file_put_contents($file, $content);

    return $file;
}

/** A synthetic pg_dump 16 plain dump: two tables, three rows, the restrict envelope and the trailer. */
const RDIP_DUMP = "--\n-- PostgreSQL database dump\n--\n\n\\restrict K1synthetic\n\nSET statement_timeout = 0;\nCREATE TABLE public.a (id integer);\nCREATE TABLE public.migrations (id integer);\nCOPY public.a (id) FROM stdin;\n1\n2\n\\.\n\nCOPY public.migrations (id) FROM stdin;\n1\n\\.\n\n--\n-- PostgreSQL database dump complete\n--\n\n\\unrestrict K1synthetic\n\n";

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
        'ProtectKernelTunables=yes', 'ProtectKernelModules=yes', 'ProtectKernelLogs=yes', 'ProtectControlGroups=yes',
        'ProtectProc=invisible', 'ProcSubset=pid', 'RestrictNamespaces=yes', 'MemoryDenyWriteExecute=yes',
        'SystemCallArchitectures=native', 'SystemCallFilter=@system-service',
        'SystemCallFilter=~@privileged @resources @mount @swap @reboot @raw-io @module @debug @obsolete @cpu-emulation @clock',
        // DR-2, DR-4, DR-12: io_uring sockets, memfd execution, keyrings.
        'SystemCallFilter=~memfd_create io_uring_setup io_uring_enter io_uring_register @keyring',
        'RestrictAddressFamilies=AF_UNIX', 'PrivateNetwork=yes', 'IPAddressDeny=any',
        'NoExecPaths=/ /tmp /var/tmp',
        'ExecPaths=/usr/lib/postgresql/16/bin /usr/lib/postgresql/16/lib /usr/lib/x86_64-linux-gnu /usr/lib64 /usr/bin/bash /usr/bin/dash',
        'MemoryMax=1536M', 'MemorySwapMax=0', 'TasksMax=64', 'OOMPolicy=stop', 'RuntimeMaxSec=1500', 'KillMode=control-group',
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
    'tasks above 64' => ['system dmsdrill-0123456789ab 1024 1536 65 1500 /x'],
    'timeout above 30 min' => ['system dmsdrill-0123456789ab 1024 1536 64 1801 /x'],
    'memory above 2 GiB' => ['system dmsdrill-0123456789ab 1024 4096 64 1500 /x'],
    'tmpfs as large as memory' => ['system dmsdrill-0123456789ab 1536 1536 64 1500 /x'],
    'bad unit name' => ['system dms-restore-drill-x 1024 1536 64 1500 /x'],
    'unknown mode' => ['root dmsdrill-0123456789ab 1024 1536 64 1500 /x'],
]);

it('draws a fresh run name of at most 31 characters, valid as a DynamicUser name (DR-11)', function () {
    $run = rdipLib('dms_sandbox_unit_name; dms_sandbox_unit_name');
    $names = explode("\n", trim($run['out']));

    expect($names)->toHaveCount(2)
        ->and($names[0])->toMatch('/^dmsdrill-[0-9a-f]{12}$/')
        ->and(strlen($names[0]))->toBeLessThanOrEqual(31)
        ->and($names[0])->not->toBe($names[1]);
});

it('keeps the inner script to builtins and pinned binaries, as the restore role, with the superuser locked and fd 3 closed', function () {
    $inner = (string) file_get_contents(base_path('scripts/restore-drill-sandbox-inner.sh'));
    $code = implode("\n", array_filter(explode("\n", $inner), fn (string $l): bool => ! str_starts_with(ltrim($l), '#')));

    expect(preg_match('/(^|[;|&(]\s*|\$\(\s*)(cat|sed|awk|grep|rm|mkdir|sleep|head|tail|cut|tr|date|id|ls|cp|mv|chmod|env|python3?|perl|sh)\s/m', $code))->toBe(0)
        // D4: the replay and the verification run as the NOSUPERUSER restore role.
        ->and($code)->toContain('-U "$RR" -d "$DB" -f - > /dev/null 2> "$R/replay.err" 3>&-')
        ->and($code)->toContain('CREATE ROLE :"r" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;')
        // DR-5: the bootstrap superuser is NOLOGIN before the first byte of the backup is read.
        ->and(strpos($code, 'ALTER ROLE :"r" NOLOGIN;'))->toBeLessThan(strpos($code, '-f - > /dev/null'))
        // DR-10: the server and every psql close the result channel.
        ->and($code)->toContain('> "$R/pg.log" 2>&1 3>&- &')
        ->and(preg_match_all('/"\$PGBIN\/psql"(?![^\n]*3>&-)/', $code))->toBe(0)
        // F-ISO-4: always an immediate shutdown; only the SQLSTATE of a failure leaves the unit.
        ->and($code)->toContain('kill -QUIT "$PGPID"')
        ->and($code)->toContain('-v VERBOSITY=sqlstate')
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

it('recognises a complete pg_dump 16 dump and refuses a truncated one (DR-7)', function (string $content, int $exit) {
    $file = rdipTemp($content);
    $run = rdipLib('dms_sandbox_dump_trailer_ok "$F"', ['F' => $file]);
    unlink($file);

    expect($run['exit'])->toBe($exit);
})->with([
    'complete' => [RDIP_DUMP, 0],
    'cut after the first table' => [substr(RDIP_DUMP, 0, strpos(RDIP_DUMP, 'CREATE TABLE public.migrations')), 1],
    'trailer without the unrestrict line' => [str_replace("\\unrestrict K1synthetic\n", '', RDIP_DUMP), 1],
    'unrestrict with another key' => [str_replace('\\unrestrict K1synthetic', '\\unrestrict K2other', RDIP_DUMP), 1],
    'no restrict header' => [str_replace("\\restrict K1synthetic\n", '', RDIP_DUMP), 1],
    'content appended after the trailer' => [RDIP_DUMP."CREATE ROLE x;\n", 1],
]);

it('counts the base tables and the COPY rows of the dump on the host (DR-7)', function () {
    $file = rdipTemp(RDIP_DUMP);
    $run = rdipLib('dms_sandbox_dump_counts "$F"', ['F' => $file]);
    unlink($file);

    expect(trim($run['out']))->toBe('2 3');
});

it('parses the result channel strictly: only well-formed lines, each key once, bounded', function () {
    $raw = "DRILL result=GO\nDRILL result=GO\nnoise\nDRILL bad key=1\nDRILL table_count=1\x1b[31m\nDRILL x=".str_repeat('a', 81)."\nDRILL ok_key=1\n";
    $file = rdipTemp($raw);
    $run = rdipLib('dms_sandbox_parse "$F"; echo "dup=$DMS_SANDBOX_DUPLICATES rej=$DMS_SANDBOX_REJECTED"; for k in "${!DMS_SANDBOX_RESULT[@]}"; do echo "$k=${DMS_SANDBOX_RESULT[$k]}"; done | sort', ['F' => $file]);
    unlink($file);

    expect($run['out'])->toContain('dup=1 rej=4')
        ->and($run['out'])->toContain("ok_key=1\nresult=GO")
        ->and($run['out'])->not->toContain('table_count');
});

it('decides GO only from host-checkable facts, and names the first failing one', function (array $result, string $args, string $decision) {
    $lines = collect($result)->map(fn (string $v, string $k): string => "DRILL {$k}={$v}")->implode("\n")."\n";
    $file = rdipTemp($lines);
    $run = rdipLib('dms_sandbox_parse "$F"; dms_sandbox_decide '.$args, ['F' => $file]);
    unlink($file);

    expect(trim($run['out']))->toBe($decision);
})->with(function () {
    $good = ['profile' => 'attested', 'bootstrap_superuser' => 'nologin', 'result' => 'GO', 'migrations_table' => '1', 'base_table_count' => '2', 'rows_total' => '3'];

    return [
        'all facts hold' => [$good, '0 0 100 100 2 3', 'GO'],
        'unit failed' => [$good, '20 0 100 100 2 3', 'FAIL:unit_exit_20'],
        'feeder failed' => [$good, '0 141 100 100 2 3', 'FAIL:feed_exit_141'],
        'short stream' => [$good, '0 0 99 100 2 3', 'FAIL:short_stream'],
        'profile not attested' => [array_merge($good, ['profile' => 'no']), '0 0 100 100 2 3', 'FAIL:profile_not_attested'],
        'superuser not locked' => [array_diff_key($good, ['bootstrap_superuser' => 1]), '0 0 100 100 2 3', 'FAIL:superuser_not_locked'],
        'sandbox said FAIL' => [array_merge($good, ['result' => 'FAIL', 'stage' => 'replay']), '0 0 100 100 2 3', 'FAIL:sandbox_replay'],
        'tables differ' => [array_merge($good, ['base_table_count' => '1']), '0 0 100 100 2 3', 'FAIL:table_count_mismatch'],
        'rows lost' => [array_merge($good, ['rows_total' => '2']), '0 0 100 100 2 3', 'FAIL:row_count_mismatch'],
        'no migrations table' => [array_merge($good, ['migrations_table' => '0']), '0 0 100 100 2 3', 'FAIL:no_migrations_table'],
    ];
});

it('fails the decision when the result channel carried a duplicate or a rejected line (forgery)', function (string $extra, string $decision) {
    $lines = "DRILL profile=attested\nDRILL bootstrap_superuser=nologin\nDRILL result=GO\nDRILL migrations_table=1\nDRILL base_table_count=2\nDRILL rows_total=3\n".$extra;
    $file = rdipTemp($lines);
    $run = rdipLib('dms_sandbox_parse "$F"; dms_sandbox_decide 0 0 1 1 2 3', ['F' => $file]);
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
