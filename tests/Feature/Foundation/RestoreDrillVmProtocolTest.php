<?php

use Symfony\Component\Process\Process;

/*
 * R6-B6 isolated restore drill — the disposable-VM transport and the host orchestrator (owner directives D1, D3, D6).
 *
 * Without a hypervisor: the input/output disk formats, the guest's refusals before it starts any unit, the host's
 * decision over the VM's output, the QEMU command line, and the orchestrator's fail-closed refusals. The VM boot
 * itself runs only in the dedicated real-sandbox CI gate on disposable infrastructure (D8).
 */

function rdvmSh(string $script, array $env = []): array
{
    $process = new Process(['bash', '-c', '. "$LIB/restore-integrity.sh"; . "$LIB/restore-sandbox.sh"; . "$LIB/restore-vm.sh"; '.$script], null, array_merge([
        'LIB' => base_path('scripts/lib'),
        'PATH' => getenv('PATH'),
    ], $env));
    $process->setTimeout(60);
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => trim($process->getOutput()), 'err' => $process->getErrorOutput()];
}

function rdvmDir(): string
{
    $dir = sys_get_temp_dir().'/rdvm-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    return $dir;
}

const RDVM_BUILD = <<<'SH'
    printf 'SELECT 1;\n' > "$T/payload"
    declare -A IN=([run_id]=dmsdrill-0123456789ab [payload_bytes]=$(stat -c %s "$T/payload") [payload_sha256]=$(sha256sum "$T/payload" | cut -d' ' -f1)
      [payload_key]=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef [payload_iv]=00112233445566778899aabbccddeeff
      [roles]="app_runtime dms_owner" [runtime_role]=app_runtime [locale]=C.UTF-8 [tmpfs_mib]=1024 [memory_mib]=1536 [tasks]=64 [timeout_seconds]=1500)
    dms_vm_input_build "$T/input.raw" "$T/payload" IN || exit 9

    SH;

it('builds an input disk without privileges and parses it back exactly (D1)', function () {
    $dir = rdvmDir();
    $run = rdvmSh(RDVM_BUILD.'dms_vm_input_parse "$T/input.raw" && for k in $DMS_VM_INPUT_KEYS; do echo "$k=${DMS_VM_INPUT[$k]}"; done; dms_vm_input_payload "$T/input.raw" "${DMS_VM_INPUT[payload_bytes]}" "${DMS_VM_INPUT[payload_key]}" "${DMS_VM_INPUT[payload_iv]}"; echo; stat -c %s "$T/input.raw"; grep -c "SELECT 1" "$T/input.raw" || true', ['T' => $dir]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['exit'])->toBe(0, $run['err'])
        ->and($run['out'])->toContain("run_id=dmsdrill-0123456789ab\npayload_bytes=10\n")
        ->and($run['out'])->toContain('roles=app_runtime dms_owner')
        // The payload round-trips through AES-256-CTR, and the plaintext never appears on the disk (VR-6).
        ->and($run['out'])->toContain("SELECT 1;\n\n4608\n0");
});

it('refuses an input header that is malformed, incomplete or carries an unknown or unsafe value', function (string $mutation) {
    $dir = rdvmDir();
    $run = rdvmSh(RDVM_BUILD.$mutation.'; dms_vm_input_parse "$T/input.raw"', ['T' => $dir]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['exit'])->toBe(1);
})->with([
    'wrong magic' => ['printf "XMSDRILL" | dd of="$T/input.raw" conv=notrunc status=none'],
    'no END' => ['sed -i "s/^END$/XXX/" "$T/input.raw"'],
    'injected role' => ['sed -i "s/^roles=app_runtime dms_owner$/roles=app_runtime x;y/" "$T/input.raw"'],
    'unknown key' => ['sed -i "s/^locale=/lokale=/" "$T/input.raw"'],
    'duplicate key' => ['sed -i "s/^locale=C.UTF-8$/run_id=dmsdrill-0123456789ab/" "$T/input.raw"'],
]);

it('makes the guest refuse a payload whose digest differs, before it starts any unit', function () {
    $dir = rdvmDir();
    $run = rdvmSh(RDVM_BUILD.'printf X | dd of="$T/input.raw" bs=1 seek=4097 conv=notrunc status=none; head -c 65536 /dev/zero > "$T/output.raw"; bash "$G" "$T/input.raw" "$T/output.raw" user; echo "guest=$?"; dms_vm_output_parse "$T/output.raw" && echo "stage=${DMS_VM_OUTPUT[guest_stage]} run=${DMS_VM_OUTPUT[run_id]}"', [
        'T' => $dir, 'G' => base_path('scripts/restore-drill-vm-guest.sh'),
    ]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['out'])->toContain('guest=1')
        ->and($run['out'])->toContain('stage=payload_digest_mismatch run=dmsdrill-0123456789ab');
});

it('parses the VM output strictly and decides only from facts the host can check', function (string $lines, int $qemu, string $decision) {
    $dir = rdvmDir();
    file_put_contents("{$dir}/lines", $lines);
    $run = rdvmSh('dms_vm_output_write "$T/output.raw" "$T/lines"; dms_vm_output_parse "$T/output.raw"; dms_vm_decide '.$qemu.' dmsdrill-0123456789ab '.str_repeat('a', 64).' 10 2 3', ['T' => $dir]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['out'])->toBe($decision);
})->with(function () {
    $sha = str_repeat('a', 64);
    $good = "run_id=dmsdrill-0123456789ab\npayload_sha256={$sha}\nunit_exit=0\nfeed_exit=0\nresult_duplicates=0\nresult_rejected=0\nsandbox_profile=attested\nsandbox_bootstrap_superuser=nologin\nsandbox_result=GO\nsandbox_migrations_table=1\nsandbox_user_code_reachable=0\nsandbox_base_table_count=2\nsandbox_rows_total=3\nguest_stage=done\n";

    return [
        'all facts hold' => [$good, 0, 'GO'],
        'VM killed or crashed' => [$good, 124, 'FAIL:vm_exit_124'],
        'another run' => [str_replace('dmsdrill-0123456789ab', 'dmsdrill-ffffffffffff', $good), 0, 'FAIL:vm_run_id_mismatch'],
        'other payload' => [str_replace($sha, str_repeat('b', 64), $good), 0, 'FAIL:vm_payload_digest_mismatch'],
        'guest stopped early' => [str_replace('guest_stage=done', 'guest_stage=profile_build_failed', $good), 0, 'FAIL:guest_profile_build_failed'],
        'feeder cut short' => [str_replace('feed_exit=0', 'feed_exit=141', $good), 0, 'FAIL:feed_exit_141'],
        'rows lost' => [str_replace('sandbox_rows_total=3', 'sandbox_rows_total=1', $good), 0, 'FAIL:row_count_mismatch'],
        'forged duplicate' => [$good."sandbox_result=GO\n", 0, 'FAIL:vm_output_malformed'],
        'injected noise' => [$good."\x1b]0;owned\x07\n", 0, 'FAIL:vm_output_malformed'],
    ];
});

it('treats an output disk without the frame as no result at all', function () {
    $dir = rdvmDir();
    file_put_contents("{$dir}/output.raw", "garbage\nresult=GO\n");
    $run = rdvmSh('dms_vm_output_parse "$T/output.raw"; echo "frame=$?"', ['T' => $dir]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['out'])->toBe('frame=1');
});

it('boots the VM with KVM, no network device, read-only input, QEMU\'s own sandbox, the D6 size and a narrow CPU (VR-3a, VR-7)', function () {
    $run = rdvmSh('dms_vm_qemu_args /w/root.qcow2 /w/input.raw /w/output.raw 1 2048');
    $args = explode("\n", $run['out']);

    expect($args[array_search('-nic', $args, true) + 1])->toBe('none')
        ->and($args[array_search('-smp', $args, true) + 1])->toBe('1')
        ->and($args[array_search('-m', $args, true) + 1])->toBe('2048')
        ->and($args[array_search('-machine', $args, true) + 1])->toBe('q35,accel=kvm,vmport=off,hpet=off,smm=off')
        ->and($args[array_search('-cpu', $args, true) + 1])->toBe('host,-vmx,-svm,pmu=off')
        ->and($args)->toContain('-nodefaults')
        ->and($args)->toContain('-sandbox')
        ->and($args)->toContain('on,obsolete=deny,elevateprivileges=deny,spawn=deny,resourcecontrol=deny')
        ->and($args)->toContain('if=none,id=dmsin,file=/w/input.raw,format=raw,readonly=on')
        ->and($args)->toContain('virtio-blk-pci,drive=dmsin,serial=dmsdrill-input')
        ->and($args)->toContain('virtio-blk-pci,drive=dmsout,serial=dmsdrill-output')
        // `-drive …,serial=` was removed in QEMU 3.0: the serial is a device property only.
        ->and(collect($args)->filter(fn (string $a): bool => str_contains($a, 'file=') && str_contains($a, 'serial='))->all())->toBe([])
        ->and($run['out'])->not->toContain('netdev')
        ->and($run['out'])->not->toContain('virtfs')
        ->and($run['out'])->not->toContain('-chardev');
});

it('reads qemu-img info with a JSON parser, as QEMU 8 prints it with a children array first (VR-3b)', function (string $json, int $exit, string $out) {
    $dir = rdvmDir();
    file_put_contents("{$dir}/info.json", $json);
    $run = rdvmSh('dms_vm_image_info_ok "$T/info.json" 10737418240', ['T' => $dir]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['exit'])->toBe($exit)
        ->and($run['out'].$run['err'])->toContain($out);
})->with(function () {
    $child = '"children": [{"name": "file", "info": {"format": "file", "virtual-size": 1234, "filename": "x"}}]';

    return [
        'a 10 GiB qcow2' => ['{'.$child.', "virtual-size": 10737418240, "filename": "x", "format": "qcow2", "format-specific": {"type": "qcow2", "data": {}}}', 0, '10737418240'],
        'larger than 10 GiB' => ['{'.$child.', "virtual-size": 10737418241, "format": "qcow2"}', 1, 'exceeds the limit'],
        'raw image' => ['{'.$child.', "virtual-size": 100, "format": "raw"}', 1, 'not qcow2'],
        'backing file' => ['{'.$child.', "virtual-size": 100, "format": "qcow2", "backing-filename": "/etc/x"}', 1, 'backing file'],
        'external data file' => ['{'.$child.', "virtual-size": 100, "format": "qcow2", "format-specific": {"type": "qcow2", "data": {"data-file": "/dev/sda"}}}', 1, 'external data file'],
    ];
});

it('kills QEMU itself, not only its timeout wrapper, when the drill is interrupted (VR-2)', function () {
    $run = rdvmSh('timeout --kill-after=5 600 sleep 4243 & P=$!; read -rt 0.5 < <(sleep 1) || true; pgrep -f "^sleep 4243$" > /dev/null && echo before=running; dms_vm_kill_group "$P"; wait "$P" 2> /dev/null; read -rt 0.5 < <(sleep 1) || true; pgrep -f "^sleep 4243$" > /dev/null && echo after=running || echo after=gone');

    expect($run['out'])->toContain('before=running')
        ->and($run['out'])->toContain('after=gone');
});

it('keeps every FAIL reason inside the evidence schema, so a guest-chosen reason cannot block the record (VR-8)', function (string $in, string $out) {
    $run = rdvmSh('dms_vm_decision_safe "$D"', ['D' => $in]);

    expect($run['out'])->toBe($out);
})->with([
    ['GO', 'GO'],
    ['FAIL:vm_exit_124', 'FAIL:vm_exit_124'],
    ['FAIL:guest_Payload.Digest-Mismatch', 'FAIL:guest_invalid_value'],
    ['FAIL:sandbox_'.str_repeat('x', 80), 'FAIL:guest_invalid_value'],
    ['go', 'FAIL:guest_invalid_value'],
]);

it('pins the orchestrator to the D6 limits, one drill at a time, and refuses on the production host (D1)', function () {
    $code = (string) file_get_contents(base_path('scripts/restore-drill-vm.sh'));

    expect($code)->toContain('VCPU=1; MEMORY_MIB=2048; DISK_GIB=10; MINUTES=30; TASKS=64')
        ->and($code)->toContain('flock -n "$lock_fd"')
        ->and($code)->toContain('timeout --kill-after=10 "$((MINUTES * 60))"')
        ->and($code)->toContain('[ "$(hostname)" != "$PROD_HOSTNAME" ]')
        ->and($code)->toContain('[ ! -e "$PROD_APP_DIR" ]')
        // VR-10: the manifest is copied once into the private run directory and verified, parsed and hashed there.
        ->and($code)->toContain('cat -- "$MANIFEST" > "$WORK/manifest"')
        // PR-7: verified in the orchestrator's own shell, so the parsed manifest survives; P5 from the catalog (PR-3).
        ->and($code)->toContain('dms_restore_manifest_verify "$WORK/manifest" "${C[DMS_DRILL_MANIFEST_ANCHOR]}" > "$WORK/manifest.verify"')
        ->and($code)->not->toContain('$(dms_restore_manifest_verify')
        ->and($code)->toContain('DECISION="FAIL:p5_runtime_owned_objects"')
        // VR-4, VR-11: never as root, and with a fixed environment.
        ->and($code)->toContain('exec env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin')
        ->and($code)->toContain('never as root (VR-4)')
        ->and($code)->toContain('dms_vm_kill_group "$QEMU_PID"')
        ->and($code)->toContain('dms_evidence_write')
        // The configuration is parsed, never sourced; nothing connects to production PostgreSQL (D3).
        ->and($code)->not->toMatch('/^\s*(\.|source)\s+"?\$CONF/m')
        ->and($code)->not->toContain('psql')
        ->and(strpos($code, 'dms_restore_verify_copy "$STAGED"'))->toBeLessThan(strpos($code, 'dms_vm_input_build'));
});

function rdvmOrchestrate(string $dir, array $args, array $env = []): array
{
    $process = new Process(array_merge(['bash', base_path('scripts/restore-drill-vm.sh')], $args), null, array_merge([
        'PATH' => getenv('PATH'),
        'DMS_RESTORE_DRILL_CONF' => "{$dir}/drill.conf",
    ], $env));
    $process->setTimeout(60);
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => $process->getOutput().$process->getErrorOutput()];
}

it('refuses before anything runs when its inputs or its environment are not trusted', function (string $case, string $message) {
    $dir = rdvmDir();
    file_put_contents("{$dir}/backup.sql", "-- PostgreSQL database dump\n");
    file_put_contents("{$dir}/manifest", "x\n");
    $conf = "DMS_DRILL_IMAGE={$dir}/image.qcow2\nDMS_DRILL_IMAGE_SHA256=".str_repeat('a', 64)."\nDMS_DRILL_MANIFEST_ANCHOR={$dir}/anchor\nDMS_DRILL_EVIDENCE_KEY={$dir}/key\nDMS_DRILL_EVIDENCE_SIGNER=drill-host@dms\nDMS_DRILL_EVIDENCE_STORE={$dir}/store\nDMS_DRILL_WORK_DIR={$dir}/work\nDMS_DRILL_LOCALE=C.UTF-8\nDMS_DRILL_QEMU={$dir}/no-qemu\nDMS_DRILL_QEMU_IMG={$dir}/no-qemu-img\n";
    foreach (['image.qcow2', 'anchor', 'key'] as $f) {
        file_put_contents("{$dir}/{$f}", "x\n");
        chmod("{$dir}/{$f}", 0600);
    }
    mkdir("{$dir}/store", 0700);
    mkdir("{$dir}/work", 0700);
    $args = ['--expected-sha256', hash('sha256', "-- PostgreSQL database dump\n"), '--manifest', "{$dir}/manifest", "{$dir}/backup.sql"];
    match ($case) {
        'no digest' => $args = ['--manifest', "{$dir}/manifest", "{$dir}/backup.sql"],
        'no manifest' => $args = ['--expected-sha256', str_repeat('a', 64), "{$dir}/backup.sql"],
        'missing configuration' => null,
        'configuration writable by others' => (function () use ($dir, $conf) {
            file_put_contents("{$dir}/drill.conf", $conf);
            chmod("{$dir}/drill.conf", 0666);
        })(),
        'configuration with shell code' => (function () use ($dir, $conf) {
            file_put_contents("{$dir}/drill.conf", $conf."DMS_DRILL_LOCALE=\$(touch {$dir}/pwned)\n");
            chmod("{$dir}/drill.conf", 0600);
        })(),
        'no hypervisor' => (function () use ($dir, $conf) {
            file_put_contents("{$dir}/drill.conf", $conf);
            chmod("{$dir}/drill.conf", 0600);
        })(),
    };
    $run = rdvmOrchestrate($dir, $args);
    $pwned = file_exists("{$dir}/pwned");
    $workLeft = array_values(array_diff(scandir("{$dir}/work"), ['.', '..', 'drill.lock']));
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['exit'])->toBe(2, $run['out'])
        ->and($run['out'])->toContain('REFUSED:')
        ->and($run['out'])->toContain($message)
        ->and($run['out'])->toContain('Nothing ran.')
        ->and($pwned)->toBeFalse()
        ->and($workLeft)->toBe([]);
})->with([
    ['no digest', '--expected-sha256 must be the 64-hex digest'],
    ['no manifest', '--manifest <signed role manifest> is required (D3)'],
    ['missing configuration', 'drill configuration'],
    ['configuration writable by others', 'writable by group or others'],
    ['configuration with shell code', 'unexpected line in'],
    ['no hypervisor', 'DMS_DRILL_QEMU'],
]);

it('retires the in-place replay of the production-side drill script (finding A-1)', function () {
    $code = (string) file_get_contents(base_path('scripts/rollout-restore-drill.sh'));
    $body = implode("\n", array_filter(explode("\n", $code), fn (string $l): bool => ! str_starts_with(ltrim($l), '#')));

    expect($body)->toContain('exit 3')
        ->and($body)->not->toContain('-f -')
        ->and($body)->not->toContain('"$CREATEDB_BIN" -O')
        ->and($body)->not->toContain('"$DROPDB_BIN" --')
        ->and($body)->toContain('scripts/restore-drill-vm.sh --expected-sha256')
        ->and(trim((string) collect(explode("\n", trim($body)))->last()))->toBe('exit 3');
});
