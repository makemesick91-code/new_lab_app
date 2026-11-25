<?php

use Symfony\Component\Process\Process;

/*
 * R6-B6 isolated restore drill — the REAL sandbox: the synthetic fixture archives (tests/Fixtures/restore-drill)
 * restored by a disposable PostgreSQL 16 cluster in a transient systemd unit with the full isolation profile, through
 * the guest agent, and decided by the host against the per-table counts it took from the archive itself.
 *
 * It needs a working per-user systemd manager and PostgreSQL 16.15+ binaries. Where they are missing the tests are
 * SKIPPED, never passed (CG-17): the dedicated real-sandbox CI gate on disposable infrastructure (D8) runs them, and
 * runs the system-mode DynamicUser variant that this per-user mode cannot provide.
 */

function rdsrAvailable(): ?string
{
    $bin = '/usr/lib/postgresql/16/bin';
    foreach (['postgres', 'initdb', 'pg_restore', 'psql'] as $tool) {
        if (! is_executable("{$bin}/{$tool}")) {
            return "PostgreSQL 16 {$tool} is not installed";
        }
    }
    $probe = new Process(['systemd-run', '--user', '--wait', '--pipe', '--collect', '--quiet', '-p', 'PrivateNetwork=yes', '--', '/usr/bin/true']);
    $probe->setTimeout(30);
    $probe->run();

    return $probe->getExitCode() === 0 ? null : 'no working per-user systemd manager (systemd-run --user)';
}

/** Restores one fixture archive through the guest agent (user mode) and decides on the host. */
function rdsrDrill(string $fixture, string $extensions = 'none'): array
{
    $dir = sys_get_temp_dir().'/rdsr-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $script = <<<'SH'
        . "$LIB/restore-integrity.sh"; . "$LIB/restore-sandbox.sh"; . "$LIB/restore-vm.sh"; . "$LIB/restore-archive.sh"
        B=/usr/lib/postgresql/16/bin
        dms_archive_count_program > "$T/count.awk"
        "$B/pg_restore" --section=data --restrict-key=hostk -f - "$ARCHIVE" | awk -f "$T/count.awk" > "$T/counts" || exit 7
        ET=$(wc -l < "$T/counts"); ED=$(dms_archive_digest "$T/counts")
        RUN=$(dms_sandbox_unit_name)
        cp -- "$ARCHIVE" "$T/payload"
        PB=$(stat -c %s "$T/payload"); PS=$(sha256sum "$T/payload" | cut -d' ' -f1)
        read -r PK PIV < <(dms_vm_input_key_iv)
        declare -A IN=([run_id]=$RUN [payload_bytes]=$PB [payload_sha256]=$PS [payload_key]=$PK [payload_iv]=$PIV
          [roles]="app_runtime dms_owner" [runtime_role]=app_runtime [extension_allowlist]="$EXTS" [locale]=C.UTF-8
          [tmpfs_mib]=256 [memory_mib]=768 [tasks]=64 [timeout_seconds]=300)
        dms_vm_input_build "$T/input.raw" "$T/payload" IN || exit 9
        head -c 65536 /dev/zero > "$T/output.raw"
        bash "$GUEST" "$T/input.raw" "$T/output.raw" user
        dms_vm_output_parse "$T/output.raw" || exit 8
        for k in "${!DMS_VM_OUTPUT[@]}"; do echo "$k=${DMS_VM_OUTPUT[$k]}"; done
        echo "expected_digest=$ED"
        echo "decision=$(dms_vm_decision_safe "$(dms_vm_decide 0 "$RUN" "$PS" "$ED" "$ET")")"
        echo "units_left=$(systemctl --user list-units --all --plain --no-legend "$RUN.service" | wc -l)"

        SH;
    $process = new Process(['bash', '-c', $script], null, [
        'LIB' => base_path('scripts/lib'), 'GUEST' => base_path('scripts/restore-drill-vm-guest.sh'), 'T' => $dir,
        'ARCHIVE' => base_path("tests/Fixtures/restore-drill/{$fixture}.dump"), 'EXTS' => $extensions, 'PATH' => getenv('PATH'),
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

beforeEach(function () {
    if (($why = rdsrAvailable()) !== null) {
        $this->markTestSkipped("real sandbox not available here: {$why} (covered by the real-sandbox CI gate)");
    }
});

it('restores the synthetic archive section by section as the restore role and decides GO from the per-table digest (PR-5, PR-6)', function () {
    $facts = rdsrDrill('clean');

    expect($facts['decision'] ?? null)->toBe('GO', json_encode($facts))
        ->and($facts['sandbox_profile'] ?? null)->toBe('attested')
        ->and($facts['sandbox_bootstrap_superuser'] ?? null)->toBe('nologin')
        ->and($facts['sandbox_table_digest'] ?? null)->toBe($facts['expected_digest'] ?? 'x')
        ->and($facts['sandbox_base_table_count'] ?? null)->toBe('2')
        ->and($facts['units_left'] ?? null)->toBe('0');
});

it('refuses GO for an index-expression restore bomb: user code on a restore path (PR-2)', function () {
    $facts = rdsrDrill('bomb');

    expect($facts['sandbox_replay'] ?? null)->toBe('ok', json_encode($facts))
        ->and($facts['sandbox_user_code_reachable'] ?? null)->not->toBe('0')
        ->and($facts['decision'] ?? null)->toBe('FAIL:user_code_in_restore_path');
});

it('never runs row-security policy code during verification: verification fails instead (PR-5)', function () {
    // The host refuses this archive at its TOC (ROW SECURITY); this drives the guest directly to prove the inner layer.
    $facts = rdsrDrill('rls');

    expect($facts['decision'] ?? null)->toStartWith('FAIL:')
        ->and($facts['sandbox_stage'] ?? null)->toBe('verify');
});

it('keeps a backslash line inside a function body as text: no meta-command runs (D5, PR-6)', function () {
    $facts = rdsrDrill('metabody');

    expect($facts['decision'] ?? null)->toBe('GO', json_encode($facts))
        ->and($facts['sandbox_replay'] ?? null)->toBe('ok');
});

it('enforces the extension allowlist after the restore too (PR-8)', function () {
    $allowed = rdsrDrill('ext', 'pgcrypto');
    $refused = rdsrDrill('ext', 'none');

    expect($allowed['decision'] ?? null)->toBe('GO', json_encode($allowed))
        ->and($refused['sandbox_extensions_outside_allowlist'] ?? null)->toBe('1')
        ->and($refused['decision'] ?? null)->toBe('FAIL:extension_outside_allowlist');
});

it('counts runtime-owned objects from the restored catalog, for P5 after R6 (PR-3)', function () {
    $facts = rdsrDrill('r6owner');

    expect((int) ($facts['sandbox_runtime_owned_objects'] ?? 0))->toBeGreaterThan(0, json_encode($facts));
});
