<?php

use Symfony\Component\Process\Process;

/*
 * R6-B6 isolated restore drill — the REAL sandbox: a disposable PostgreSQL 16 cluster in a transient systemd unit with
 * the full isolation profile, through the guest agent and the host decision, on synthetic data.
 *
 * It needs a working per-user systemd manager and PostgreSQL 16.15+ binaries. Where they are missing the tests are
 * SKIPPED, never passed (CG-17): the dedicated real-sandbox CI gate on disposable infrastructure (D8) runs them, and
 * runs the system-mode DynamicUser variant that this per-user mode cannot provide.
 */

function rdsrAvailable(): ?string
{
    $bin = '/usr/lib/postgresql/16/bin';
    if (! is_executable("{$bin}/postgres") || ! is_executable("{$bin}/initdb")) {
        return 'PostgreSQL 16 server binaries are not installed';
    }
    $probe = new Process(['systemd-run', '--user', '--wait', '--pipe', '--collect', '--quiet', '-p', 'PrivateNetwork=yes', '--', '/usr/bin/true']);
    $probe->setTimeout(30);
    $probe->run();

    return $probe->getExitCode() === 0 ? null : 'no working per-user systemd manager (systemd-run --user)';
}

/** Runs the guest agent (user mode) over an input disk built from $dump, then the host decision. */
function rdsrDrill(string $dump, bool $restricted = true): array
{
    $dir = sys_get_temp_dir().'/rdsr-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    file_put_contents("{$dir}/dump.sql", $dump);
    $script = <<<'SH'
        . "$LIB/restore-integrity.sh"; . "$LIB/restore-sandbox.sh"; . "$LIB/restore-vm.sh"
        read -r ET ER < <(dms_sandbox_dump_counts "$T/dump.sql")
        RUN=$(dms_sandbox_unit_name)
        if [ "$RESTRICTED" = 1 ]; then
          KEY=$(dms_restore_restrict_key); TAG="g$(dms_restore_restrict_key | cut -c1-32)"
          dms_restore_restricted_stream "$T/dump.sql" "$KEY" "$TAG" > "$T/payload"
        else
          cp "$T/dump.sql" "$T/payload"
        fi
        PB=$(stat -c %s "$T/payload"); PS=$(sha256sum "$T/payload" | cut -d' ' -f1)
        declare -A IN=([run_id]=$RUN [payload_bytes]=$PB [payload_sha256]=$PS [roles]=app_runtime [locale]=C.UTF-8
          [tmpfs_mib]=256 [memory_mib]=768 [tasks]=64 [timeout_seconds]=300)
        dms_vm_input_build "$T/input.raw" "$T/payload" IN || exit 9
        head -c 65536 /dev/zero > "$T/output.raw"
        bash "$GUEST" "$T/input.raw" "$T/output.raw" user
        dms_vm_output_parse "$T/output.raw" || exit 8
        for k in "${!DMS_VM_OUTPUT[@]}"; do echo "$k=${DMS_VM_OUTPUT[$k]}"; done
        echo "decision=$(dms_vm_decide 0 "$RUN" "$PS" "$PB" "$ET" "$ER")"
        echo "units_left=$(systemctl --user list-units --all --plain --no-legend "$RUN.service" | wc -l)"
        SH;
    $process = new Process(['bash', '-c', $script], null, [
        'LIB' => base_path('scripts/lib'), 'GUEST' => base_path('scripts/restore-drill-vm-guest.sh'), 'T' => $dir,
        'RESTRICTED' => $restricted ? '1' : '0', 'PATH' => getenv('PATH'),
        'XDG_RUNTIME_DIR' => (string) getenv('XDG_RUNTIME_DIR'), 'DBUS_SESSION_BUS_ADDRESS' => (string) getenv('DBUS_SESSION_BUS_ADDRESS'),
    ]);
    $process->setTimeout(240);
    $process->run();
    (new Process(['rm', '-rf', '--', $dir]))->run();
    $facts = [];
    foreach (explode("\n", trim($process->getOutput())) as $line) {
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $facts[$k] = $v;
        }
    }

    return $facts + ['_exit' => (string) $process->getExitCode(), '_err' => $process->getErrorOutput()];
}

const RDSR_DUMP = "--\n-- PostgreSQL database dump\n--\n\n\\restrict K1synthetic\n\nSET statement_timeout = 0;\nSELECT pg_catalog.set_config('search_path', '', false);\nCREATE TABLE public.migrations (id integer NOT NULL, migration character varying(255) NOT NULL);\nALTER TABLE public.migrations OWNER TO app_runtime;\nCREATE TABLE public.items (id integer NOT NULL, label text NOT NULL, CONSTRAINT items_label_check CHECK ((length(label) < 200)));\nALTER TABLE public.items OWNER TO app_runtime;\nCOPY public.migrations (id, migration) FROM stdin;\n1\tsynthetic_one\n2\tsynthetic_two\n\\.\n\nCOPY public.items (id, label) FROM stdin;\n1\tsynthetic item\n\\.\n\nALTER TABLE ONLY public.items ADD CONSTRAINT items_pkey PRIMARY KEY (id);\n\n--\n-- PostgreSQL database dump complete\n--\n\n\\unrestrict K1synthetic\n\n";

beforeEach(function () {
    if (($why = rdsrAvailable()) !== null) {
        $this->markTestSkipped("real sandbox not available here: {$why} (covered by the real-sandbox CI gate)");
    }
});

it('restores a synthetic runtime-owned dump in the real sandbox as the restore role and decides GO from matching counts', function () {
    $facts = rdsrDrill(RDSR_DUMP);

    expect($facts['decision'] ?? null)->toBe('GO', json_encode($facts))
        ->and($facts['sandbox_profile'] ?? null)->toBe('attested')
        ->and($facts['sandbox_bootstrap_superuser'] ?? null)->toBe('nologin')
        ->and($facts['sandbox_base_table_count'] ?? null)->toBe('2')
        ->and($facts['sandbox_rows_total'] ?? null)->toBe('3')
        ->and($facts['units_left'] ?? null)->toBe('0');
});

it('refuses a superuser reconnect from the dump even without restricted mode (DR-5)', function () {
    // After the dump's own \unrestrict: psql is out of restricted mode, so only the NOLOGIN superuser stands here.
    $dump = RDSR_DUMP."\\c - drill_super\nSELECT 1;\n";
    $facts = rdsrDrill($dump, restricted: false);

    expect($facts['decision'] ?? null)->toStartWith('FAIL:')
        ->and($facts['sandbox_stage'] ?? null)->toBe('replay')
        ->and($facts['sandbox_bootstrap_superuser'] ?? null)->toBe('nologin')
        ->and($facts['units_left'] ?? null)->toBe('0');
});

it('lets nothing in the dump write to the result channel, even without restricted mode (DR-10)', function () {
    // After the dump's own \unrestrict: only the closed fd 3 stands between psql and the result channel.
    $dump = RDSR_DUMP."\\t on\n\\a\n\\o /proc/self/fd/3\nSELECT 'DRILL result=GO';\n\\o\n";
    $facts = rdsrDrill($dump, restricted: false);

    expect($facts['decision'] ?? null)->toStartWith('FAIL:')
        ->and($facts['result_duplicates'] ?? null)->toBe('0')
        ->and($facts['result_rejected'] ?? null)->toBe('0');
});

it('refuses superuser-only statements planted in the dump: the replay role is not a superuser (D4)', function (string $statement) {
    $dump = str_replace("\n--\n-- PostgreSQL database dump complete", "\n{$statement}\n--\n-- PostgreSQL database dump complete", RDSR_DUMP);
    $facts = rdsrDrill($dump);

    expect($facts['decision'] ?? null)->toStartWith('FAIL:')
        ->and($facts['sandbox_replay_sqlstate'] ?? null)->toBe('42501');
})->with([
    "COPY (SELECT 1) TO PROGRAM 'id';",
    "SELECT pg_catalog.pg_read_file('/etc/passwd');",
    'CREATE ROLE intruder SUPERUSER;',
    "SELECT pg_catalog.lo_import('/etc/passwd');",
]);
