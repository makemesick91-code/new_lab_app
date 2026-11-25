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
      [roles]="app_runtime dms_owner" [runtime_role]=app_runtime [extension_allowlist]=none [locale]=C.UTF-8 [env_pins]=none [tmpfs_mib]=1024 [memory_mib]=1536 [tasks]=64 [timeout_seconds]=1500)
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

it('parses the VM output strictly and decides from the host\'s own expectations (VR-1: the guest only asserts)', function (string $lines, int $qemu, string $decision) {
    $dir = rdvmDir();
    file_put_contents("{$dir}/lines", $lines);
    $run = rdvmSh('dms_vm_output_write "$T/output.raw" "$T/lines"; dms_vm_output_parse "$T/output.raw"; dms_vm_decide '.$qemu.' dmsdrill-0123456789ab '.str_repeat('a', 64).' '.str_repeat('d', 64).' 2 1:2:0:2:0:0:1', ['T' => $dir]);
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['out'])->toBe($decision);
})->with(function () {
    $sha = str_repeat('a', 64);
    $dg = str_repeat('d', 64);
    $good = "run_id=dmsdrill-0123456789ab\npayload_sha256={$sha}\nunit_exit=0\nresult_duplicates=0\nresult_rejected=0\nsandbox_profile=attested\nsandbox_bootstrap_superuser=nologin\nsandbox_result=GO\nsandbox_migrations_table=1\nsandbox_user_code_reachable=0\nsandbox_user_code_executed=0\nsandbox_catalog_shape=1:2:0:2:0:0:1\nsandbox_extensions_outside_allowlist=0\nsandbox_base_table_count=2\nsandbox_table_digest={$dg}\nguest_stage=done\n";

    return [
        'all facts hold' => [$good, 0, 'GO'],
        'VM killed or crashed' => [$good, 124, 'FAIL:vm_exit_124'],
        'another run' => [str_replace('dmsdrill-0123456789ab', 'dmsdrill-ffffffffffff', $good), 0, 'FAIL:vm_run_id_mismatch'],
        'other payload' => [str_replace($sha, str_repeat('b', 64), $good), 0, 'FAIL:vm_payload_digest_mismatch'],
        'guest stopped early' => [str_replace('guest_stage=done', 'guest_stage=archive_digest_mismatch', $good), 0, 'FAIL:guest_archive_digest_mismatch'],
        'one table lost rows' => [str_replace($dg, str_repeat('e', 64), $good), 0, 'FAIL:per_table_rows_mismatch'],
        'a function vanished (P4P-1)' => [str_replace('sandbox_catalog_shape=1:2:0:2:0:0:1', 'sandbox_catalog_shape=0:2:0:2:0:0:1', $good), 0, 'FAIL:catalog_shape_mismatch'],
        'user code ran (P4P-1)' => [str_replace('sandbox_user_code_executed=0', 'sandbox_user_code_executed=2', $good), 0, 'FAIL:user_code_executed_in_restore'],
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
        ->and($args[array_search('-machine', $args, true) + 1])->toBe('q35,accel=kvm,vmport=off,hpet=off,smm=off,dump-guest-core=off')
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

it('pins the orchestrator to D6, refuses the production host (D1), and keeps host-verified apart from guest-asserted facts', function () {
    $code = (string) file_get_contents(base_path('scripts/restore-drill-vm.sh'));

    expect($code)->toContain('VCPU=1; MEMORY_MIB=2048; DISK_GIB=10; MINUTES=30; TASKS=64')
        ->and($code)->toContain('flock -n "$lock_fd"')
        // Phase 3: QEMU runs only under its own identity, through the launcher; the operator holds no image and no KVM.
        ->and($code)->toContain('sudo -n -- "${C[DMS_DRILL_SUPERVISOR_COMMAND]}" vm "$RUN_ID"')
        // (The orchestrator reads only the image's PROPERTIES, never an image path or digest of its own: F3.)
        ->and($code)->not->toContain('DMS_DRILL_IMAGE=')
        ->and($code)->not->toContain('DMS_DRILL_IMAGE_SHA256')
        ->and($code)->not->toContain('DMS_DRILL_QEMU_USER')
        ->and($code)->toContain('[ "$(hostname)" != "$PROD_HOSTNAME" ]')
        ->and($code)->toContain('[ ! -e "$PROD_APP_DIR" ]')
        // PR-7, VR-10: verified in this shell from one private copy; PR-9: revocation.
        ->and($code)->toContain('dd if="$MANIFEST" iflag=nofollow,nonblock bs=8192 count=1 status=none > "$WORK/manifest"')
        ->and($code)->toContain('dms_restore_manifest_verify "$WORK/manifest" "${C[DMS_DRILL_MANIFEST_ANCHOR]}" > "$WORK/manifest.verify"')
        ->and($code)->not->toContain('$(dms_restore_manifest_verify')
        ->and($code)->toContain('dms_restore_manifest_revocation_check "${C[DMS_DRILL_MANIFEST_REVOCATIONS]}"')
        // PR-6, PR-5, PR-8: the structured archive, inspected in a sandbox, validated, counted per table.
        ->and($code)->toContain('dms_archive_inspect "$STAGED" "$WORK/inspect"')
        ->and($code)->toContain('dms_archive_toc_validate "$WORK/inspect/toc.txt" "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ROLES]}" "$EXTENSIONS"')
        ->and($code)->toContain('EXPECT_DIGEST="$(dms_archive_digest "$WORK/inspect/counts.txt")"')
        // P5 twice: from the TOC before the VM boots, from the restored catalog after.
        ->and($code)->toContain('refuse "the archive makes the runtime role an owner after R6 (P5)"')
        ->and($code)->toContain('DECISION="FAIL:p5_runtime_owned_objects"')
        // VR-4: a separate signer identity through one sudo command; the operator holds no key and no store.
        ->and($code)->toContain('evidence_request "$1" | sudo -n -u "${C[DMS_DRILL_SIGNER_USER]}" -- "${C[DMS_DRILL_SIGNER_COMMAND]}" sign')
        ->and($code)->toContain('the signer must be a separate, unprivileged identity (VR-4)')
        ->and($code)->not->toContain('DMS_DRILL_EVIDENCE_KEY')
        // VR-1: the record separates what the host verified from what the guest asserted.
        ->and($code)->toContain('DMS_DRILL_EVIDENCE_HOST_VERIFIED=')
        ->and($code)->toContain('DMS_DRILL_EVIDENCE_GUEST_ASSERTED=')
        // VR-4, VR-11: never as root, with a fixed environment, and QEMU killed with its process group.
        ->and($code)->toContain('exec env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin')
        ->and($code)->toContain('never as root (VR-4)')
        ->and($code)->toContain('kill -TERM -- "-${VM_PID}"')
        // Phase 3: refusals and aborts are recorded through the signer once the configuration is trusted.
        ->and($code)->toContain('refuse() { echo "REFUSED: $*. Nothing ran."; ensure_started && { record "REFUSED:${STAGE}" || true; }; exit 2; }')
        ->and($code)->toContain('record "ABORTED:${STAGE}"')
        // R6C-3: a STARTED record opens every attempt before any input is read.
        ->and(strpos($code, 'ensure_started || { echo "REFUSED: the drill cannot be recorded'))->toBeLessThan(strpos($code, 'STAGE=manifest'))
        ->and(strpos($code, 'RECORDABLE=1'))->toBeGreaterThan(strpos($code, 'DMS_DRILL_SIGNER_COMMAND DMS_DRILL_SUPERVISOR_COMMAND; do'))
        ->and($code)->not->toMatch('/^\s*(\.|source)\s+"?\$CONF/m')
        ->and($code)->not->toContain('psql -');
});

/**
 * Installs the orchestrator, its libraries and the production identity file into a fresh directory, as a drill host
 * would (P4V-4: the orchestrator refuses to run from a directory another identity can write).
 */
function rdvmInstall(): string
{
    $root = sys_get_temp_dir().'/rdvm-inst-'.bin2hex(random_bytes(6));
    foreach (['', '/scripts', '/scripts/lib', '/deploy'] as $d) {
        @mkdir($root.$d, 0755, true);
        chmod($root.$d, 0755);
    }
    copy(base_path('scripts/restore-drill-vm.sh'), "{$root}/scripts/restore-drill-vm.sh");
    chmod("{$root}/scripts/restore-drill-vm.sh", 0755);
    foreach (glob(base_path('scripts/lib/*.sh')) as $lib) {
        copy($lib, "{$root}/scripts/lib/".basename($lib));
        chmod("{$root}/scripts/lib/".basename($lib), 0644);
    }
    copy(base_path('deploy/production-host.conf'), "{$root}/deploy/production-host.conf");
    chmod("{$root}/deploy/production-host.conf", 0644);

    return $root;
}

function rdvmOrchestrate(string $dir, array $args, array $env = []): array
{
    $root = rdvmInstall();
    $process = new Process(array_merge(['bash', "{$root}/scripts/restore-drill-vm.sh"], $args), null, array_merge([
        'PATH' => getenv('PATH'),
        'DMS_RESTORE_DRILL_CONF' => "{$dir}/drill.conf",
    ], $env));
    $process->setTimeout(120);
    $process->run();
    (new Process(['rm', '-rf', '--', $root]))->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => $process->getOutput().$process->getErrorOutput()];
}

it('refuses before anything runs when its inputs or its environment are not trusted', function (string $case, string $message) {
    $dir = rdvmDir();
    file_put_contents("{$dir}/backup.sql", "-- PostgreSQL database dump\n");
    file_put_contents("{$dir}/manifest", "x\n");
    $signer = $case === 'signer is the operator' ? (string) trim((string) shell_exec('id -un')) : 'nobody';
    $conf = "DMS_DRILL_MANIFEST_ANCHOR={$dir}/anchor\nDMS_DRILL_MANIFEST_REVOCATIONS={$dir}/revocations\nDMS_DRILL_EXTENSION_ALLOWLIST=none\nDMS_DRILL_SIGNER_USER={$signer}\nDMS_DRILL_SIGNER_COMMAND={$dir}/signer\nDMS_DRILL_SUPERVISOR_COMMAND={$dir}/no-supervisor\nDMS_DRILL_PG_BINDIR=/usr/lib/postgresql/16/bin\nDMS_DRILL_WORK_DIR={$dir}/work\nDMS_DRILL_LOCALE=C.UTF-8\nDMS_DRILL_IMAGE_PROPERTIES={$dir}/image.properties\n";
    foreach (['no-supervisor', 'anchor', 'revocations', 'signer', 'image.properties'] as $f) {
        file_put_contents("{$dir}/{$f}", "x\n");
        chmod("{$dir}/{$f}", 0600);
    }
    mkdir("{$dir}/work", 0700);
    $args = ['--expected-sha256', hash('sha256', "-- PostgreSQL database dump\n"), '--manifest', "{$dir}/manifest", "{$dir}/backup.sql"];
    match ($case) {
        // Phase 3: argument refusals come after the trusted configuration, so that they can be recorded.
        'no digest' => (function () use ($dir, $conf, &$args) {
            file_put_contents("{$dir}/drill.conf", $conf);
            chmod("{$dir}/drill.conf", 0600);
            $args = ['--manifest', "{$dir}/manifest", "{$dir}/backup.sql"];
        })(),
        'no manifest' => (function () use ($dir, $conf, &$args) {
            file_put_contents("{$dir}/drill.conf", $conf);
            chmod("{$dir}/drill.conf", 0600);
            $args = ['--expected-sha256', str_repeat('a', 64), "{$dir}/backup.sql"];
        })(),
        'missing configuration' => null,
        'configuration writable by others' => (function () use ($dir, $conf) {
            file_put_contents("{$dir}/drill.conf", $conf);
            chmod("{$dir}/drill.conf", 0666);
        })(),
        'configuration with shell code' => (function () use ($dir, $conf) {
            file_put_contents("{$dir}/drill.conf", $conf."DMS_DRILL_LOCALE=\$(touch {$dir}/pwned)\n");
            chmod("{$dir}/drill.conf", 0600);
        })(),
        'signer is the operator' => (function () use ($dir, $conf) {
            file_put_contents("{$dir}/drill.conf", $conf);
            chmod("{$dir}/drill.conf", 0600);
        })(),
        'no supervisor' => (function () use ($dir, $conf) {
            file_put_contents("{$dir}/drill.conf", $conf);
            chmod("{$dir}/drill.conf", 0600);
            unlink("{$dir}/no-supervisor");
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
    ['signer is the operator', 'the signer must be a separate, unprivileged identity (VR-4)'],
    ['no supervisor', 'DMS_DRILL_SUPERVISOR_COMMAND'],
]);

/** A complete, trusted drill configuration with a signed synthetic manifest; QEMU is a stub that never runs. */
function rdvmTrustedSetup(string $dir, string $fixture): array
{
    (new Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'owner', '-f', "{$dir}/owner"]))->mustRun();
    file_put_contents("{$dir}/anchor", 'owner@ci '.file_get_contents("{$dir}/owner.pub"));
    $manifest = "DMS_ROLE_MANIFEST_CONTRACT=2\nDMS_ROLE_MANIFEST_ID=test-manifest-0001\nDMS_ROLE_MANIFEST_ROLES=app_runtime dms_owner\nDMS_ROLE_MANIFEST_ENV_CONTRACT_SHA256=".str_repeat('c', 64)."\n"
        ."DMS_ROLE_MANIFEST_RUNTIME_ROLE=app_runtime\nDMS_ROLE_MANIFEST_R6_OWNER_ROLE=none\nDMS_ROLE_MANIFEST_ISSUED_BY=owner@ci\n"
        .'DMS_ROLE_MANIFEST_ISSUED_AT='.gmdate('Y-m-d\TH:i:s\Z', time() - 60)."\nDMS_ROLE_MANIFEST_NOT_AFTER=".gmdate('Y-m-d\TH:i:s\Z', time() + 86400)."\n";
    file_put_contents("{$dir}/manifest", $manifest);
    (new Process(['ssh-keygen', '-q', '-Y', 'sign', '-f', "{$dir}/owner", '-n', 'dms-restore-role-manifest', "{$dir}/manifest"]))->mustRun();
    foreach (['revocations' => '', 'qemu' => "#!/bin/sh\nexit 1\n"] as $f => $content) {
        file_put_contents("{$dir}/{$f}", $content);
        chmod("{$dir}/{$f}", 0700);
    }
    foreach (['anchor', 'manifest', 'manifest.sig'] as $f) {
        chmod("{$dir}/{$f}", 0600);
    }
    @mkdir("{$dir}/work", 0700);
    copy(base_path("tests/Fixtures/restore-drill/{$fixture}"), "{$dir}/backup.dump");
    chmod("{$dir}/backup.dump", 0600);
    file_put_contents("{$dir}/drill.conf", "DMS_DRILL_MANIFEST_ANCHOR={$dir}/anchor\nDMS_DRILL_MANIFEST_REVOCATIONS={$dir}/revocations\nDMS_DRILL_EXTENSION_ALLOWLIST=none\nDMS_DRILL_SIGNER_USER=nobody\nDMS_DRILL_SIGNER_COMMAND={$dir}/qemu\nDMS_DRILL_SUPERVISOR_COMMAND={$dir}/qemu\nDMS_DRILL_PG_BINDIR=/usr/lib/postgresql/16/bin\nDMS_DRILL_WORK_DIR={$dir}/work\nDMS_DRILL_LOCALE=C.UTF-8\nDMS_DRILL_IMAGE_PROPERTIES={$dir}/image.properties\n");
    chmod("{$dir}/drill.conf", 0600);
    file_put_contents("{$dir}/image.properties", "x\n");
    chmod("{$dir}/image.properties", 0600);

    return ['--expected-sha256', hash_file('sha256', "{$dir}/backup.dump"), '--manifest', "{$dir}/manifest", '--env-contract', "{$dir}/env-contract", "{$dir}/backup.dump"];
}

it('never inspects an archive it cannot record: without its signer, the drill stops before any input is opened (R6C-3)', function (string $fixture) {
    // The full path (integrity, sandboxed inspection, TOC, VM) runs end to end in the vm-boot CI gate on real accounts.
    $dir = rdvmDir();
    $args = rdvmTrustedSetup($dir, $fixture);
    $run = rdvmOrchestrate($dir, $args);
    $workLeft = array_values(array_diff(scandir("{$dir}/work"), ['.', '..', 'drill.lock']));
    (new Process(['rm', '-rf', '--', $dir]))->run();

    expect($run['exit'])->toBe(2, $run['out'])
        ->and($run['out'])->toContain('the signer did not open a record for this drill')
        ->and($run['out'])->toContain('REFUSED: the drill cannot be recorded, so it does not run. Nothing ran.')
        ->and($run['out'])->not->toContain('RESTORE_INTEGRITY')
        ->and($run['out'])->not->toContain('archive:')
        ->and($workLeft)->toBe([]);
})->with(['clean.dump', 'bomb.dump', 'dbowner.dump']);

it('runs only from an install nobody else can change, and accepts only its own per-user manager (P4V-4, P4V-8)', function () {
    $code = (string) file_get_contents(base_path('scripts/restore-drill-vm.sh'));

    expect($code)->toContain('TRUST_UID="$(stat -c \'%u\' -- "${SCRIPT_DIR}/$(basename "${BASH_SOURCE[0]}")")"')
        ->and($code)->toContain('for k in DMS_DRILL_MANIFEST_ANCHOR DMS_DRILL_MANIFEST_REVOCATIONS DMS_DRILL_SIGNER_COMMAND DMS_DRILL_SUPERVISOR_COMMAND DMS_DRILL_IMAGE_PROPERTIES; do')
        ->and($code)->toContain('xdg="${XDG_RUNTIME_DIR:-}"; [ "$xdg" = "/run/user/$(id -u)" ] || xdg=""')
        ->and($code)->toContain('[ "$bus" = "unix:path=${xdg}/bus" ] || bus=""')
        ->and($code)->toContain('dms_archive_inspect_stop "$INSPECT_UNIT"')
        // P5-4, Phase 3: the operator never states its install; the signer verifies it.
        ->and($code)->not->toContain('install=')
        ->and(strpos($code, 'dms_archive_toc_validate "$WORK/inspect/toc.txt"'))->toBeLessThan(strpos($code, 'STAGE=vm'));
});

it('refuses to run when its code, its directories or the production identity file can be changed by another identity (P4V-4, P5-6)', function (string $target, int $mode) {
    $root = rdvmInstall();
    chmod("{$root}/{$target}", $mode);
    $process = new Process(['bash', "{$root}/scripts/restore-drill-vm.sh", '--expected-sha256', str_repeat('a', 64), '--manifest', '/nonexistent', '/nonexistent'], null, ['PATH' => getenv('PATH')]);
    $process->run();
    foreach (['scripts/lib' => 0755, 'scripts' => 0755, 'deploy' => 0755, '' => 0755] as $d => $m) {
        chmod("{$root}/{$d}", $m);
    }
    (new Process(['rm', '-rf', '--', $root]))->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('writable by another identity. Nothing ran.');
})->with([
    'library directory' => ['scripts/lib', 0777],
    'the script itself (P5-6)' => ['scripts/restore-drill-vm.sh', 0777],
    'the production identity file (P5-6)' => ['deploy/production-host.conf', 0666],
    'the install root, even sticky (P5-6)' => ['', 01777],
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

it('runs QEMU only in the launcher, under its own identity, with D6 and the image fixed by root (Phase 3)', function () {
    $code = (string) file_get_contents(base_path('scripts/restore-drill-qemu-launcher.sh'));

    expect($code)->toStartWith("#!/bin/bash\n")
        ->and($code)->toContain('VCPU=1; MEMORY_MIB=2048; DISK_GIB=10; MINUTES=30')
        // The QEMU identity must not be able to change its own next run: its configuration is root's.
        ->and($code)->toContain('dms_trusted_file "QEMU configuration" "$CONF" 0')
        ->and($code)->toContain('[ "$IMAGE_SHA" = "${C[DMS_QEMU_IMAGE_SHA256]}" ] || die image_digest_mismatch')
        ->and($code)->toContain('[ -r /dev/kvm ] && [ -w /dev/kvm ] || die kvm_unavailable')
        // The only inputs: the bounded input disk on stdin and the run id; the QEMU command line is built here.
        ->and($code)->toContain('timeout 120 head -c $((INPUT_MAX_BYTES + 1)) > "$RT/input.raw"')
        ->and($code)->toContain('mapfile -t QARGS < <(dms_vm_qemu_args "$QWORK/root.qcow2" "$RT/input.raw" "$RT/output.raw" "$VCPU" "$MEMORY_MIB")')
        // Review round 6: only inside the supervisor's unit; its code is root's alone (R6A-9).
        ->and($code)->toContain("grep -qx '0::/system.slice/dmsqemu.service' /proc/self/cgroup || die not_in_the_supervised_unit")
        ->and($code)->toContain('[ "${st% *}" = 0 ] && [ $(( 8#${st#* } & 8#022 )) -eq 0 ]; }')
        ->and($code)->toContain("trap 'status \"aborted=signal\"; exit 143' TERM INT HUP")
        ->and($code)->toContain('rm -rf -- "$QWORK"');
});

it('refuses to launch a VM unless asked through sudo from another identity with a root-owned configuration (Phase 3)', function (array $env, string $message) {
    $root = sys_get_temp_dir().'/rdvm-qemu-'.bin2hex(random_bytes(6));
    foreach (['', '/qemu', '/qemu/lib'] as $d) {
        mkdir($root.$d, 0755, true);
        chmod($root.$d, 0755);
    }
    copy(base_path('scripts/restore-drill-qemu-launcher.sh'), "{$root}/qemu/restore-drill-qemu-launcher.sh");
    chmod("{$root}/qemu/restore-drill-qemu-launcher.sh", 0755);
    foreach (['trusted-path.sh', 'restore-vm.sh'] as $lib) {
        copy(base_path("scripts/lib/{$lib}"), "{$root}/qemu/lib/{$lib}");
        chmod("{$root}/qemu/lib/{$lib}", 0644);
    }
    file_put_contents("{$root}/qemu.conf", "DMS_QEMU_BINARY=/bin/true\n");
    chmod("{$root}/qemu.conf", 0600);
    $process = new Process(['bash', "{$root}/qemu/restore-drill-qemu-launcher.sh", 'run', 'dmsdrill-0123456789ab'], null, array_merge(['PATH' => getenv('PATH'), 'DMS_QEMU_CONF' => "{$root}/qemu.conf"], $env), "DMSDRILL-INPUT 1\n");
    $process->run();
    (new Process(['rm', '-rf', '--', $root]))->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain($message);
})->with([
    'called directly' => [[], 'QEMU_LAUNCH refused='],
    'through sudo, but outside the supervised unit' => [['SUDO_UID' => (string) (getmyuid() + 1), 'SUDO_USER' => 'drillop'], 'QEMU_LAUNCH refused='],
]);

it('keeps the real-isolation workflow audited: one validation branch, read-only, pinned, KVM-only, no secrets (Phase 3)', function () {
    $raw = (string) file_get_contents(base_path('deploy/restore-drill-vm/ci/restore-drill-real-sandbox.workflow-proposal.yml'));
    $yml = implode("\n", array_filter(explode("\n", $raw), fn (string $l): bool => ! str_starts_with(ltrim($l), '#')))."\n";
    $on = substr($yml, (int) strpos($yml, "\non:"), (int) strpos($yml, "\npermissions:") - (int) strpos($yml, "\non:"));

    expect(trim($on))->toBe("on:\n  push:\n    branches:\n      - validation/r6-b6-real-isolation")
        ->and($yml)->toContain("permissions:\n  contents: read\n")
        ->and($yml)->not->toContain('secrets.')
        ->and($yml)->not->toContain('pull_request')
        ->and($yml)->not->toContain('environment:')
        ->and(substr_count($yml, 'persist-credentials: false'))->toBe(2)
        ->and(preg_match_all('/uses: [^@\s]+@([0-9a-f]{40}) # v/', $yml))->toBe(substr_count($yml, 'uses: '))
        ->and($yml)->toContain('gpgv --keyring /usr/share/keyrings/ubuntu-cloudimage-keyring.gpg SHA256SUMS.gpg SHA256SUMS')
        ->and($yml)->toContain('sudo -n -u dmsdrill-qemu test -r /dev/kvm -a -w /dev/kvm || { echo "KVM is not usable by the QEMU account: the drill never falls back to emulation"; exit 1; }')
        ->and($yml)->not->toContain('apparmor')
        ->and($yml)->not->toContain('0666')
        ->and(substr_count($yml, 'timeout-minutes: '))->toBe(2)
        ->and($yml)->toContain('cancel-in-progress: true');
});

it('keeps root confined to the supervisor: two fixed modes, root-owned code, the runner as its only caller (review round 6)', function () {
    $code = (string) file_get_contents(base_path('scripts/restore-drill-supervisor.sh'));
    $vm = (string) file_get_contents(base_path('scripts/lib/restore-vm.sh'));
    $process = new Process(['bash', base_path('scripts/restore-drill-supervisor.sh'), 'vm', 'dmsdrill-0123456789ab'], null, ['PATH' => getenv('PATH')]);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('SUPERVISOR refused=not_root')
        ->and($code)->toStartWith("#!/bin/bash\n")
        ->and($code)->toContain('[ "$SUDO_USER" = "${C[DMS_SUPERVISOR_CALLER]}" ]')
        ->and($code)->toContain('|| refuse identities_not_separate')
        // R6C-3: a VM only for the run whose signed STARTED record is the newest record of the store.
        ->and($code)->toContain('grep -qx \'DMS_DRILL_EVIDENCE_DECISION=STARTED\' <<< "$rec" || refuse run_not_started')
        ->and($code)->toContain('systemd-run --system --wait --pipe --collect --quiet --unit="$UNIT" "$@" <&3 >&4 3<&- 4>&- &')
        ->and($code)->toContain('run_unit dmsqemu "${PROPS[@]}" -- /bin/bash "${C[DMS_SUPERVISOR_QEMU_LAUNCHER]}" run "$NAME"')
        // R6F-L1: every catchable signal stops the unit at once; never a second VM while one runs.
        ->and($code)->toContain("trap 'stop_unit; exit 143' TERM INT HUP PIPE USR1 USR2 ALRM QUIT XCPU XFSZ VTALRM PROF SYS")
        ->and($code)->toContain('unit_idle dmsqemu || refuse unit_busy')
        // R6F-L5: the STARTED record is the signer's regular file, read bounded; a run boots once.
        ->and($code)->toContain('|| refuse record_unsafe')
        ->and($code)->toContain('rec="$(head -c 65536 -- "$last")"')
        ->and($code)->toContain('( umask 022; set -C; : > "$B/$NAME" ) 2> /dev/null || refuse already_booted')
        // R6F-L4, R6F-L6, R6F-L10, R6F-L11: dedicated work file system, root-owned parent, one inspection, trusted code.
        ->and($code)->toContain('|| refuse work_dir_not_dedicated')
        // R6F-L21: every directory above the work directory is root's before root repairs and empties it.
        ->and($code)->toContain('while :; do _root_ok "$d" || refuse work_dir_parent_unsafe; [ "$d" = / ] && break; d="$(dirname -- "$d")"; done')
        // R6F-L22: a guest console only on a host marked as a disposable CI runner.
        ->and($code)->toContain('_root_ok /etc/dms-restore-drill/disposable-ci-runner 2> /dev/null || refuse console_dir_outside_ci')
        ->and($code)->toContain('flock -n "$ilock" || refuse inspect_busy')
        ->and($code)->toContain('|| refuse launcher_untrusted')
        // R6F-L2, R6F-L3, R6F-L4: no io_uring or namespaces, a private /dev/shm, no journal or D-Bus, bounded resources.
        ->and($vm)->toContain('io_uring_setup io_uring_enter io_uring_register')
        ->and($vm)->toContain('-p RestrictNamespaces=yes -p RemoveIPC=yes -p ProtectProc=invisible')
        ->and($vm)->toContain('-p "TemporaryFileSystem=/dev/shm:size=1M,mode=1777,noexec,nosuid,nodev"')
        ->and($vm)->toContain('-p "InaccessiblePaths=-/dev/mqueue -/run/systemd/journal -/run/dbus"')
        ->and($vm)->toContain('-p CPUQuota=150% -p IOWeight=10 -p LimitFSIZE=12G')
        // R6A-1, R6A-2, R6A-6, R6A-11: the unit's bounds are outside QEMU's reach.
        // R6F-L13: no socket at all: socket() itself is refused (an AF_UNIX socket reaches systemd-resolved, which
        // resolves names outside); systemd-run rejects RestrictAddressFamilies=none, so it is never used.
        ->and($vm)->toContain('-p PrivateNetwork=yes -p IPAddressDeny=any -p RestrictAddressFamilies=AF_UNIX')
        ->and($vm)->toContain('io_uring_register socket process_vm_readv process_vm_writev')
        ->and($vm)->not->toContain('RestrictAddressFamilies=none')
        // R6F-L18: /run read-only (nothing left in /run/lock).
        ->and($vm)->toContain('-p "ReadOnlyPaths=/run"')
        ->and($code)->toContain('flock -n "$vlock" || refuse vm_busy')
        ->and($vm)->toContain('-p KillMode=control-group')
        ->and($vm)->toContain('-p MemoryMax=3G -p MemorySwapMax=0 -p TasksMax=512 -p LimitCORE=0 -p CoredumpFilter=0')
        ->and($vm)->toContain('-p DevicePolicy=closed -p "DeviceAllow=/dev/kvm rw"')
        ->and($vm)->toContain('dump-guest-core=off');
});

it('provisions the drill configuration the signer verifies, and lets the unprivileged runner read the inbox by its group (CI run 9)', function () {
    $prov = (string) file_get_contents(base_path('deploy/restore-drill-vm/ci/provision-drill-host.sh'));
    $gate = (string) file_get_contents(base_path('deploy/restore-drill-vm/ci/vm-boot-gate.sh'));
    $signer = (string) file_get_contents(base_path('scripts/restore-drill-evidence-signer.sh'));
    $stage = (string) file_get_contents(base_path('scripts/lib/restore-integrity.sh'));
    $fn = substr($stage, (int) strpos($stage, 'dms_restore_stage_copy() {'));
    $fn = substr($fn, 0, (int) strpos($fn, "\n}\n"));

    // The install the signer verifies includes the drill configuration naming exactly its signer and its supervisor.
    expect($signer)->toContain('grep -qx "DMS_DRILL_SIGNER_COMMAND=$HERE/$(basename "${BASH_SOURCE[0]}")" /etc/dms-restore-drill/drill.conf')
        ->and($prov)->toContain('"DMS_DRILL_SIGNER_COMMAND=${L}/signer/restore-drill-evidence-signer.sh"')
        ->and($prov)->toContain('"DMS_DRILL_SUPERVISOR_COMMAND=${L}/supervisor/restore-drill-supervisor.sh"')
        ->and($prov)->toContain('| conf drill.conf')
        ->and($gate)->not->toContain('/etc/dms-restore-drill/drill.conf'."\n")
        ->and($gate)->not->toContain('install -o root -g root -m 0644 /dev/stdin /etc/dms-restore-drill/drill.conf')
        // Only root reads another account's file as that account; an unprivileged identity opens it with its own rights.
        ->and($fn)->toContain('if [ "$owner" = "$(id -un)" ] || [ "$(id -u)" != 0 ]; then')
        ->and(strpos($fn, 'dd if="$dump" iflag=nofollow,nonblock'))->toBeLessThan(strpos($fn, 'runuser -u "$owner"'));
});

it('gives the QEMU unit no writable path but its work directory, and the CI console only when configured (CI run 10)', function () {
    $lib = base_path('scripts/lib/restore-vm.sh');
    $props = function (string ...$args) use ($lib): array {
        $p = new Process(['bash', '-c', '. "$1"; shift; dms_vm_qemu_unit_properties "$@"', 'x', $lib, ...$args]);
        $p->mustRun();

        return array_values(array_filter(explode("\n", $p->getOutput()), fn (string $l): bool => str_starts_with($l, 'ReadWritePaths=')));
    };
    $gate = (string) file_get_contents(base_path('deploy/restore-drill-vm/ci/vm-boot-gate.sh'));
    $sup = (string) file_get_contents(base_path('scripts/restore-drill-supervisor.sh'));
    $prov = (string) file_get_contents(base_path('deploy/restore-drill-vm/ci/provision-drill-host.sh'));

    expect($props('dmsdrill-qemu', '/var/lib/dmsdrill-qemu/work', '30'))->toBe(['ReadWritePaths=/var/lib/dmsdrill-qemu/work'])
        ->and($props('dmsdrill-qemu', '/var/lib/dmsdrill-qemu/work', '30', 'none'))->toBe(['ReadWritePaths=/var/lib/dmsdrill-qemu/work'])
        ->and($props('dmsdrill-qemu', '/var/lib/dmsdrill-qemu/work', '30', '/var/lib/dmsdrill-qemu/console'))
        ->toBe(['ReadWritePaths=/var/lib/dmsdrill-qemu/work', 'ReadWritePaths=/var/lib/dmsdrill-qemu/console'])
        // The console directory must be the QEMU account's own, private; `none` on a drill host.
        ->and($sup)->toContain('[ "$(stat -c \'%u %a\' -- "$CON" 2> /dev/null)" = "${QUID} 700" ] || refuse console_dir_not_private')
        ->and($prov)->toContain('"DMS_SUPERVISOR_QEMU_CONSOLE_DIR=/var/lib/dmsdrill-qemu/console"')
        // A code-bearing archive passes only as the guest's own replay failure, never as an unreadable VM.
        ->and($gate)->toContain("has V6 '^Decision: FAIL:unit_exit_20 '")
        ->and($gate)->toContain("has V9 '^Decision: FAIL:unit_exit_20 '")
        ->and($gate)->not->toContain("has V6 '^Decision: FAIL:' ")
        ->and($gate)->not->toContain("has V9 '^Decision: FAIL:' ");
});

it('stops the supervised unit at once when the supervisor is signalled, not when the unit ends by itself (R6F-L1)', function () {
    $rt = (string) getenv('XDG_RUNTIME_DIR');
    if ($rt === '' || ! is_dir($rt.'/systemd') || (new Process(['systemctl', '--user', 'is-system-running']))->run() > 1) {
        test()->markTestSkipped('no per-user systemd manager on this host');
    }
    $code = (string) file_get_contents(base_path('scripts/restore-drill-supervisor.sh'));
    $from = (int) strpos($code, 'UNIT=""; SR=""');
    $to = (int) strpos($code, 'unit_idle()');
    // The supervisor's own stop_unit, traps and run_unit, against the caller's per-user manager.
    $harness = str_replace(['systemctl ', 'systemd-run --system '], ['systemctl --user ', 'systemd-run --user '], substr($code, $from, $to - $from));
    $unit = 'dmsl1probe-'.bin2hex(random_bytes(4));
    $script = $harness."\nrun_unit {$unit} -p Type=exec -- /bin/sleep 60\n";
    $p = new Process(['bash', '-c', $script], null, ['XDG_RUNTIME_DIR' => $rt, 'DBUS_SESSION_BUS_ADDRESS' => (string) getenv('DBUS_SESSION_BUS_ADDRESS'), 'PATH' => (string) getenv('PATH')]);
    $p->setInput('');
    $p->start();
    $active = fn (): string => trim((string) (new Process(['systemctl', '--user', 'show', '-P', 'ActiveState', "{$unit}.service"], null, ['XDG_RUNTIME_DIR' => $rt]))->mustRun()->getOutput());
    for ($i = 0; $i < 50 && $active() !== 'active'; $i++) {
        usleep(100000);
    }
    $wasActive = $active();
    $t = microtime(true);
    $p->signal(SIGTERM);
    $p->wait();
    $elapsed = microtime(true) - $t;
    $after = $active();
    (new Process(['systemctl', '--user', 'stop', "{$unit}.service"], null, ['XDG_RUNTIME_DIR' => $rt]))->run();

    expect($wasActive)->toBe('active')
        ->and($p->getExitCode())->toBe(143)
        ->and($elapsed)->toBeLessThan(15.0)
        ->and($after)->toBeIn(['inactive', 'failed', '']);
});

it('starts the QEMU unit\'s exact properties under systemd, with no socket, no io_uring and a read-only /run (R6F-L13, L18)', function () {
    $rt = (string) getenv('XDG_RUNTIME_DIR');
    if ($rt === '' || ! is_dir($rt.'/systemd') || (new Process(['systemctl', '--user', 'is-system-running']))->run() > 1) {
        test()->markTestSkipped('no per-user systemd manager on this host');
    }
    $p = new Process(['bash', '-c', '. "$1"; dms_vm_qemu_unit_properties x /nonexistent 1', 'x', base_path('scripts/lib/restore-vm.sh')]);
    $p->mustRun();
    $lines = explode("\n", trim($p->getOutput()));
    // Only what a per-user manager cannot apply is dropped (accounts, devices, IP accounting, system paths).
    $props = [];
    for ($i = 0; $i < count($lines); $i++) {
        if ($lines[$i] === '-p') {
            $v = $lines[++$i];
            if (! preg_match('/^(SupplementaryGroups|DevicePolicy|DeviceAllow|IPAddressDeny|ReadWritePaths|RuntimeDirectory|RuntimeDirectoryMode|CPUQuota|IOWeight)=/', $v)) {
                array_push($props, '-p', $v);
            }
        }
    }
    $probe = implode("\n", [
        'import ctypes, socket',
        'try:',
        '    socket.socket(socket.AF_UNIX, socket.SOCK_STREAM); print("SOCKET_OPENED")',
        'except OSError as e:',
        '    print("SOCKET_REFUSED errno=%d" % e.errno)',
        'l = ctypes.CDLL(None, use_errno=True); b = ctypes.create_string_buffer(120)',
        'print("IOURING_OK" if l.syscall(425, 4, b) >= 0 else "IOURING_DENIED errno=%d" % ctypes.get_errno())',
        'try:',
        '    open("/run/lock/dmsl13probe", "w"); print("RUNLOCK_WRITTEN")',
        'except OSError as e:',
        '    print("RUNLOCK_REFUSED errno=%d" % e.errno)',
    ]);
    $unit = 'dmsl13probe-'.bin2hex(random_bytes(4));
    $run = new Process(array_merge(['systemd-run', '--user', '--wait', '--pipe', '--collect', '--quiet', "--unit={$unit}", '-p', 'PrivateUsers=yes'], $props, ['--', '/usr/bin/python3', '-I', '-c', $probe]), null, ['XDG_RUNTIME_DIR' => $rt, 'DBUS_SESSION_BUS_ADDRESS' => (string) getenv('DBUS_SESSION_BUS_ADDRESS'), 'PATH' => (string) getenv('PATH')]);
    $run->setInput('');
    $run->run();
    @unlink('/run/lock/dmsl13probe');

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($run->getOutput())->toContain('SOCKET_REFUSED errno=1')
        ->and($run->getOutput())->toContain('IOURING_DENIED errno=1')
        ->and($run->getOutput())->toContain('RUNLOCK_REFUSED errno=30');
});
